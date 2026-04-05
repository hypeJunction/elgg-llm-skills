---
name: elgg-migrate
description: >
  Use when migrating Elgg CMS plugins from legacy versions (1.x-5.x) to modern
  Elgg (up to 6.x). Handles one major version at a time with automated scripts,
  security auditing, Docker-based verification, and test coverage. Triggers on
  Elgg plugin migration, upgrade, or modernization tasks.
category: process
triggers:
  - elgg migration
  - elgg upgrade
  - elgg plugin migration
  - migrate elgg
  - upgrade elgg plugin
user-invocable: true
---

# elgg-migrate

> **Purpose:** Migrate Elgg plugins from legacy versions to modern Elgg, one major version at a time.
> **Phases:** Scan → Migrate (branch per version) → Verify in Docker → Finalize
> **Usage:** `/elgg-migrate <plugin-repo-url-or-path> [--target=6.x] [--dry-run]`

## Iron Laws

1. **NEVER SKIP A MAJOR VERSION** — Migrate 2.x→3.x, then 3.x→4.x, etc. Skipping guarantees missed breaking changes.
2. **NEVER MIGRATE WITHOUT A BRANCH** — Each version step gets its own git branch (`migrate/elgg-3.x`, etc.).
3. **COMMIT EACH CHANGE CATEGORY SEPARATELY** — Automated transforms in one commit, each manual fix category in its own commit.
4. **VERIFY IN DOCKER** — Every version step must boot in Docker with the plugin active before proceeding.
5. **SECURITY ISSUES BLOCK MIGRATION** — Critical vulnerabilities found during migration must be fixed.

## Scope Flags

| Flag | Description |
|------|-------------|
| `--target=N.x` | Target Elgg version (default: 6.x) |
| `--dry-run` | Analyze only, don't apply changes |
| `--skip-docker` | Skip Docker verification |

---

## Phase 1: SETUP

**Mode:** Read-only + git operations

### Step 1.1: Obtain Plugin

```bash
# If URL provided, clone into tmp/
git clone <plugin-repo-url> tmp/<plugin-name>
cd tmp/<plugin-name>
```

If the plugin is already cloned (e.g., in `tmp/`), work directly on it.

### Step 1.2: Detect Current Elgg Version

Check in order:
1. `elgg-plugin.php` → look for `requires.elgg` version
2. `composer.json` → look for `elgg/elgg` in require
3. `manifest.xml` → look for `<requires>` with `elgg_release`

### Step 1.3: Determine Migration Path

Calculate the version steps needed. Example for a 2.x plugin targeting 6.x:
```
2.x → 3.x → 4.x → 5.x → 6.x
```

Each step uses its own manifest at `rules/{from}-to-{to}/manifest.json`.

---

## Phase 2: MIGRATE (repeat for each version step)

**Mode:** Full write access with git

### Step 2.1: Create Version Branch

```bash
git checkout -b migrate/elgg-{N}.x
```

### Step 2.2: Run Automated Rules

From the elgg-migrate project root:

```bash
php bin/migrate.php rules/{from}-to-{to}/manifest.json tmp/<plugin-name>
```

This applies all automated AST-based transformations. Review the output for warnings.

### Step 2.3: Commit Automated Changes

```bash
git add -A
git commit -m "migrate({N}.x): automated AST transformations

Applied by elgg-migrate automated rules:
- <list each rule that applied and what it changed>"
```

### Step 2.4: Apply LLM-Guided Fixes

For each non-automated rule in the manifest, read the `llm_instructions` field and apply the changes. The migration CLI shows these:

```bash
php bin/migrate.php rules/{from}-to-{to}/manifest.json tmp/<plugin-name> --dry-run --report
```

Work through each applicable LLM rule:
1. Search the codebase for the pattern described
2. Apply the transformation
3. Verify syntax: `php -l <file>`
4. Commit with a descriptive message:

```bash
git add -A
git commit -m "migrate({N}.x): <description of manual fixes>

- <detail each change made>"
```

### Step 2.5: Handle Known Pain Points

#### Legacy Upgrade Scripts (2.x→3.x)
Old upgrade scripts (`lib/upgrades.php`) often use metastrings, subtype IDs, and raw SQL incompatible with 3.x. These should be:
- Disabled (wrapped in version check or replaced with a comment)
- Replaced with `Elgg\Upgrade\Batch` classes if data migration is needed

#### activate.php / deactivate.php Cleanup
- `add_subtype`/`update_subtype` → `elgg_set_entity_class` (idempotent, call once)
- `update_subtype` with 2 args (clearing) → remove (not needed in 3.x+)
- deactivate.php subtype cleanup → remove (not needed in 3.x+)

#### Redundant Patterns After AST Transform
The AST transform may produce redundant code. Example:
```php
// Before: if (!update_subtype('object', 'x', X::class)) { add_subtype('object', 'x', X::class); }
// After:  if (!elgg_set_entity_class('object', 'x', X::class)) { elgg_set_entity_class('object', 'x', X::class); }
// Fix:    elgg_set_entity_class('object', 'x', X::class);  // idempotent
```

Review transformed code for these patterns and simplify.

### Step 2.6: Verify PHP Syntax

```bash
find tmp/<plugin-name> -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"
```

### Step 2.7: Verify in Docker

```bash
# Start the Docker environment for the target version
docker compose -f docker/elgg{N}/docker-compose.yml up -d

# Wait for installation
sleep 30

# Copy plugin into container
docker cp tmp/<plugin-name>/. $(docker compose -f docker/elgg{N}/docker-compose.yml ps -q elgg):/var/www/html/mod/<plugin-name>/

# Activate plugin
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg php -r "
require_once '/var/www/html/vendor/autoload.php';
\$app = \Elgg\Application::getInstance();
\$app->bootCore();
_elgg_services()->plugins->generateEntities();
\$plugin = elgg_get_plugin_from_id('<plugin-name>');
if (\$plugin && !\$plugin->isActive()) {
    \$plugin->activate();
    echo 'Activated' . PHP_EOL;
} else {
    echo (\$plugin ? 'Already active' : 'Not found') . PHP_EOL;
}
"

# Verify site loads
curl -sL http://localhost:8380/ | grep -oP '<title>[^<]*</title>'

# Stop when done
docker compose -f docker/elgg{N}/docker-compose.yml stop
```

### Step 2.8: Compare with Reference (if available)

If the plugin author has a branch targeting the new version, compare:
```bash
git fetch origin
git diff migrate/elgg-{N}.x origin/{reference-branch} -- start.php
```

This validates our migration against the author's manual work.

---

## Phase 3: FINALIZE

**Mode:** Read-only verification

### Step 3.1: Review Branch History

```bash
git log --oneline migrate/elgg-{N}.x --not master
```

Each version step should have:
1. One commit for automated AST transforms
2. One or more commits for manual LLM-guided fixes
3. Clear commit messages describing what changed and why

### Step 3.2: Security Scan

Quick check for common vulnerabilities:
- Unescaped output in views (`echo $vars['x']` without escaping)
- Actions without CSRF token validation
- Raw SQL with user input
- Missing permission checks

### Step 3.3: Generate Migration Report

Summarize:
- Total files changed per version step
- Which automated rules applied
- Which LLM rules needed manual work
- Security issues found
- Known limitations or remaining manual steps

---

## Version-Specific Notes

### 2.x → 3.x (largest migration)
- **Metastrings removed** — all raw SQL using metastrings tables is broken
- **Subtypes are strings** — `add_subtype()`/`update_subtype()` → `elgg_set_entity_class()`
- **Page handlers deprecated** — `elgg_register_page_handler()` → `elgg_register_route()`
- **Libraries removed** — `elgg_register_library()`/`elgg_load_library()` → autoloading
- **Entity queries unified** — `elgg_get_entities_from_*()` → `elgg_get_entities()`
- **Config global proxied** — `global $CONFIG` → `elgg_get_config()`
- **~50 functions removed** — see `rules/2x-to-3x/manifest.json` for complete list

### 3.x → 4.x
- **manifest.xml + start.php → elgg-plugin.php** — biggest structural change
- **All _elgg_* callbacks → class-based handlers**
- **Type hints added everywhere**

### 4.x → 5.x
- **Hooks and events merged** — `hooks` → `events` in elgg-plugin.php
- **Private settings removed** → metadata
- **PHP 8.0+ required**

### 5.x → 6.x
- **RequireJS/AMD → ES modules**
- **MySQL 8.0+ required**

## Docker Environments

Each version has a Docker setup in `docker/elgg{N}/`:

| Version | PHP | MySQL | Port | Status |
|---------|-----|-------|------|--------|
| 3.x | 7.4 | 5.7 | 8380 | Working |
| 4.x | 7.4 | 5.7 | 8480 | TODO |
| 5.x | 8.0 | 5.7 | 8580 | TODO |
| 6.x | 8.1 | 8.0 | 8680 | TODO |

## Project Structure

```
elgg-migrate/
├── SKILL.md              # This file
├── bin/migrate.php       # CLI migration runner
├── src/                  # Rule implementations
│   ├── AbstractRule.php
│   ├── MigrationRule.php
│   ├── RuleRunner.php
│   └── Rules/V2ToV3/    # 11 automated rules
├── rules/                # Version manifests
│   └── 2x-to-3x/manifest.json  # 26 rules (11 auto + 15 LLM)
├── tests/                # PHPUnit tests (55 tests, 445 assertions)
├── references/           # Breaking change docs
├── docker/               # Docker environments per version
│   └── elgg3/            # Working Elgg 3.3.25 setup
└── tmp/                  # Guinea pig plugins (gitignored)
```

## References

- [Version Matrix](references/version-matrix.md)
- [Breaking Changes Overview](references/breaking-changes/overview.md)
- [Removed Functions](references/breaking-changes/removed-functions.md)
