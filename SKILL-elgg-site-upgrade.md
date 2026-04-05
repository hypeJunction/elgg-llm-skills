---
name: elgg-site-upgrade
description: >
  Use when upgrading an entire Elgg installation (core + plugins) between major
  versions. Two workflows: PREPARE (development — branches, tests, Docker) and
  EXECUTE (production — strict checklist with backup and rollback plan).
category: process
triggers:
  - elgg upgrade site
  - elgg installation upgrade
  - upgrade elgg site
  - migrate elgg installation
user-invocable: true
---

# elgg-site-upgrade

> **Purpose:** Upgrade an entire Elgg installation from one major version to the next.
> **Two workflows:** PREPARE (dev) and EXECUTE (production)
> **Usage:** `/elgg-site-upgrade <project-path> [--from=2.x] [--to=6.x] [--mode=prepare|execute]`

## Iron Laws

1. **ONE MAJOR VERSION AT A TIME** — Upgrade 2.x→3.x, then 3.x→4.x, etc.
2. **LATEST MINOR FIRST** — Must be on latest minor before jumping major (e.g., 2.3.x before 3.x).
3. **PREPARE COMPLETELY BEFORE EXECUTING** — Never run the production checklist until the preparation workflow has produced a fully tested migration branch.

---

# PART A: PREPARE (Development Workflow)

This is iterative, safe to break. Done in a development environment with Docker
and a fresh database. The goal is to produce a tested migration branch for each
version step that can be applied to production with confidence.

## Prep Phase 0: ASSESS

### Step 0.1: Detect Current Elgg Version

```bash
cat <project>/composer.json | grep "elgg/elgg"
# Or from manifest: grep -r "elgg_release" <project>/mod/*/manifest.xml | head -5
```

### Step 0.2: Map the Upgrade Path

| From | Target | Steps |
|------|--------|-------|
| 2.x | 6.x | 2.3→3.3→4.3→5.1→6.1 |
| 3.x | 6.x | 3.3→4.3→5.1→6.1 |
| 4.x | 6.x | 4.3→5.1→6.1 |

**Rule:** Must be on latest minor of current major before jumping. From 2.3+ you can technically jump to any future version, but upgrading one major at a time is safer and lets you test incrementally.

### Step 0.3: Inventory All Plugins

```bash
for d in <project>/mod/*/; do
  name=$(basename "$d")
  if [ -f "$d/manifest.xml" ]; then
    ver=$(grep -A1 'elgg_release' "$d/manifest.xml" | grep version | grep -oP '>[^<]+<' | tr -d '><')
    echo "$name: Elgg $ver"
  elif [ -f "$d/elgg-plugin.php" ]; then
    echo "$name: elgg-plugin.php (3.x+)"
  fi
done | sort
```

### Step 0.4: Categorize Plugins

| Category | Identify by | Strategy |
|----------|------------|----------|
| **Core** | Ships with Elgg | Auto-upgraded with core |
| **Composer-managed with upstream** | In `composer.json`, has GitHub repo | Find upgraded version or migrate |
| **Custom/private** | Only in `mod/`, no upstream | Migrate in-place in project repo |

### Step 0.5: Find Upgraded Plugin Versions

For each plugin, check if a compatible version already exists:

**Strategy 1: Check upstream branches**
```bash
gh api repos/<owner>/<plugin>/branches -q '.[].name' | grep -iE '3\.x|4\.x'
```

**Strategy 2: Check Elgg3-* repos (hypeJunction pattern)**
```bash
gh search repos --owner <org> "Elgg3-<plugin>" --json name -q '.[].name'
```

**Strategy 3: Check Composer**
```bash
composer show <vendor>/<plugin> --all 2>/dev/null | grep versions
```

**Strategy 4: Check manifest on branches**
```bash
gh api "repos/<owner>/<plugin>/contents/manifest.xml?ref=3.x" -q '.content' | base64 -d | grep -A1 'elgg_release'
```

**If no upgraded version exists:** Use elgg-migrate to create one.

---

## Prep Phase 1: SETUP WORKSPACE

### Step 1.1: Clone Plugin Repos

```bash
mkdir -p ~/plugins-workspace
# Clone each plugin with an upstream repo
git clone https://github.com/<owner>/<plugin>.git ~/plugins-workspace/<plugin>
```

### Step 1.2: Symlink Into Project

```bash
cd <project>/mod
rm -rf <plugin>
ln -s ~/plugins-workspace/<plugin> <plugin>
```

Changes in the workspace are instantly reflected in the project.

### Step 1.3: Create Branches

```bash
# Project
git -C <project> checkout -b migrate/elgg-{N}.x

# Each plugin
git -C ~/plugins-workspace/<plugin> checkout -b migrate/elgg-{N}.x
```

### Step 1.4: Record Plugin Activation Order

Get the current activation order from the running site and save it:

```bash
# Save to mod/.plugin-order.txt (one plugin ID per line, in priority order)
```

---

## Prep Phase 2: TEST BASELINE (Current Version)

### Step 2.1: Boot Docker for Current Version

```bash
docker compose -f docker/elgg{CURRENT}/docker-compose.yml \
  -f docker/elgg{CURRENT}/docker-compose.override.yml up -d
```

### Step 2.2: Write Tests (use `/elgg-test-writer`)

For each plugin, write:
- Entity CRUD tests
- Registration tests (actions, routes, hooks, widgets)
- Permission tests
- View rendering tests

### Step 2.3: Run Tests — Must Be Green

```bash
docker compose exec elgg php vendor/bin/phpunit \
  --configuration mod/<plugin>/tests/phpunit.xml
```

**GATE: Tests pass on current version. This is your safety net.**

---

## Prep Phase 3: MIGRATE PLUGINS (For One Version Step)

### Step 3.1: Automated Migration

```bash
# From elgg-migrate root
php bin/migrate.php rules/{from}-to-{to}/manifest.json ~/plugins-workspace/<plugin>

# Or batch
./bin/migrate-plugin.sh ~/plugins-workspace/<plugin> rules/{from}-to-{to}/manifest.json
```

### Step 3.2: Commit Automated Changes

```bash
git -C ~/plugins-workspace/<plugin> add -A
git -C ~/plugins-workspace/<plugin> commit -m "migrate({N}.x): automated AST transformations"
```

### Step 3.3: Apply LLM-Guided Fixes

Review the `--report` output and apply each fix:

```bash
php bin/migrate.php rules/{from}-to-{to}/manifest.json ~/plugins-workspace/<plugin> --dry-run --report
```

Commit each category of fixes separately.

### Step 3.4: Migrate Custom Plugins In-Place

```bash
php bin/migrate.php rules/{from}-to-{to}/manifest.json <project>/mod/<plugin>
git -C <project> add mod/<plugin>
git -C <project> commit -m "migrate({N}.x): <plugin>"
```

### Step 3.5: Compare With Reference (if available)

```bash
# Clone the reference (e.g., Elgg3-hypeDropzone)
diff ~/plugins-workspace/<plugin> ~/plugins-workspace/Elgg3-<plugin> --stat
```

---

## Prep Phase 4: VERIFY IN DOCKER (Target Version)

### Step 4.1: Boot Docker for Target Version

```bash
docker compose -f docker/elgg{N}/docker-compose.yml \
  -f docker/elgg{N}/docker-compose.override.yml up -d
```

### Step 4.2: Validate Plugin Activation (GATE)

All plugins must activate without fatal errors:

```bash
docker compose exec elgg php -r "
require 'vendor/autoload.php';
\$app = \Elgg\Application::getInstance();
\$app->bootCore();
_elgg_services()->plugins->generateEntities();
// Read activation order
\$order = file('/var/www/html/mod/.plugin-order.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
\$failed = [];
foreach (\$order as \$id) {
    \$id = trim(\$id);
    \$plugin = elgg_get_plugin_from_id(\$id);
    if (!\$plugin || \$plugin->isActive()) continue;
    try { \$plugin->activate(); }
    catch (\Throwable \$e) { \$failed[] = \$id . ': ' . \$e->getMessage(); }
}
if (empty(\$failed)) { echo 'All plugins activated.' . PHP_EOL; }
else { foreach (\$failed as \$f) echo 'FAIL: ' . \$f . PHP_EOL; }
"
```

### Step 4.3: Validate Site Renders (GATE)

```bash
curl -sL http://localhost:${ELGG_PORT}/ | grep -oP '<title>[^<]*</title>'
# Must NOT contain "Fatal Error"
```

### Step 4.4: Run Tests (GATE)

```bash
docker compose exec elgg php vendor/bin/phpunit \
  --configuration mod/<plugin>/tests/phpunit.xml
```

### Step 4.5: Run E2E Smoke Tests

```bash
cd e2e && ELGG_URL=http://localhost:${ELGG_PORT} npx playwright test
```

### Step 4.6: Fix Issues, Iterate

If any gate fails:
1. Fix the issue in the workspace
2. Commit the fix
3. Re-run the failing gate
4. Repeat until all gates pass

---

## Prep Phase 5: REPEAT FOR EACH VERSION STEP

Go back to Prep Phase 3 for the next major version.

When ALL version steps pass ALL gates, the migration branches are ready for production.

---

# PART B: EXECUTE (Production Checklist)

This is a one-time, sequential procedure. No experimentation. Every step has
a verification check. If any check fails, STOP and decide whether to fix
forward or roll back.

## Pre-Flight

- [ ] All migration branches tested in Docker (Part A complete)
- [ ] Maintenance window scheduled
- [ ] Team notified
- [ ] Rollback plan documented

## Execution Checklist

### 1. BACKUP

- [ ] Database dump: `mysqldump -u root -p elgg > elgg_backup_$(date +%Y%m%d).sql`
- [ ] Data directory: `tar czf elgg_data_$(date +%Y%m%d).tar.gz /path/to/data/`
- [ ] Code snapshot: `git tag pre-upgrade-$(date +%Y%m%d)`
- [ ] **Verify backup is restorable** (test restore on a separate server)

### 2. ENABLE MAINTENANCE MODE

- [ ] Add to `settings.php`: `$CONFIG->elgg_maintenance_mode = true;`
- [ ] Or via admin UI: Admin → Settings → Advanced → Maintenance Mode

### 3. UPDATE PLUGIN CODE

- [ ] Merge migration branches for all plugins:
  ```bash
  # For each plugin with upstream repo
  cd ~/plugins-workspace/<plugin>
  git checkout migrate/elgg-{N}.x
  # Symlinks in mod/ already point here

  # For the project repo (custom plugins)
  cd <project>
  git merge migrate/elgg-{N}.x
  ```

### 4. UPDATE ELGG CORE

- [ ] `composer require elgg/elgg:~{N}.0`
- [ ] `composer update`
- [ ] Verify: `php vendor/bin/elgg-cli --version`

### 5. RUN UPGRADE

- [ ] Synchronous: `php vendor/bin/elgg-cli upgrade -v`
- [ ] Async: `php vendor/bin/elgg-cli upgrade async -v`
- [ ] Or web: visit `upgrade.php`, then `admin/upgrades`
- [ ] If stuck: `php vendor/bin/elgg-cli upgrade async -v --force`

### 6. VERIFY

- [ ] Site loads: `curl -sL https://your-site.com/ | grep '<title>'`
- [ ] Admin panel works: log in as admin, check plugins page
- [ ] Key features work: test critical user journeys
- [ ] No PHP errors in logs: `tail /var/log/elgg/error.log`
- [ ] Cron runs: `php vendor/bin/elgg-cli cron -v`

### 7. FLUSH CACHES

- [ ] `php vendor/bin/elgg-cli cache:clear`
- [ ] Or: Admin → Settings → Advanced → Flush Caches

### 8. DISABLE MAINTENANCE MODE

- [ ] Remove `$CONFIG->elgg_maintenance_mode` from settings.php
- [ ] Or via admin UI

### 9. POST-UPGRADE

- [ ] Monitor error logs for 24 hours
- [ ] Verify email notifications still work
- [ ] Verify cron jobs run successfully
- [ ] Update documentation

## Rollback Procedure

If the upgrade fails and cannot be fixed:

```bash
# 1. Restore database
mysql -u root -p elgg < elgg_backup_YYYYMMDD.sql

# 2. Restore code
git checkout pre-upgrade-YYYYMMDD
composer install

# 3. Restore data directory (if changed)
tar xzf elgg_data_YYYYMMDD.tar.gz -C /

# 4. Flush caches
rm -rf data/system_cache/* data/views_simplecache/*

# 5. Verify site works
curl -sL https://your-site.com/ | grep '<title>'
```

---

# Reference

## Version Upgrade Paths (from Elgg docs)

| From | To | Requirement |
|------|----|-------------|
| < 2.0 | 2.x | Upgrade one minor at a time |
| 2.x | 3.x | Must be on 2.3.x first |
| 2.3+ | Any future | Can jump directly (but one-at-a-time is safer) |

## PHP/MySQL Requirements

| Elgg | PHP | MySQL | MariaDB |
|------|-----|-------|---------|
| 2.3 | >=5.6 | >=5.5 | >=5.5 |
| 3.3 | >=7.2 | >=5.6 | >=10.1 |
| 4.3 | >=7.4 | >=5.7 | >=10.3 |
| 5.1 | >=8.0 | >=5.7 | >=10.3 |
| 6.1 | >=8.1 | >=8.0 | >=10.6 |

## Composer Commands Per Step

```bash
# 2.x → 3.x
composer require elgg/elgg:~3.3.0 && composer update && vendor/bin/elgg-cli upgrade async -v

# 3.x → 4.x
composer require elgg/elgg:~4.3.0 && composer update && vendor/bin/elgg-cli upgrade async -v

# 4.x → 5.x
composer require elgg/elgg:~5.1.0 && composer update && vendor/bin/elgg-cli upgrade async -v

# 5.x → 6.x
composer require elgg/elgg:~6.1.0 && composer update && vendor/bin/elgg-cli upgrade async -v
```

## Troubleshooting

**All plugins crash on upgrade:**
Create empty file `<project>/mod/disabled` to disable all plugins. Run upgrade. Remove file. Re-activate plugins individually.

**`upgrade.php` returns error:**
Add `$CONFIG->security_protect_upgrade = false;` to settings.php. Remove after upgrade.

**Upgrade hangs / mutex lock:**
`php vendor/bin/elgg-cli upgrade async -v --force`

**Plugin depends on removed function:**
Check if the function was removed pre-2.x (our rules only cover 2.x→3.x+). Common pre-2.x removals:
- `register_notification_object()` → `elgg_register_notification_event()`
- `register_notification_handler()` → notification event system
- `elgg_register_entity_url_handler()` → `entity:url` hook

**Entity subtable queries crash (3.x):**
`groups_entity`, `users_entity`, `objects_entity`, `sites_entity` tables removed in 3.x. Rewrite JOINs to use metadata queries or QueryBuilder.

## Learnings From bodyology-forum Migration

1. **92 plugins migrated** — 47 had code changes from automated rules
2. **Activation order matters** — use `.plugin-order.txt` to control sequence
3. **Pre-2.x function removals** not covered by automated rules — manual fix needed
4. **PHP version compat** — `isset()` on expressions fails in PHP 7.4+
5. **Entity subtable removal** is the most impactful 3.x schema change
6. **Fresh Docker install auto-enables all plugins** — problematic for testing
7. **Elgg 3.x settings.php** must use `global $CONFIG` pattern
8. **`roave/security-advisories`** conflicts with old Elgg versions — use `"replace"` in composer.json

## Available Migration Rules

| Step | Auto | LLM | Manifest |
|------|:----:|:---:|---------|
| 2.x→3.x | 12 | 15 | `rules/2x-to-3x/manifest.json` |
| 3.x→4.x | 6 | 5 | `rules/3x-to-4x/manifest.json` |
| 4.x→5.x | — | — | TODO |
| 5.x→6.x | — | — | TODO |
