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

When ALL version steps pass ALL gates and you're on the latest Elgg version,
proceed to Phase 6.

---

## Prep Phase 6: HARDEN PHP DEPENDENCIES

Once on the latest Elgg version, upgrade PHP dependencies one at a time
to their latest stable versions. This catches compatibility issues early
and ensures you're on supported, patched versions.

### Step 6.1: List Outdated PHP Packages

```bash
composer outdated --direct 2>&1
```

### Step 6.2: Upgrade One Package at a Time

For each outdated package, in order of risk (low-risk utilities first, then
frameworks, then core dependencies last):

```bash
# 1. Check what will change
composer update <vendor>/<package> --dry-run

# 2. Read the package changelog for breaking changes
# Check GitHub releases or CHANGELOG.md

# 3. Apply the update
composer update <vendor>/<package>

# 4. Run ALL tests
vendor/bin/phpunit
cd e2e && npx playwright test

# 5. Commit if tests pass
git add composer.json composer.lock
git commit -m "deps(php): upgrade <vendor>/<package> from X.Y to A.B"
```

### Step 6.3: Recommended Upgrade Order

Start with packages least likely to break things:

```
1. Dev dependencies (phpunit, code sniffers, faker)
2. Utility libraries (monolog, symfony/var-dumper)
3. Mail/HTTP (laminas/laminas-mail, guzzlehttp/guzzle)
4. Image processing (imagine/imagine)
5. Template/view (michelf/php-markdown, css-crush)
6. Database (doctrine/dbal) — HIGH RISK, test thoroughly
7. Framework (symfony/*, php-di/php-di) — HIGH RISK
8. Elgg patch updates (elgg/elgg within same major)
```

### Step 6.4: Handle Breaking Changes

If a package upgrade breaks tests:
1. Read the changelog to understand the API change
2. Fix the code
3. Commit the fix WITH the dependency bump in the same commit
4. If the fix is too complex, pin the package to current version and create a ticket

**GATE: All tests pass after each individual package upgrade.**

---

## Prep Phase 7: HARDEN JS/CSS DEPENDENCIES

Upgrade JavaScript and CSS dependencies one at a time. In Elgg, JS deps
are managed via Composer (npm-asset packages from asset-packagist.org).

### Step 7.1: List JS Dependencies

```bash
# Elgg manages JS via Composer, not npm directly
composer show | grep "npm-asset\|bower-asset"
composer outdated | grep "npm-asset\|bower-asset"
```

### Step 7.2: Upgrade One JS Package at a Time

```bash
# 1. Check current version
composer show npm-asset/jquery

# 2. Update
composer update npm-asset/jquery

# 3. Run PHP tests (they may test view output that includes JS)
vendor/bin/phpunit

# 4. Run JS unit tests (if plugin has them)
npm run test:js

# 5. Run E2E tests (catches JS runtime errors in browser)
cd e2e && npx playwright test

# 6. Manual smoke test in browser — check console for JS errors
# Open http://localhost:8380/ and check browser DevTools console

# 7. Commit
git add composer.json composer.lock
git commit -m "deps(js): upgrade npm-asset/jquery from X.Y to A.B"
```

### Step 7.3: Recommended Upgrade Order

```
1. normalize.css (pure CSS reset, very safe)
2. sprintf-js (utility, rarely breaks)
3. cropperjs / jquery-cropper (image cropping)
4. jquery-colorbox (lightbox — check for API changes)
5. jquery-ui (MEDIUM RISK — widgets may use deprecated methods)
6. jQuery (HIGH RISK — major versions have breaking changes)
7. tagify (HIGH RISK — custom component, API may change)
```

### Step 7.4: Plugin-Level JS Dependencies

If plugins have their own `package.json`:

```bash
cd mod/<plugin>
npm outdated
npm update <package>
npm run test:js
```

### Step 7.5: CSS Dependencies

Elgg uses `css-crush/css-crush` for CSS preprocessing. After upgrading:

```bash
# Verify CSS compiles without errors
# Flush simplecache and load a page
docker compose exec elgg php -r "
require 'vendor/autoload.php';
\$app = \Elgg\Application::getInstance();
\$app->bootCore();
elgg_invalidate_caches();
echo 'Caches flushed' . PHP_EOL;
"

# Check browser for CSS rendering issues
```

### Step 7.6: Writing JS Tests (use `/elgg-js-test-writer`)

Before upgrading JS dependencies, establish JS test coverage:
- Unit tests for pure logic (Vitest)
- Hook/event interaction tests
- E2E tests for UI behavior (Playwright)

This gives you a safety net for detecting regressions.

**GATE: All tests (PHP + JS + E2E) pass after each dependency upgrade.**

---

## Prep Phase 8: FINAL VERIFICATION

- [ ] All Elgg version steps complete (e.g., 2.x→3.x→4.x→5.x→6.x)
- [ ] All PHP dependencies at latest stable
- [ ] All JS/CSS dependencies at latest stable
- [ ] Full test suite green (PHPUnit + Vitest + Playwright)
- [ ] Docker boots with all plugins active
- [ ] No PHP errors in logs
- [ ] No JS console errors in browser

The migration branches are now ready for production.

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

### What Went Right
1. **92 plugins migrated** — automated rules applied to all, 47 had code changes
2. **Batch migration script** (`bin/migrate-plugin.sh`) processed 45 upstream plugins
3. **Docker verification** caught real issues that syntax checks missed
4. **Plugin activation ordering** via `.plugin-order.txt` worked reliably (90/92 activated)

### What We Got Wrong
5. **Skipped Phase 2 (test baseline)** — no tests existed before migration, so we had no safety net to detect regressions
6. **Skipped static analysis** — PHPStan would have caught method signature mismatches (`delete()` vs `delete($follow_symlinks)`), removed interfaces (`Elgg\Cache\Pool`), and undefined functions before Docker
7. **Batch-migrated everything at once** instead of verifying each plugin individually — cascading errors made debugging harder
8. **Fixed issues reactively** in Docker instead of catching them with `php -l` + PHPStan + tests

### Common Issues Encountered
9. **Method signature mismatches** — Elgg 3.x added parameters to methods like `ElggFile::delete($follow_symlinks)`. Overrides without the new parameter cause fatal errors in PHP 7.4+. **PHPStan level 0 catches these.**
10. **Removed interfaces** — `Elgg\Cache\Pool` and similar interfaces removed in 3.x. Classes implementing them cause fatal errors. **PHPStan catches these.**
11. **Removed functions in non-statement context** — `is_memcache_available()` inside `if()` conditions or ternaries aren't removed by our AST rules (only standalone statement calls are removed). **PHPStan catches these.**
12. **Hardcoded vendor paths** — `require_once dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php'` breaks with symlinks. Use `file_exists()` guard or remove (Elgg autoloader already loaded).
13. **Symlinks don't resolve in Docker** — absolute host-path symlinks need the target mounted in the container too.
14. **Pre-2.x function removals** — `register_notification_object()`, `elgg_register_entity_url_handler()` removed before 2.x, not covered by our 2.x→3.x rules.
15. **PHP 7.4 syntax changes** — `isset()` on function results is a parse error.
16. **Entity subtable removal** — `groups_entity`, `users_entity`, etc. removed in 3.x. Raw SQL JOINs to these tables cause fatal errors.
17. **Plugin-local vendor directories** — some plugins have their own `vendors/` or `vendor/` directory. Guard with `file_exists()` before `require_once`.

### Correct Per-Plugin Migration Flow (what we should have done)

```
For EACH plugin:
  1. php -l *.php                    # Syntax check
  1.5. composer install (if plugin has own deps)  # Install plugin-specific packages
  2. phpstan analyse --level 0       # Static analysis (catches signature/interface issues)
  3. Run automated migration rules   # AST transforms
  4. php -l *.php                    # Re-check syntax
  5. phpstan analyse --level 0       # Re-check static analysis
  6. Run tests (if they exist)       # Verify behavior
  7. Commit
  8. Verify in Docker                # Integration check
```

Steps 2 and 5 would have caught ALL the issues we found reactively in Docker.

### Recommended New Rules to Add
- **MethodSignatureCheck** — detect overridden methods with incompatible signatures
- **RemovedInterfaceCheck** — detect classes implementing removed interfaces
- **RemoveVendorAutoload** — detect and remove redundant Elgg root autoloader requires (added)
- **SubtableQueryCheck** — detect JOINs to removed entity subtables
- **ComposerInstallCheck** — detect plugins with composer.json but no vendor/ dir

## Available Migration Rules

| Step | Auto | LLM | Manifest |
|------|:----:|:---:|---------|
| 2.x→3.x | 12 | 15 | `rules/2x-to-3x/manifest.json` |
| 3.x→4.x | 6 | 5 | `rules/3x-to-4x/manifest.json` |
| 4.x→5.x | — | — | TODO |
| 5.x→6.x | — | — | TODO |
