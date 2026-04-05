---
name: elgg-site-upgrade
description: >
  Use when upgrading an entire Elgg installation (core + plugins) between major
  versions. Covers the complete path: backup, test coverage, plugin migration,
  Docker verification, and incremental upgrades. Handles both Composer-managed
  and manual installations.
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
> **Usage:** `/elgg-site-upgrade <project-path> [--from=2.x] [--to=6.x]`

## Iron Laws

1. **BACKUP FIRST** — Database, data directory, and code. Every time. No exceptions.
2. **ONE MAJOR VERSION AT A TIME** — Upgrade 2.x→3.x, then 3.x→4.x, etc. Never skip majors.
3. **LATEST MINOR FIRST** — Must be on the latest minor of the current major before jumping. E.g., upgrade to 2.3.x before going to 3.x.
4. **TESTS BEFORE MIGRATION** — Establish test coverage on the current version. Migrate only when tests are green.
5. **VERIFY EACH STEP IN DOCKER** — Don't proceed to the next version until the current step boots and passes tests.

---

## Phase 0: ASSESS THE INSTALLATION

### Step 0.1: Detect Current Version

```bash
# Check Elgg version
grep -r "release" <project>/vendor/elgg/elgg/engine/lib/elgglib.php | head -3
# Or from composer
cat <project>/composer.json | grep "elgg/elgg"
# Or from database
# SELECT value FROM elgg_config WHERE name = 'version';
```

### Step 0.2: Inventory All Plugins

```bash
# List all plugins with their version targets
for d in <project>/mod/*/; do
  name=$(basename "$d")
  if [ -f "$d/manifest.xml" ]; then
    version=$(grep -A1 'elgg_release' "$d/manifest.xml" | grep version | grep -oP '>[^<]+<' | tr -d '><')
    echo "$name: Elgg $version (manifest.xml)"
  elif [ -f "$d/elgg-plugin.php" ]; then
    echo "$name: has elgg-plugin.php (3.x+)"
  else
    echo "$name: unknown format"
  fi
done
```

### Step 0.3: Categorize Plugins

| Category | How to identify | Upgrade strategy |
|----------|----------------|-----------------|
| **Composer-managed** | Listed in project `composer.json` | `composer require vendor/plugin:^N.0` |
| **With upstream repo** | Has `.git` dir or known GitHub repo | Clone, create migrate branch, push |
| **Custom/private** | No upstream, lives only in `mod/` | Migrate in-place, commit to project repo |
| **Core plugins** | Ships with Elgg (blog, groups, etc.) | Upgraded automatically with Elgg core |

### Step 0.4: Find Upgraded Plugin Versions

For each third-party plugin, check if an upgraded version exists:

```bash
# Check if the plugin author has a version for the target Elgg version
# Method 1: Check GitHub branches
gh api repos/<owner>/<plugin>/branches -q '.[].name' | grep -iE '3\.x|4\.x|elgg3|elgg4'

# Method 2: Check Composer for available versions
composer show <vendor>/<plugin> --all --format=json | php -r '...'

# Method 3: Check for Elgg3-* repos (hypeJunction pattern)
gh search repos --owner <org> "Elgg3-<plugin>" --json name -q '.[].name'

# Method 4: Check the plugin's composer.json requires
gh api repos/<owner>/<plugin>/contents/composer.json -q '.content' | base64 -d | grep "elgg/elgg"
```

**hypeJunction-specific:** The hypeJunction org has `Elgg3-<plugin>` repos that are manually migrated versions. These serve as reference implementations for what the migrated plugin should look like.

---

## Phase 1: SETUP WORKSPACE

### Step 1.1: Clone Plugin Repos

For plugins with upstream repos, clone them into a workspace:

```bash
mkdir -p ~/plugins-workspace
git clone https://github.com/<owner>/<plugin>.git ~/plugins-workspace/<plugin>
```

### Step 1.2: Symlink Into Project

Replace `mod/` directories with symlinks to the workspace:

```bash
cd <project>/mod
rm -rf <plugin>
ln -s ~/plugins-workspace/<plugin> <plugin>
```

This ensures changes in the workspace are immediately reflected in the project.

### Step 1.3: Create Migration Branch

```bash
# In the project repo
git checkout -b migrate/elgg-{N}.x

# In each plugin repo
git -C ~/plugins-workspace/<plugin> checkout -b migrate/elgg-{N}.x
```

---

## Phase 2: TEST BASELINE

### Step 2.1: Boot Docker for Current Version

Use the appropriate Docker environment from `docker/elgg{N}/`:

```bash
docker compose -f docker/elgg{N}/docker-compose.yml up -d
```

### Step 2.2: Write Plugin Tests

Use `/elgg-test-writer` skill to generate test suites for each plugin. Focus on:
- Entity CRUD
- Hook/event handler registration
- Action registration and behavior
- Route registration
- Permission checks
- View rendering

### Step 2.3: Verify Tests Pass

```bash
docker compose exec elgg php vendor/bin/phpunit \
  --configuration mod/<plugin>/tests/phpunit.xml
```

**GATE: All tests must pass on the current version before proceeding.**

---

## Phase 3: MIGRATE PLUGINS

### Step 3.1: Run Automated Rules

```bash
# From the elgg-migrate project root
php bin/migrate.php rules/{from}-to-{to}/manifest.json ~/plugins-workspace/<plugin>

# Or batch all plugins
./bin/migrate-plugin.sh ~/plugins-workspace/<plugin> rules/{from}-to-{to}/manifest.json
```

### Step 3.2: Commit Automated Changes

```bash
git -C ~/plugins-workspace/<plugin> add -A
git -C ~/plugins-workspace/<plugin> commit -m "migrate({N}.x): automated AST transformations"
```

### Step 3.3: Apply LLM-Guided Fixes

```bash
# View remaining manual work
php bin/migrate.php rules/{from}-to-{to}/manifest.json ~/plugins-workspace/<plugin> --dry-run --report
```

Apply each LLM rule manually, committing separately.

### Step 3.4: Migrate Custom Plugins In-Place

For plugins without upstream repos (custom/private):

```bash
# Run directly on the project's mod/ directory
php bin/migrate.php rules/{from}-to-{to}/manifest.json <project>/mod/<plugin>

# Commit to the project repo
git -C <project> add mod/<plugin>
git -C <project> commit -m "migrate({N}.x): <plugin> automated transforms"
```

---

## Phase 4: UPGRADE ELGG CORE

### Step 4.1: Update Composer

```bash
cd <project>
composer require elgg/elgg:~{N}.0
composer update
```

### Step 4.2: Run Elgg Upgrade

```bash
# CLI (recommended)
vendor/bin/elgg-cli upgrade async -v

# Or web-based
# Visit: http://your-site/upgrade.php
# Then: http://your-site/admin/upgrades
```

### Step 4.3: Handle Upgrade Failures

If the upgrade fails due to plugin errors:

```bash
# Nuclear option: disable all plugins
touch <project>/mod/disabled

# Run upgrade again
vendor/bin/elgg-cli upgrade async -v

# Remove the disabled file
rm <project>/mod/disabled

# Re-activate plugins individually via admin UI
```

---

## Phase 5: VERIFY IN DOCKER

### Step 5.1: Boot Docker for New Version

```bash
docker compose -f docker/elgg{N}/docker-compose.yml \
  -f docker/elgg{N}/docker-compose.override.yml up -d
```

### Step 5.2: Plugin Activation Order

Plugins must activate in dependency order. Use a `.plugin-order.txt` file in `mod/`:

```bash
# The Docker install script reads this file and activates in order
cp references/bodyology-plugin-order.txt <project>/mod/.plugin-order.txt
```

### Step 5.3: Validate (BLOCKING GATE)

ALL of these must succeed:

```bash
# 1. All plugins activate
docker compose exec elgg php -r "
require 'vendor/autoload.php';
\$app = \Elgg\Application::getInstance();
\$app->bootCore();
\$inactive = elgg_get_plugins('inactive');
echo count(\$inactive) . ' inactive plugins';
"

# 2. Site renders
curl -sL http://localhost:${ELGG_PORT}/ | grep -oP '<title>[^<]*</title>'

# 3. No PHP errors
docker compose exec elgg grep -c 'CRITICAL\|Fatal' /var/log/apache2/error.log

# 4. Tests pass
docker compose exec elgg php vendor/bin/phpunit \
  --configuration mod/<plugin>/tests/phpunit.xml

# 5. E2E smoke tests pass
cd e2e && npx playwright test
```

---

## Phase 6: REPEAT FOR NEXT VERSION

Go back to Phase 3 and repeat for the next major version step.

---

## Version Upgrade Paths

### Official Rules (from Elgg docs)

| From | To | Requirement |
|------|----|-------------|
| < 2.0 | 2.x | Upgrade one minor at a time |
| 2.x | 3.x | Must be on 2.3.x first |
| 2.3+ | Any future | Can jump directly (2.3→5.x is valid) |
| Any minor | Next major | Supported (e.g., 3.1→4.0) |
| Skip major | Not supported | Never go 2.x→4.x directly |

### Composer Commands Per Step

| Step | Command |
|------|---------|
| 2.x → 3.x | `composer require elgg/elgg:~3.3.0 && composer update` |
| 3.x → 4.x | `composer require elgg/elgg:~4.3.0 && composer update` |
| 4.x → 5.x | `composer require elgg/elgg:~5.1.0 && composer update` |
| 5.x → 6.x | `composer require elgg/elgg:~6.1.0 && composer update` |

After each: `vendor/bin/elgg-cli upgrade async -v`

### PHP/MySQL Requirements Per Version

| Elgg | PHP | MySQL | MariaDB |
|------|-----|-------|---------|
| 2.3 | >=5.6 | >=5.5 | >=5.5 |
| 3.3 | >=7.2 | >=5.6 | >=10.1 |
| 4.3 | >=7.4 | >=5.7 | >=10.3 |
| 5.1 | >=8.0 | >=5.7 | >=10.3 |
| 6.1 | >=8.1 | >=8.0 | >=10.6 |

---

## Finding Compatible Plugin Versions

### Strategy 1: Check Upstream Branches

```bash
# Check for version-specific branches
gh api repos/<owner>/<plugin>/branches -q '.[].name'

# Look for: 3.x, 4.x, 5.x, 6.x, master, main
# Or: elgg3, elgg4, elgg-3.x, etc.
```

### Strategy 2: Check Elgg3-* Pattern (hypeJunction)

Some authors maintain separate repos for each Elgg version:

```bash
# hypeJunction uses Elgg3-<plugin> naming
gh search repos --owner hypeJunction "Elgg3-" --json name -q '.[].name'
```

### Strategy 3: Check Composer Versions

```bash
composer show <vendor>/<plugin> --all --format=json 2>/dev/null | \
  php -r '$d=json_decode(file_get_contents("php://stdin"),true); 
  foreach($d["versions"]??[] as $v) echo "$v\n";'
```

### Strategy 4: Check manifest.xml on Branches

```bash
# Fetch manifest from a specific branch
gh api "repos/<owner>/<plugin>/contents/manifest.xml?ref=3.x" -q '.content' | \
  base64 -d | grep -A1 'elgg_release'
```

### Strategy 5: When No Upgraded Version Exists

If no compatible version exists:
1. Use `elgg-migrate` to create one (automated rules + LLM-guided fixes)
2. Fork the repo and maintain your own migrated version
3. Replace with an alternative plugin that supports the target version
4. Remove the plugin if it's non-essential

---

## Learnings From Real-World Migration (bodyology-forum)

### Issues Discovered During 2.x→3.x Migration

| Issue | Plugin | Root Cause | Fix |
|-------|--------|-----------|-----|
| `register_notification_object()` fatal | videolist | Function removed pre-2.x | `elgg_register_notification_event()` |
| `elgg_register_entity_url_handler()` fatal | videolist | Removed in 3.x | `entity:url` hook |
| `isset()` on function result | code_review | PHP 7.4 incompatibility | `!== null` |
| `get_subtype_id()` in activate.php | anypage, news, tour | Function removed in 3.x | `elgg_set_entity_class()` (idempotent) |
| `groups_entity` subtable query | bodyology_theme | Subtables removed in 3.x | Rewrite to use metadata |
| Missing class dependency | bodyology_courses | Plugin activation order | Ensure dependencies activate first |
| `menus_api_combine_menus()` not found | bodyology_theme | Namespace/loading issue | Verify function namespace |

### Plugin Activation Order Matters

In a fresh Elgg install, ALL plugins in `mod/` are registered. They must activate in dependency order. Use `.plugin-order.txt` in `mod/` (one plugin ID per line) to control activation sequence.

### Functions Removed BEFORE 2.x (Not in Our Rules)

Our automated rules cover 2.x→3.x API changes. But some plugins still use functions removed even earlier:
- `register_notification_object()` — removed in 2.0
- `register_notification_handler()` — removed in 2.0
- Old `notify_user()` patterns

These cause immediate fatals and must be fixed manually before the automated rules can run.

### Entity Subtables Removed in 3.x

The most impactful 3.x schema change: `groups_entity`, `users_entity`, `objects_entity`, `sites_entity` tables were removed. All columns moved to metadata.

Any plugin with raw SQL joining these tables will fatal:
```sql
-- BROKEN in 3.x:
SELECT * FROM elgg_entities e
JOIN elgg_groups_entity ge ON e.guid = ge.guid

-- FIX: Use QueryBuilder or metadata queries:
elgg_get_entities(['type' => 'group', 'metadata_name' => 'name', ...])
```

### Composer vs Manual Installation

| | Composer | Manual |
|---|---|---|
| Upgrade command | `composer require elgg/elgg:~N.0 && composer update` | Download, overwrite, visit upgrade.php |
| Plugin install | `composer require vendor/plugin` | Copy to mod/ |
| Rollback | `git checkout composer.lock && composer install` | Restore from backup |
| Recommended for | New installs, actively maintained sites | Legacy sites being upgraded |

### Docker Test Environment Notes

- Use `docker-compose.override.yml` to mount project's `mod/` into the container
- Fresh Elgg install auto-registers and enables ALL plugins in `mod/`
- Must clear system cache after fixing plugins: `rm -rf data/system_cache/*`
- Elgg 3.x settings.php MUST use `global $CONFIG` (not just `$CONFIG = new \stdClass`)
- Bootstrap uses `\Elgg\Application::index()` for web, `::loadCore()` for CLI/tests
- `roave/security-advisories` conflicts with old Elgg versions — use `"replace"` in composer.json

---

## Automated Migration Rules Available

| Version Step | Automated Rules | LLM Rules | Manifest |
|-------------|:---:|:---:|---|
| 2.x → 3.x | 12 | 15 | `rules/2x-to-3x/manifest.json` |
| 3.x → 4.x | 6 | 5 | `rules/3x-to-4x/manifest.json` |
| 4.x → 5.x | — | — | TODO |
| 5.x → 6.x | — | — | TODO |

---

## References

- [Elgg Upgrade Guide](https://learn.elgg.org/en/stable/admin/upgrading.html)
- [Elgg Composer Guide](https://learn.elgg.org/en/stable/admin/composer.html)
- [Elgg Release Policy](https://learn.elgg.org/en/stable/appendix/releases.html)
- [Version Matrix](references/version-matrix.md)
- [Breaking Changes](references/breaking-changes/overview.md)
- [Bodyology Plugin Order](references/bodyology-plugin-order.txt)
