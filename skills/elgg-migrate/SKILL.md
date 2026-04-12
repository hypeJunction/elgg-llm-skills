---
name: elgg-migrate
description: >
  Use when migrating Elgg CMS plugins between major versions (2.x→3.x, 3.x→4.x, etc.),
  upgrading plugin APIs, or modernizing legacy Elgg code. Triggers on "migrate elgg",
  "upgrade plugin", "elgg breaking changes".
---

# elgg-migrate

Migrate Elgg plugins one major version at a time using automated AST rules + LLM-guided fixes, verified in Docker.

## Required Reading

Before starting any migration, the agent MUST consult the relevant docs in `docs/`:

| Doc | When to read |
|-----|--------------|
| `docs/version-api-boundaries.md` | Before applying any rules — confirms which APIs are valid for the target version |
| `docs/plugin-architecture-by-version.md` | Phase 1 (SETUP) and Phase 2.8 (Document) — defines target structure |
| `docs/coding-standards.md` | Phase 1.7 (baseline) and Phase 2.10 (verify) — version-specific style rules |
| `docs/security-review-checklist.md` | After running `--security` — interpret findings |
| `docs/llm-security-review.md` | Phase 2.9 — second-stage LLM security review workflow |
| `docs/post-migration-documentation.md` | Phase 2.8 — generate ARCHITECTURE.md template |

**Linear knowledge rule**: When migrating from version N to N+1, only read the sections of these docs relevant to N and N+1. Do NOT read sections about versions beyond N+1 — that knowledge will leak into your migration and cause version drift.

## Iron Laws

1. **NEVER SKIP A MAJOR VERSION** — 2.x→3.x→4.x→5.x→6.x. Skipping guarantees missed breaking changes.
2. **NEVER MIGRATE WITHOUT A BRANCH** — Branch name is the TARGET version: `migrate/elgg-{TARGET}.x` (e.g., 3→4 = `migrate/elgg-4.x`).
3. **VERIFY IN DOCKER** — Plugin must activate and site must render before proceeding.
4. **TESTS BEFORE MIGRATION** — Write tests against the CURRENT working version BEFORE running any migration rules. Tests are your regression safety net. If tests don't exist, write them first (Phase 1.8). Migration CANNOT start until pre-migration tests pass in Docker.
5. **CLOSURES CANNOT GO IN elgg-plugin.php** — Elgg 4+ serializes plugin config. Use class-based callbacks or Bootstrap.
6. **DIRECTORY NAME MUST MATCH composer.json** — Elgg 4+ requires plugin dir matches the `name` field (lowercase).
7. **LINEAR VERSION KNOWLEDGE ONLY** — When migrating from version N to N+1, the agent MUST only apply N+1 APIs, patterns, and conventions. Do NOT use APIs from version N+2 or later. Example: when migrating 3.x→4.x, use `\Elgg\Hook` (4.x), NOT `\Elgg\Event` (5.x); use `elgg_trigger_plugin_hook()` (4.x, deprecated), NOT `elgg_trigger_event_results()` (5.x). Run `--verify` after every migration to catch leakage.
8. **SECURITY SWEEP AFTER EVERY MIGRATION** — Run `--security` after applying rules. Fix critical findings before committing. Security debt from legacy code gets inherited — catch it at the version boundary.
9. **DOCUMENT AFTER MIGRATION** — After each version step, generate a plugin architecture summary documenting the current structure, registered hooks/events, entities, routes, and any migration notes for future reference.
10. **FOLLOW ELGG CODING STYLE** — Migrated code must follow Elgg's coding standards for the target version. Run PHP_CodeSniffer with Elgg's ruleset after each change. See `docs/coding-standards.md` for version-specific rules.

---

## Container Infrastructure

All operations run inside Docker containers — nothing executes on the host machine.

| Service | Purpose | Location |
|---------|---------|----------|
| `migrate` | AST migration rules (PHP 8.1 + php-parser) | Root `docker-compose.yml` |
| `elgg` | Plugin activation, PHPUnit, Elgg bootstrap | `docker/elgg{N}/docker-compose.yml` |
| `node` | Playwright and Vitest tests | `docker/elgg{N}/docker-compose.yml` (profile: test) |
| `db` | MySQL database | `docker/elgg{N}/docker-compose.yml` |

### Quick setup

```bash
# Build the migration tool (once)
docker compose build migrate

# Start target Elgg environment
docker compose -f docker/elgg{N}/docker-compose.yml up -d

# Run migration
docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin>

# Run tests
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test"
```

### Debugging inside containers

```bash
# Elgg container logs (PHP errors, Apache errors)
docker compose -f docker/elgg{N}/docker-compose.yml logs elgg
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg tail -f /var/log/apache2/error.log

# Interactive shell in Elgg container
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg bash

# Check PHP error log
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg cat /var/log/apache2/error.log

# MySQL queries (interactive)
docker compose -f docker/elgg{N}/docker-compose.yml exec db mysql -uelgg -pelgg elgg

# Check container status
docker compose -f docker/elgg{N}/docker-compose.yml ps

# Node container — debug Playwright
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test --debug"

# Rebuild containers after Dockerfile changes
docker compose -f docker/elgg{N}/docker-compose.yml build --no-cache
```

---

## Quick Reference

| Step | Command |
|------|---------|
| Analyze | `docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --dry-run` |
| Apply + all gates | `docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --verify --security --audit` |
| LLM report | `docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --dry-run --report` |
| Verify only | `docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --dry-run --verify --security --audit` |

### CLI Flags

| Flag | Purpose |
|------|---------|
| `--dry-run` | Analyze only, don't modify files |
| `--report` | Show LLM instructions for manual rules |
| `--verify` | Run post-migration version boundary check (catches future-version API leakage) |
| `--security` | Run security sweep (SQL injection, XSS, command injection, etc.) |
| `--audit` | Run `composer audit` for dependency CVEs |
| `--no-guard` | Skip version guard validation (not recommended) |

### Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | Usage error |
| 2 | Version mismatch (plugin version doesn't match manifest "from") |
| 3 | Post-migration verification failed (future-version APIs detected) |
| 4 | Security sweep found critical issues |
| 5 | Dependency audit found critical/high CVEs |

---

## Beads Acceptance Criteria (MANDATORY for every migration issue)

Every `Migrate <plugin> N.x→M.x` issue MUST satisfy ALL of these gates before being closed. If any gate is skipped, the migration is INCOMPLETE — re-open the issue.

```
[ ] 1. Pre-migration tests written + passing on CURRENT version (Phase 1.8)
[ ] 2. Migration branch created: migrate/elgg-{TARGET}.x
[ ] 3. Automated rules applied (bin/migrate.php with --verify --security)
[ ] 4. LLM-guided manual fixes applied (no warnings remaining in report)
[ ] 5. PHP syntax check clean (php -l on all .php files)
[ ] 6. PostMigrationVerifier passes (no future-version API leakage, no forbidden files)
[ ] 7. SecuritySweep passes (no critical findings)
[ ] 8. Pre-migration tests adapted + passing on TARGET version (Phase 2.6)
[ ] 9. Plugin activates in Docker (elgg{TARGET} container)
[ ] 10. Site renders HTTP 200 with full page (>1000 bytes) — both homepage AND login
[ ] 11. PHP_CodeSniffer passes for target version standards
[ ] 12. ARCHITECTURE.md generated (Phase 2.8)
[ ] 13. CHANGELOG.md updated with migration notes
[ ] 14. Elgg\Upgrade\Batch script added if data migration needed
[ ] 15. Commit message follows format: migrate({TARGET}.x): <summary>
[ ] 16. Issue closed with --reason summarizing what changed
```

**Skill invocation order**:
1. `elgg-test-writer` skill — write pre-migration tests (gate 1)
2. `elgg-migrate` skill — execute migration (gates 2-15)
3. `bd close` — only after ALL gates pass

**Subagent contract**: When dispatching migration to a subagent, the prompt MUST include the full gate checklist above and the subagent MUST report on each gate explicitly (PASS/FAIL/SKIP-WITH-REASON). A migration commit without a gates report is INCOMPLETE and the work must be redone.

**Tools that enforce gates**:
- Gate 6: `php bin/migrate.php ... --verify` (PostMigrationVerifier)
- Gate 7: `php bin/migrate.php ... --security` (SecuritySweep)
- Gate 9-10: `docker compose -f docker/elgg{N}/docker-compose.yml exec elgg ...`

---

## Workflow

### Phase 1: SETUP

1. Obtain plugin (clone or locate in `tmp/`)
2. Detect current version: check `elgg-plugin.php` → `composer.json` → `manifest.xml`
3. Determine path: e.g., 2.x → 3.x → 4.x
4. **CHECK IF ALREADY MIGRATED** (see Phase 1.5 below)

### Phase 1.5: PRE-FLIGHT — Check for Existing Migrations

**Before writing any code**, check whether someone has already done the migration work.
Duplicate migration wastes time and can introduce regressions over a known-good upgrade.

#### Step 1.5.1: Check local git branches

```bash
git -C <plugin-path> branch -a | grep -iE 'migrate|elgg|upgrade|[0-9]\.[0x]'
```

Look for branches like `migrate/elgg-4.x`, `4.x`, `elgg4`, `upgrade/5.x`, etc.
If a target branch exists, inspect it:

```bash
git -C <plugin-path> log --oneline migrate/elgg-3.x..migrate/elgg-4.x
```

If it has migration commits, **start from that branch** instead of re-migrating.

#### Step 1.5.2: Check composer.json and manifest.xml on each branch

```bash
# Check what Elgg version a branch targets
git -C <plugin-path> show migrate/elgg-4.x:composer.json 2>/dev/null | grep "elgg/elgg"
git -C <plugin-path> show migrate/elgg-4.x:manifest.xml 2>/dev/null | grep -A1 'elgg_release'
```

#### Step 1.5.3: Check upstream GitHub for forks and branches

People may have already forked and migrated the plugin:

```bash
# Check upstream branches
gh api repos/<owner>/<plugin>/branches -q '.[].name' | grep -iE '[4-7]\.|migrate|upgrade'

# Check forks for migration work
gh api repos/<owner>/<plugin>/forks -q '.[].full_name' | head -20
# Then check each promising fork:
gh api repos/<fork-owner>/<plugin>/branches -q '.[].name' | grep -iE '[4-7]\.|migrate|upgrade'
```

#### Step 1.5.4: Check the Elgg Plugin Directory

Search the official Elgg plugin directory and Packagist for an updated version:

```bash
# Packagist (Composer registry) — run in Elgg container
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  composer show <vendor>/<plugin> --all 2>/dev/null | grep -E 'versions|descrip'

# Web search for updated versions
# https://elgg.org/plugins — search for the plugin name
# https://packagist.org/packages/<vendor>/<plugin>
```

#### Step 1.5.5: Check version-prefixed repos (hypeJunction pattern)

Some organizations publish version-prefixed repos (e.g., `Elgg3-hypeDropzone` for the 3.x version):

```bash
# Search for version-prefixed variants
gh search repos --owner <org> "Elgg3-<plugin>" --json name -q '.[].name'
gh search repos --owner <org> "Elgg4-<plugin>" --json name -q '.[].name'
# Or without prefix (may be the latest version)
gh search repos --owner <org> "<plugin>" --json name -q '.[].name'
```

#### Step 1.5.6: Assess current state indicators

Quick heuristics to determine what version a plugin already targets:

| Indicator | Version |
|-----------|---------|
| Has `start.php` with `elgg_register_event_handler('init', ...)` at top level | 2.x |
| Has `start.php` that returns a closure + has `elgg-plugin.php` | 3.x |
| Has `manifest.xml` but no `elgg-plugin.php` | 2.x |
| Has `manifest.xml` AND `elgg-plugin.php` | 3.x |
| Has `elgg-plugin.php` with `'hooks'` key, no start.php, no manifest.xml | 4.x |
| Has `elgg-plugin.php` with `'events'` key only (no `'hooks'`) | 5.x+ |
| Has `'plugin'` key in elgg-plugin.php (replaces manifest.xml) | 4.x+ |
| Has `'capabilities'` in entity registration | 4.x+ |
| Has `'group_tools'` key in elgg-plugin.php | 4.x+ |
| Has `'notifications'` key in elgg-plugin.php | 4.x+ |
| Uses `\Elgg\Hook` type hint in callbacks | 3.x or 4.x |
| Uses `\Elgg\Event` type hint in callbacks | 5.x+ |
| Uses `elgg_register_plugin_hook_handler()` in start.php | 2.x or 3.x |
| Uses `elgg_register_page_handler()` | 2.x (removed in 3.x) |
| Uses `elgg_register_library()` / `elgg_load_library()` | 2.x (removed in 3.x) |
| Uses `add_group_tool_option()` | 2.x (use service in 3.x, declarative in 4.x) |
| Uses `elgg_define_js()` / `elgg_require_js()` | ≤5.x, removed in 6.x |
| Uses `elgg_register_esm()` / `elgg_import_esm()` | 6.x+ |
| Uses AMD `define()/require()` in JS | ≤5.x |
| Uses ES module `import/export` in JS | 6.x+ |
| Has `'restorable'` in entity capabilities | 6.x+ |
| Uses `elgg_generate_url()` for URLs | 3.x+ |
| Uses hardcoded URL strings (e.g., `"blog/owner/$name"`) | 2.x |

**Decision tree:**
- If the plugin is already at the target version → **skip migration**
- If a migration branch exists but is incomplete → **continue from that branch**
- If an upstream fork has the migration → **use that instead of re-migrating**
- If no migration exists anywhere → **proceed to Phase 1.8**

### Phase 1.7: Establish Coding Style Baseline

Before migration, capture the plugin's current style state and ensure a `.phpcs.xml` configuration exists for the target version.

```bash
# Check if .phpcs.xml exists, create one if not (see docs/coding-standards.md for template)
ls <plugin-path>/.phpcs.xml 2>/dev/null || echo "Need to create .phpcs.xml"

# Generate baseline report
cd <plugin-path>
vendor/bin/phpcs --standard=PSR12 classes/ actions/ lib/ > /tmp/style-baseline.txt 2>&1
```

The post-migration code MUST maintain or improve style compliance — never regress. See `docs/coding-standards.md` for version-specific rules and the full `.phpcs.xml` template.

### Phase 1.8: PRE-MIGRATION TESTS (BLOCKING GATE)

**Before touching ANY migration code**, the plugin MUST have passing tests against its CURRENT version. These tests become the regression safety net — if migration breaks something, the tests catch it.

**This gate is MANDATORY. Do NOT skip to Phase 2 without passing tests.**

#### Beads dependency tracking

When using beads for issue tracking (fleet migration), the pre-migration test issue MUST block the first migration step. If a plugin requires multiple version steps (e.g., 3→4→5), the chain must be fully wired:

```bash
# Tests block the first migration
bd dep add <migrate-3to4> <tests-issue>
# Each step blocks the next
bd dep add <migrate-4to5> <migrate-3to4>
```

Never create a migration issue without wiring it to its predecessor. A 4.x→5.x migration that doesn't depend on the 3.x→4.x migration (for the same plugin directory) is a broken graph.

#### Step 1.8.1: Check for existing tests

```bash
ls <plugin-path>/tests/phpunit.xml 2>/dev/null && echo "HAS TESTS" || echo "NO TESTS"
```

#### Step 1.8.2: If no tests exist — write them

Use the `elgg-test-writer` skill or the `plugin-test-scaffold` formula:

```bash
bd mol pour plugin-test-scaffold
```

Scan the plugin source to identify all testable features, then write tests covering:

**PHPUnit tests (backend):**
- [ ] Entity class mapping (each registered entity type resolves to correct class)
- [ ] Entity CRUD (create, read, update, delete for each entity subtype)
- [ ] At least one test per action (validates input, creates/modifies entities, checks permissions)
- [ ] Hook/event handlers execute without errors
- [ ] Key views render without fatal errors
- [ ] Permissions (owner can edit, non-owner cannot)

**Playwright tests (UI + database):**
- [ ] Each user-facing feature has at least one Playwright test
- [ ] Forms: fill and submit, assert success response AND verify entity created in database
- [ ] Listings: navigate to list pages, assert items render, pagination works
- [ ] Modals/widgets: trigger UI elements, assert they appear and function
- [ ] Permissions: test as owner vs non-owner, assert correct visibility/access
- [ ] AJAX interactions: trigger async actions, assert UI updates AND database state changes
- [ ] Admin pages: if plugin registers admin views, navigate and assert they render

See `elgg-test-writer` skill for Playwright test templates and patterns.

**Commit tests on the CURRENT branch** (not the migration branch):

```bash
cd <plugin-path>
git add tests/
git commit -m "test: add pre-migration test suite (PHPUnit + Playwright)"
```

#### Step 1.8.3: Run tests in Docker against CURRENT Elgg version

```bash
# Copy plugin into the CURRENT version's Docker container
docker cp <plugin-path>/. $(docker compose -f docker/elgg{CURRENT}/docker-compose.yml ps -q elgg):/var/www/html/mod/<plugin-id>/

# Run PHPUnit tests — ALL must pass
docker compose -f docker/elgg{CURRENT}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin-id>/tests/phpunit.xml

# Run Playwright tests — ALL must pass (inside Docker)
docker compose -f docker/elgg{CURRENT}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test"
```

**All tests (PHPUnit AND Playwright) MUST pass before proceeding to Phase 2.** If tests fail, fix them — they represent real bugs in the current plugin that would be carried forward (or masked) by migration.

#### Step 1.8.4: Establish baseline

Record the test count and passing status for both PHPUnit and Playwright. After migration (Phase 2.6), the same tests must still pass (adapted for the new API if needed).

```bash
# Save PHPUnit baseline
docker compose -f docker/elgg{CURRENT}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin-id>/tests/phpunit.xml 2>&1 | tail -5

# Save Playwright baseline (inside Docker)
docker compose -f docker/elgg{CURRENT}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test --reporter=list" 2>&1 | tail -10
```

### Phase 2: MIGRATE (repeat per version step)

**BRANCH NAMING**: The branch name is the **TARGET** version, not the source.
- Migrating 2.x → 3.x: `migrate/elgg-3.x`
- Migrating 3.x → 4.x: `migrate/elgg-4.x`
- Migrating 4.x → 5.x: `migrate/elgg-5.x`

```bash
# In each plugin's git repo:
git checkout -b migrate/elgg-{TARGET}.x
```

**Step 2.1: Run automated rules (with verification + security)**
```bash
# Run from elgg-migrate root — always include --verify and --security
docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --verify --security

# If verification fails (exit code 3): future-version APIs detected — fix before committing
# If security fails (exit code 4): critical security issues found — fix before committing
cd <plugin-path> && git add -A && git commit -m "migrate({TARGET}.x): automated AST transformations"
```

**Step 2.2: Apply LLM-guided fixes** — use `--dry-run --report` to see instructions, apply each, commit separately.

**Step 2.3: Verify syntax**
```bash
# Syntax-check against the TARGET PHP version inside the Elgg container
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  find mod/<plugin-id> -name "*.php" -not -path "*/vendor/*" -exec php -l {} \; | grep -v "No syntax errors"
```

**Step 2.4: Install plugin dependencies** (if plugin has its own `composer.json`)
```bash
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  composer install -d mod/<plugin-id> --no-interaction
```

**Step 2.5: Validate in Docker (GATE)**

This is a **blocking gate**. Do NOT proceed without passing all checks:

```bash
# Copy into container (use lowercase dir name matching composer.json)
docker cp <plugin>/. $(docker compose -f docker/elgg{N}/docker-compose.yml ps -q elgg):/var/www/html/mod/<plugin-id>/

# Activate plugin — MUST succeed
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg php -r "
  require_once '/var/www/html/vendor/autoload.php';
  \$app = \Elgg\Application::getInstance(); \$app->bootCore();
  _elgg_services()->plugins->generateEntities();
  \$p = elgg_get_plugin_from_id('<plugin-id>');
  if (!\$p) { echo 'FAIL: not found'.PHP_EOL; exit(1); }
  try { \$p->activate(); echo 'OK'.PHP_EOL; }
  catch (\Throwable \$e) { echo 'FAIL: '.\$e->getMessage().PHP_EOL; exit(1); }
"

# Site renders — MUST return >100 bytes (curl from inside the Elgg container)
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  curl -sL http://localhost/ | wc -c

# No PHP errors
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  grep -c "PHP Fatal\|PHP Error" /var/log/apache2/error.log 2>/dev/null

# Run plugin tests if they exist
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin-id>/tests/phpunit.xml

# Verify simplecache CSS is non-empty (css-crush v2.4 silently fails on some CSS)
TS=$(docker compose -f docker/elgg{N}/docker-compose.yml exec -T elgg \
  curl -sL http://localhost/ | grep -oP 'cache/\K\d+' | head -1)
SIZE=$(docker compose -f docker/elgg{N}/docker-compose.yml exec -T elgg \
  curl -sL -o /dev/null -w "%{size_download}" "http://localhost/cache/${TS}/default/elgg.css")
test "$SIZE" -gt 1000 && echo "CSS OK (${SIZE} bytes)" || echo "CSS BROKEN (${SIZE} bytes) — see REFERENCE.md §18"

# Run Playwright tests against the TARGET version (inside Docker)
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test"
```

**Step 2.6: Adapt and verify tests (GATE)**

This is a **blocking gate**. Migration is NOT complete until ALL pre-migration tests (PHPUnit + Playwright) pass against the new version.

Pre-migration tests (from Phase 1.8) were written against the old API. After migration, they need adaptation:

1. **Copy tests to migration branch** (if not already there)
2. **Update PHPUnit test API calls** for the target version:
   - 3.x→4.x: `elgg_get_session()->setLoggedInUser()` → `_elgg_services()->session_manager->setLoggedInUser()`
   - 4.x→5.x: `\Elgg\Hook` → `\Elgg\Event`, hook registrations → event registrations
3. **Update Playwright tests** if routes/URLs changed between versions
4. **Run all tests** against the TARGET version:

```bash
# PHPUnit
docker compose -f docker/elgg{TARGET}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin-id>/tests/phpunit.xml

# Playwright (inside Docker)
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test"
```

5. **Compare with baseline** from Phase 1.8.4 — same test count, all passing
6. **Commit adapted tests:**

```bash
git commit -m "test: adapt tests for Elgg {TARGET}.x"
```

**If pre-migration tests don't exist** (legacy — plugin was migrated before this gate was added):
- STOP. Go back to Phase 1.8 and write tests against the CURRENT version first.
- Only exception: if the plugin has zero PHP logic (pure views/CSS/JS only), document why tests are skipped in the commit message.

**Step 2.7: Compare with reference** (if a manually-migrated version exists upstream)

**Step 2.8: Generate plugin architecture documentation**

After successful migration and verification, document the plugin's current state for future reference:

```bash
# Create or update ARCHITECTURE.md in the plugin root
```

The documentation should include:
- **Plugin summary**: What the plugin does, its entity types and subtypes
- **Directory structure**: Current file layout matching the target version's conventions
- **Registered hooks/events**: All handlers declared in elgg-plugin.php
- **Routes**: All registered routes with their handlers
- **Entities**: All registered entity types with capabilities
- **Actions**: All registered actions
- **Views**: Key views and view extensions
- **Dependencies**: Other plugins this plugin depends on
- **Migration notes**: What changed in this version step, any known issues or workarounds

Commit the documentation:
```bash
git commit -m "docs: add plugin architecture summary for Elgg {TARGET}.x"
```

**Step 2.9: LLM-based deep security review**

After the automated security sweep passes, run the `/security-review` skill on the migrated plugin for deeper analysis the pattern matcher cannot do:

```
/security-review --files=<plugin-path>
```

The LLM review covers:
- **Data flow analysis**: trace user input from `get_input()` through to outputs
- **Authorization gaps**: actions that don't verify ownership before privileged operations
- **Business logic flaws**: IDOR, race conditions, mass assignment
- **Hook/event handler trust**: handlers that trust hook data without validation
- **Migration-introduced issues**: Bootstrap classes with privileged operations, missing CSRF on custom endpoints

Address all HIGH and MEDIUM confidence findings before committing. See `docs/llm-security-review.md` for the full two-stage security workflow.

**Step 2.10: Verify coding standards**

Run PHP_CodeSniffer against the migrated code using Elgg's coding standards:

```bash
# Check coding style
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpcs --standard=./vendor/elgg/elgg/engine/lib/..../phpcs.xml mod/<plugin-id>/classes/ mod/<plugin-id>/actions/ mod/<plugin-id>/views/

# Fix auto-fixable issues
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpcbf --standard=./vendor/elgg/elgg/engine/lib/..../phpcs.xml mod/<plugin-id>/classes/
```

Key style rules by version:
- **3.x+**: PSR-2 base, Elgg extensions (no tabs, 4-space indent, opening brace on same line for classes)
- **4.x+**: Strict types declaration, return type hints on all methods, property type hints
- **5.x+**: Union types, named arguments where they improve readability
- **6.x+**: Readonly properties, enums where applicable

Commit style fixes:
```bash
git commit -m "style: fix coding standards for Elgg {TARGET}.x"
```

### Phase 3: FINALIZE

Review branch history, run `--security` sweep on the final state, generate report.

---

## Version-Specific Breaking Changes

Details in `rules/{from}-to-{to}/manifest.json`. Key highlights:

**2.x → 3.x** (largest): metastrings removed, subtypes→strings, page handlers→routes, libraries→autoloading, ~50 functions removed, entity queries unified.

**3.x → 4.x** (structural): start.php→elgg-plugin.php+Bootstrap, `\DI\object()`→`\DI\create()`, `Zend\Mail`→`Laminas\Mail`, entity attribute setters changed, canWriteToContainer() requires type+subtype, `run_sql_script()` removed, `forward()` removed, JS `elgg.action/get/getJSON/post` → `elgg/Ajax` module, plugin dirs must match composer.json lowercase name, `elgg_register_entity_type()` → entities key in elgg-plugin.php.

**4.x → 5.x**: hooks+events merged, private settings→metadata, PHP 8.0+.

**5.x → 6.x**: RequireJS/AMD→ES modules, MySQL 8.0+.

---

## Plugin Architecture Evolution (Reference Patterns)

Observed from tracing Blog (core), Tidypics (iionly), and group_tools (ColdTrick) across all Elgg versions.

### Elgg 2.x — Procedural Era

```
plugin/
├── start.php          # All init in one function, registered via elgg_register_event_handler('init','system',...)
├── manifest.xml       # Plugin metadata (XML)
├── activate.php       # Runs on plugin activation
├── deactivate.php     # Runs on plugin deactivation
├── lib/plugin.php     # Helper functions, loaded via elgg_register_library() / elgg_load_library()
├── actions/           # Procedural action scripts using get_input(), forward(), register_error()
├── classes/           # Entity classes (ElggBlog, etc.) — flat, not namespaced
└── views/
```

**Key patterns:**
- Routing: `elgg_register_page_handler('blog', 'blog_page_handler')` with manual URL dispatch via switch/case
- Hooks: `elgg_register_plugin_hook_handler()` with 4-arg callbacks: `function($hook, $type, $return, $params)`
- URLs: Hardcoded strings (`"blog/owner/{$entity->username}"`)
- Entity registration: `elgg_register_entity_type('object', 'blog')`
- Group tools: `add_group_tool_option('blog', elgg_echo('blog:enableblog'), true)`
- Menus: `elgg_register_menu_item('site', new ElggMenuItem(...))`
- Widgets: `elgg_register_widget_type('blog', ...)`
- Notifications: `elgg_register_notification_event('object', 'blog', ['publish'])`
- Entity type checks: `elgg_instanceof($entity, 'object', 'blog')`

### Elgg 3.x — Transitional Era (DUAL System)

**Critical insight**: 3.x supports BOTH start.php AND elgg-plugin.php simultaneously. The 2→3 migration should produce this dual format — declarative config in elgg-plugin.php, remaining procedural code in start.php returning a closure.

```
plugin/
├── start.php          # REDUCED — returns a closure, only registers init handler
├── elgg-plugin.php    # NEW — declarative routes, entities, actions, hooks, widgets
├── manifest.xml       # Still exists (removed in 4.x)
├── lib/plugin.php     # Still exists but deprecated
├── classes/Elgg/Blog/ # Namespaced classes start appearing (GroupToolContainerLogicCheck, Seeder)
└── views/
```

**Key patterns:**
- start.php returns a closure: `return function() { elgg_register_event_handler('init', 'system', 'blog_init'); };`
- Hook callbacks already use `\Elgg\Hook` single-arg type hint (NOT a 4.x innovation!)
- `$hook->getValue()`, `$hook->getEntityParam()`, `$hook->getParam('entity')` replace `$return` and `$params`
- URL generation: `elgg_generate_url('collection:object:blog:owner', ['username' => $entity->username])`
- Translation key convention: `'collection:object:blog'` replaces `'blog:blogs'`
- Group tools: `elgg()->group_tools->register('blog')` replaces `add_group_tool_option()`
- Entity checks: `$entity instanceof ElggBlog` replaces `elgg_instanceof()`
- Declarative routes in elgg-plugin.php with named patterns (e.g., `'collection:object:blog:owner'`)

### Elgg 4.x — Declarative Era

```
plugin/
├── elgg-plugin.php       # ONLY config file — routes, entities, actions, hooks, widgets, notifications, group_tools
├── composer.json          # Plugin metadata (replaces manifest.xml)
├── lib/functions.php      # Helper functions loaded via require_once at top of elgg-plugin.php
├── classes/Elgg/Blog/
│   ├── Menus/            # Menu handlers split into dedicated classes (Site, OwnerBlock, etc.)
│   ├── Notifications/    # Notification handlers as classes (PublishBlogEventHandler)
│   ├── GroupToolContainerLogicCheck.php
│   └── Bootstrap.php     # For complex plugins needing imperative init logic
└── views/
```

**Key patterns:**
- NO start.php, NO manifest.xml, NO activate.php, NO deactivate.php
- `'plugin'` key in elgg-plugin.php: `['name' => 'Blog', 'activate_on_install' => true]`
- `'entities'` key with `'capabilities'`: `['commentable' => true, 'searchable' => true, 'likable' => true]`
- `'hooks'` key with class-based handlers: `'Elgg\Blog\Menus\Site::register' => []`
- `'group_tools'` key: `['blog' => []]` (declarative, replaces service call)
- `'notifications'` key: `['object' => ['blog' => ['publish' => PublishBlogEventHandler::class]]]`
- Menu handlers split into separate namespaced classes (one class per menu, one static method per item)
- Helper functions in `lib/functions.php`, loaded via `require_once(__DIR__ . '/lib/functions.php')` at top of elgg-plugin.php
- Complex plugins use `'bootstrap' => \MyPlugin\Bootstrap::class` for imperative init (Tidypics pattern)
- Actions reduced — core handles generic delete, so `blog/delete` action removed

### Elgg 5.x — Unified Events Era

Same structure as 4.x with these changes:

- `'hooks'` key → `'events'` key (THE biggest change)
- `\Elgg\Hook` type hint → `\Elgg\Event` in all handler signatures
- Route middleware additions: `UserPageOwnerGatekeeper`, `PageOwnerGatekeeper`, `GroupPageOwnerGatekeeper`
- `'form:prepare:fields'` event: form preparation moves from procedural `prepare_form_vars()` to `PrepareFields::class`
- Actions further reduced (blog: 3→1, only `blog/save` remains)
- `'mentions'` notification type added alongside `'publish'`
- Private settings removed (migrated to metadata)
- Some plugins rename handler classes: e.g., `TidypicsHooks` → `TidypicsEvents`

### Elgg 6.x — ES Modules Era

Same structure as 5.x with:

- New entity capability: `'restorable' => true` (trash/soft-delete support)
- `entity:url` event for widget types
- RequireJS → ES modules (`elgg_define_js()` removed, use `elgg_register_esm()`)
- MySQL 8.0 minimum, MariaDB 10.6 minimum
- PHP 8.1 minimum

### Migration Checklist by Version Step

**2.x → 3.x: Introduce declarative config alongside procedural code**
- [ ] Create `elgg-plugin.php` with routes, entities, actions from page handler
- [ ] Convert page handler switch/case → declarative `'routes'` array
- [ ] Convert `elgg_register_library()`/`elgg_load_library()` → PSR-4 autoloading
- [ ] Convert hardcoded URLs → `elgg_generate_url()` with named routes
- [ ] Update translation keys: `'blog:blogs'` → `'collection:object:blog'`
- [ ] Convert `add_group_tool_option()` → `elgg()->group_tools->register()`
- [ ] Convert `elgg_instanceof()` → `instanceof` checks
- [ ] Keep start.php returning a closure for hooks not yet extracted
- [ ] Keep manifest.xml (still needed in 3.x)

**3.x → 4.x: Go fully declarative**
- [ ] Move ALL hook/event registrations from start.php → elgg-plugin.php declarative arrays
- [ ] Delete start.php, manifest.xml, activate.php, deactivate.php
- [ ] If activate.php has SQL table creation: move to `Bootstrap::activate()` method, register bootstrap in elgg-plugin.php
- [ ] Add `'plugin'` key with name and metadata (replaces manifest.xml)
- [ ] Extract menu handlers → dedicated namespaced classes (e.g., `Menus\Site`, `Menus\OwnerBlock`)
- [ ] Extract notification handlers → dedicated classes (e.g., `Notifications\PublishEventHandler`)
- [ ] Add `'capabilities'` to entity registration (replaces individual hook registrations for likable etc.)
- [ ] Add `'group_tools'` key (replaces `elgg()->group_tools->register()`)
- [ ] Add `'notifications'` key (replaces `elgg_register_notification_event()`)
- [ ] Move helper functions → `lib/functions.php` with `require_once` at top of elgg-plugin.php
- [ ] Remove redundant actions (core handles generic entity delete in 4.x)
- [ ] Use Bootstrap class for complex imperative init logic

**4.x → 5.x: Merge hooks into events**
- [ ] Rename `'hooks'` key → `'events'` key in elgg-plugin.php
- [ ] Change `\Elgg\Hook` → `\Elgg\Event` in ALL handler signatures
- [ ] Add route middleware: `UserPageOwnerGatekeeper`, `GroupPageOwnerGatekeeper`
- [ ] Convert `prepare_form_vars()` functions → `PrepareFields` event handler class
- [ ] Remove deprecated actions that core now handles
- [ ] Migrate private settings → metadata
- [ ] Consider renaming handler classes (e.g., `Hooks.php` → `Events.php`) for clarity

**5.x → 6.x: Modernize JS and add capabilities**
- [ ] Convert RequireJS AMD `define()/require()` → ES module `import/export`
- [ ] Replace `elgg_define_js()` → `elgg_register_esm()`
- [ ] Replace `elgg_require_js()` → `elgg_import_esm()`
- [ ] Add `'restorable' => true` to entity capabilities where appropriate
- [ ] Update MySQL to 8.0+ compatible syntax
- [ ] Update PHP to 8.1+ features where beneficial

---

## Common Mistakes

| Mistake | Fix |
|---------|-----|
| Closures in elgg-plugin.php | Use `ClassName::class . '::method' => []` or Bootstrap class |
| Plugin dir case mismatch | Dir must match composer.json `name` (usually lowercase) |
| Skipping Docker gate | Always validate — catches serialization, missing deps, type errors |
| Running 4.x rules on 2.x code | Migrate 2→3 first, commit, then 3→4 |
| Not installing plugin deps | Run `composer install -d <plugin>` before Docker test |
| `run_sql_script()` in activate.php | Removed in 4.x — use `elgg()->db->updateData()` with inline SQL |
| `elgg_register_menu_item()` in elgg-plugin.php | Can't go in config — use Bootstrap::init() or register hook |
| Auto-generated hooks use wrong format | Generator outputs `[Class, 'method']` — must be `Class::class . '::method' => []` |
| `elgg_extend_view()` not in elgg-plugin.php | Generator now extracts to `view_extensions` key — verify after running |
| Conditional hooks lost during migration | `elgg_is_active_plugin()` guards need Bootstrap class, not elgg-plugin.php |
| Old hook handler signatures | `($hook,$type,$return,$params)` → `(\Elgg\Hook $hook)` — rule 018 automates this |
| `get_data()` / `insert_data()` etc. | Removed in 4.x — use `elgg()->db->getData()`, `insertData()`, etc. |
| `Elgg\Database` type hints | Renamed to `Elgg\Application\Database` in 4.x — update `use` imports |
| `self::getInstance()` singleton pattern | Use DI container: `elgg()->{'service.key'}` per `elgg-services.php` |
| Tables not created on activation | Elgg 4.x does NOT run `activate.php` — it calls `Bootstrap::activate()` instead. Move all activate.php logic (table creation, schema setup) into `Bootstrap::activate()` method. Delete activate.php after migration. Use `catch (\Throwable $e)` not `catch (DatabaseException)` because Doctrine DBAL throws PDOException that Elgg doesn't wrap. Also fix TEXT columns: MySQL strict mode rejects `DEFAULT ''` on TEXT — remove the DEFAULT |
| Plugin activates but site 500s | Always test RENDER after activation — hooks fire on page load and can crash. Common cause: plugin registers `head`/`page` or `view_vars` hooks that query custom tables not yet created. Add try/catch around DB queries for custom tables as defense-in-depth |
| `elgg_trigger_event_results()` used in 4.x | That's Elgg 5.x only! In 4.x use `elgg_trigger_plugin_hook()` (deprecated but works) |
| `elgg_register_event_handler` for view/view_vars | In 4.x, `view` and `view_vars` are HOOKS not events — use `elgg_register_plugin_hook_handler()` |
| start.php still exists (even empty) | Elgg 4.x REJECTS plugins with ANY start.php file — delete it completely |
| `::SUBTYPE` constant in elgg-plugin.php | Triggers autoload before classes registered — use string literals (::class is fine) |
| `ElggEntity::save()` missing `: bool` return type | Elgg 4.x added return type hints — subclasses must match |
| `elgg_unregister_css()` in Bootstrap | Removed in 4.x — just delete the call (was defensive cleanup) |
| `elgg_register_js()` / `elgg_define_js()` in Bootstrap | Removed in 4.x — JS views under `views/default/js/` auto-discover as AMD modules |
| `elgg_register_simplecache_view()` in Bootstrap | Removed in 4.x — static `.js`/`.css` views auto-discovered by simplecache |
| `views.php` alias in `view_extensions` | When views.php is removed, update `view_extensions` to use actual view paths |
| Global Elgg functions in namespaced Bootstrap | Must use `\elgg_*()` prefix — namespace resolution fails without backslash |
| `elgg_register_admin_menu_item()` in Bootstrap | Removed in 4.x — admin views at `views/default/admin/` are auto-discovered |
| `elgg_set_plugin_setting()` in actions | Removed in 4.x — use `elgg_get_plugin_from_id('id')->setSetting()` |
| `validate_username()` in hooks/actions | Removed in 4.x — use `elgg()->accounts->assertValidUsername()` with try/catch |
| `forward(REFERRER)` in action files | Removed in 4.x — use `return elgg_ok_response()` or `return elgg_error_response()` |
| `forward()` in resource views | Removed in 4.x — use `throw new \Elgg\Exceptions\Http\EntityNotFoundException()` |
| `register_error()` / `system_message()` | Removed in 4.x — use `return elgg_error_response()` / `elgg_ok_response()` |
| `get_installed_translations()` | Removed in 4.x — use `elgg()->translator->getInstalledTranslations()` |
| `elgg_add_subscription()` | Removed in 4.x — use `$entity->addRelationship($user_guid, 'notify'.$method)` |
| Conditional view extensions in elgg-plugin.php | `elgg_is_active_plugin()` guards must go in Bootstrap::init(), not elgg-plugin.php |
| Symfony Response + exit in AJAX actions | Replace with `return elgg_ok_response($data)` / `elgg_error_response($msg, '', 422)` |
| `elgg_get_registered_tag_metadata_names()` | Removed in 4.x — use `elgg_get_config('registered_tag_metadata_names') ?? ['tags']` |
| `require(['jquery-ui'])` in JS | Elgg 4.x uses granular jQuery UI — use `require(['jquery-ui/widgets/sortable'])` etc. |
| XHR JSON echo + exit in action files | Replace manual `echo json_encode(); exit;` with `return elgg_ok_response($data)` — response system handles content negotiation |
| `views.php` removed but views key lost | When AST rule removes `views.php`, verify the `'views'` key in `elgg-plugin.php` preserves JS/CSS path mappings |
| Helper functions in start.php | Move to `lib/functions.php` and load via `Bootstrap::boot()` — don't put in elgg-plugin.php |
| `elgg_get_config('dbprefix')` in raw SQL | Removed in 4.x — use QueryBuilder `$qb->subquery()` or `elgg()->db->getTablePrefix()` |
| `elgg.action()` in JS | Removed in 4.x — use `elgg/Ajax` module: `var ajax = new Ajax(); ajax.action(...)` |
| `\Elgg\Hook` type hint introduced in 4.x | WRONG — `\Elgg\Hook` single-arg signatures work from 3.x onward. The 3→4 change is DECLARATIVE config, not the type hint |
| Monolithic menu handler function | Split into dedicated namespaced classes: `Menus\Site::register`, `Menus\OwnerBlock::registerUserItem`, etc. |
| `elgg_register_entity_type()` for search | Replaced by `'searchable' => true` in entity `'capabilities'` (4.x). Don't register separately |
| `likes:is_likable` hook for liking | Replaced by `'likable' => true` in entity `'capabilities'` (4.x). Don't register a hook for this |
| `elgg_register_notification_event()` still in Bootstrap | Use declarative `'notifications'` key in elgg-plugin.php (4.x) with handler classes |
| `elgg()->group_tools->register()` in Bootstrap | Use declarative `'group_tools'` key in elgg-plugin.php (4.x): `['blog' => []]` |
| Missing `'plugin'` key in elgg-plugin.php | Required in 4.x to replace manifest.xml: `['name' => 'Plugin Name', 'activate_on_install' => true]` |
| `blog_prepare_form_vars()` procedural function | In 5.x, use `'form:prepare:fields'` event with a `PrepareFields` class handler |
| Hardcoded URLs like `"blog/owner/$name"` | Use `elgg_generate_url('collection:object:blog:owner', ['username' => $name])` from 3.x+ |
| Old translation keys like `'blog:blogs'` | Convention changed in 3.x: `'collection:object:blog'`, `'item:object:blog'`, `'add:object:blog'` |
| Not adding route middleware in 5.x | Routes need `UserPageOwnerGatekeeper`, `PageOwnerGatekeeper`, `GroupPageOwnerGatekeeper` middleware |
| Keeping delete action for entities | Core handles generic entity deletion from 4.x — remove plugin-specific delete actions |
| Missing `'restorable' => true` capability | Add to entity capabilities in 6.x for trash/soft-delete support |
| Not using `require_once` for lib/functions.php | In 4.x, helper functions go in `lib/functions.php` loaded via `require_once(__DIR__ . '/lib/functions.php')` at top of elgg-plugin.php |
| Mixing hooks and events keys in 4.x | In 4.x, use `'hooks'` for hooks (view, view_vars, permissions, etc.) and `'events'` for events (create, update, delete). In 5.x, everything merges into `'events'` |

---

## elgg-plugin.php Generation (3.x → 4.x)

The `GenerateElggPluginPhp` rule extracts registrations from start.php into the Elgg 4.x config format. **Always review** the generated file:

### What the rule extracts automatically
- `elgg_register_action()` → `'actions'` key
- `elgg_register_route()` → `'routes'` key
- `elgg_set_entity_class()` / `elgg_register_entity_type()` → `'entities'` key
- `elgg_register_plugin_hook_handler()` → `'hooks'` key (format: `Class::class . '::method' => []`)
- `elgg_register_event_handler()` → `'events'` key
- `elgg_extend_view()` → `'view_extensions'` key (with priority support)
- `elgg_register_widget_type()` → `'widgets'` key
- `elgg_register_notification_event()` → `'notifications'` key

### What requires a Bootstrap class (NOT extractable)
- `elgg_register_menu_item()` — must go in `Bootstrap::init()`
- Conditional registrations (`elgg_is_active_plugin()` guards)
- `elgg()->group_tools->register()` — must go in `Bootstrap::init()`
- `elgg_register_ajax_view()` — must go in `Bootstrap::init()`
- Upgrade event handlers — go in `Bootstrap::upgrade()`
- activate.php logic — goes in `Bootstrap::activate()`

### Correct hook format for Elgg 4.x

**elgg-plugin.php registration format:**
```php
'hooks' => [
    'register' => [
        'menu:entity' => [
            \MyPlugin\Menus::class . '::entityMenu' => [],
        ],
    ],
],
```

**Handler signature — MUST use single-arg format:**
```php
// CORRECT (Elgg 4.x) — handlers get a single Hook/Event object
public static function entityMenu(\Elgg\Hook $hook) {
    $return = $hook->getValue();       // was: $return (3rd arg)
    $entity = $hook->getParam('entity'); // was: $params['entity'] or elgg_extract('entity', $params)
    // $hook->getType(), $hook->getName(), $hook->getParams() also available
    return $return;
}

public static function onCreate(\Elgg\Event $event) {
    $entity = $event->getObject();     // was: $entity (3rd arg)
    // $event->getType(), $event->getName() also available
}

// WRONG — old multi-arg signatures cause "Too few arguments" fatal
public static function entityMenu($hook, $type, $return, $params) { ... }
public static function onCreate($event, $type, $entity) { ... }
```

The `018-hook-callback-signatures` rule automates this rewrite (AST-based).

---

## Docker Environments

| Version | PHP | MySQL | Port | Status |
|---------|-----|-------|------|--------|
| 3.x | 7.4 | 5.7 | 8380 | Working |
| 4.x | 7.4 | 5.7 | 8480 | Working |
| 5.x | 8.0 | 5.7 | 8580 | TODO |
| 6.x | 8.1 | 8.0 | 8680 | TODO |

## Project Structure

```
elgg-migrate/
├── docker-compose.yml               # Root: migrate service (AST tool)
├── docker/
│   ├── migrate/Dockerfile           # PHP 8.1 + php-parser for AST rules
│   ├── elgg3/                       # Elgg 3.x: elgg + db + node services
│   │   ├── docker-compose.yml
│   │   ├── docker-compose.override.yml
│   │   └── Dockerfile
│   └── elgg4/                       # Elgg 4.x: elgg + db + node services
│       ├── docker-compose.yml
│       ├── docker-compose.override.yml
│       └── Dockerfile
├── skills/
│   ├── elgg-migrate/SKILL.md        # This file
│   ├── elgg-test-writer/SKILL.md    # PHPUnit + Playwright test writing
│   ├── elgg-js-test-writer/SKILL.md # Vitest JS test writing
│   ├── elgg-site-upgrade/SKILL.md   # Full site upgrade workflow
│   └── elgg-plugin-fleet/SKILL.md   # Batch plugin migration
├── bin/migrate.php                   # CLI runner (runs in migrate container)
├── src/Rules/V2ToV3/                 # 18 automated rules
├── src/Rules/V3ToV4/                 # 12 automated rules
├── rules/2x-to-3x/                  # 28+ rules (18 auto + LLM)
├── rules/3x-to-4x/                  # 30 rules (13 auto + 17 LLM)
├── tests/                            # 217 tests, 1022 assertions
└── tmp/                              # Guinea pig plugins (gitignored)
```
