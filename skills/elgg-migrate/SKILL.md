---
name: elgg-migrate
description: >
  Use when migrating Elgg CMS plugins between major versions (2.x→3.x, 3.x→4.x, etc.),
  upgrading plugin APIs, or modernizing legacy Elgg code. Triggers on "migrate elgg",
  "upgrade plugin", "elgg breaking changes".
---

# elgg-migrate

Migrate Elgg plugins one major version at a time using automated AST rules + LLM-guided fixes, verified in Docker.

## Iron Laws

1. **NEVER SKIP A MAJOR VERSION** — 2.x→3.x→4.x→5.x→6.x. Skipping guarantees missed breaking changes.
2. **NEVER MIGRATE WITHOUT A BRANCH** — Branch name is the TARGET version: `migrate/elgg-{TARGET}.x` (e.g., 3→4 = `migrate/elgg-4.x`).
3. **VERIFY IN DOCKER** — Plugin must activate and site must render before proceeding.
4. **TESTS BEFORE MIGRATION** — Write tests against the CURRENT working version BEFORE running any migration rules. Tests are your regression safety net. If tests don't exist, write them first (Phase 1.8). Migration CANNOT start until pre-migration tests pass in Docker.
5. **CLOSURES CANNOT GO IN elgg-plugin.php** — Elgg 4+ serializes plugin config. Use class-based callbacks or Bootstrap.
6. **DIRECTORY NAME MUST MATCH composer.json** — Elgg 4+ requires plugin dir matches the `name` field (lowercase).

---

## Quick Reference

| Step | Command |
|------|---------|
| Analyze | `php bin/migrate.php rules/{from}-to-{to}/manifest.json <plugin> --dry-run` |
| Apply | `php bin/migrate.php rules/{from}-to-{to}/manifest.json <plugin>` |
| Batch script | `./bin/migrate-plugin.sh <plugin-path> rules/{from}-to-{to}/manifest.json` |
| LLM report | `php bin/migrate.php rules/{from}-to-{to}/manifest.json <plugin> --dry-run --report` |

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
# Packagist (Composer registry)
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
| Has `start.php` with `elgg_register_event_handler('init', ...)` | 2.x or 3.x |
| Has `manifest.xml` but no `elgg-plugin.php` | 2.x |
| Has `elgg-plugin.php` with `'hooks'` key | 3.x or 4.x |
| Has `elgg-plugin.php` with `'events'` key only (no `'hooks'`) | 5.x+ |
| Uses `\Elgg\Hook` type hint in callbacks | 4.x |
| Uses `\Elgg\Event` type hint in callbacks | 5.x+ |
| Uses `elgg_register_plugin_hook_handler()` | ≤4.x (deprecated), removed in 6.x |
| Uses `elgg_define_js()` / `elgg_require_js()` | ≤5.x, removed in 6.x |
| Uses `elgg_register_esm()` / `elgg_import_esm()` | 6.x+ |
| No `start.php`, no `manifest.xml`, no `views.php` | 4.x+ |
| Uses AMD `define()/require()` in JS | ≤5.x |
| Uses ES module `import/export` in JS | 6.x+ |

**Decision tree:**
- If the plugin is already at the target version → **skip migration**
- If a migration branch exists but is incomplete → **continue from that branch**
- If an upstream fork has the migration → **use that instead of re-migrating**
- If no migration exists anywhere → **proceed to Phase 1.8**

### Phase 1.8: PRE-MIGRATION TESTS (BLOCKING GATE)

**Before touching ANY migration code**, the plugin MUST have passing tests against its CURRENT version. These tests become the regression safety net — if migration breaks something, the tests catch it.

**This gate is MANDATORY. Do NOT skip to Phase 2 without passing tests.**

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

- [ ] Entity class mapping (each registered entity type resolves to correct class)
- [ ] Entity CRUD (create, read, update, delete for each entity subtype)
- [ ] At least one test per action (validates input, creates/modifies entities, checks permissions)
- [ ] Hook/event handlers execute without errors
- [ ] Key views render without fatal errors
- [ ] Permissions (owner can edit, non-owner cannot)

**Commit tests on the CURRENT branch** (not the migration branch):

```bash
cd <plugin-path>
git add tests/
git commit -m "test: add pre-migration test suite"
```

#### Step 1.8.3: Run tests in Docker against CURRENT Elgg version

```bash
# Copy plugin into the CURRENT version's Docker container
docker cp <plugin-path>/. $(docker compose -f docker/elgg{CURRENT}/docker-compose.yml ps -q elgg):/var/www/html/mod/<plugin-id>/

# Run tests — ALL must pass
docker compose -f docker/elgg{CURRENT}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin-id>/tests/phpunit.xml
```

**All tests MUST pass before proceeding to Phase 2.** If tests fail, fix them — they represent real bugs in the current plugin that would be carried forward (or masked) by migration.

#### Step 1.8.4: Establish baseline

Record the test count and passing status. After migration (Phase 2.6), the same tests must still pass (adapted for the new API if needed).

```bash
# Save baseline
docker compose -f docker/elgg{CURRENT}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin-id>/tests/phpunit.xml 2>&1 | tail -5
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

**Step 2.1: Run automated rules**
```bash
cd /path/to/elgg-migrate
php -r 'require "vendor/autoload.php"; $r = new ElggMigrate\RuleRunner(); $r->applyAll("rules/{from}-to-{to}/manifest.json", "<plugin-path>");'
cd <plugin-path> && git add -A && git commit -m "migrate({TARGET}.x): automated AST transformations"
```

**Step 2.2: Apply LLM-guided fixes** — use `--dry-run --report` to see instructions, apply each, commit separately.

**Step 2.3: Verify syntax**
```bash
find <plugin> -name "*.php" -not -path "*/vendor/*" -exec php -l {} \; | grep -v "No syntax errors"
```

**Step 2.4: Install plugin dependencies** (if plugin has its own `composer.json`)
```bash
composer install -d <plugin> --no-interaction
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

# Site renders — MUST return >100 bytes
curl -sL http://localhost:${ELGG_PORT}/ | wc -c

# No PHP errors
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  grep -c "PHP Fatal\|PHP Error" /var/log/apache2/error.log 2>/dev/null

# Run plugin tests if they exist
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin-id>/tests/phpunit.xml

# Verify simplecache CSS is non-empty (css-crush v2.4 silently fails on some CSS)
TS=$(curl -sL http://localhost:${ELGG_PORT}/ | grep -oP 'cache/\K\d+' | head -1)
SIZE=$(curl -sL -o /dev/null -w "%{size_download}" "http://localhost:${ELGG_PORT}/cache/${TS}/default/elgg.css")
test "$SIZE" -gt 1000 && echo "CSS OK (${SIZE} bytes)" || echo "CSS BROKEN (${SIZE} bytes) — see REFERENCE.md §18"
```

**Step 2.6: Adapt and verify tests (GATE)**

This is a **blocking gate**. Migration is NOT complete until the pre-migration tests pass against the new version.

Pre-migration tests (from Phase 1.8) were written against the old API. After migration, they need adaptation:

1. **Copy tests to migration branch** (if not already there)
2. **Update test API calls** for the target version:
   - 3.x→4.x: `elgg_get_session()->setLoggedInUser()` → `_elgg_services()->session_manager->setLoggedInUser()`
   - 4.x→5.x: `\Elgg\Hook` → `\Elgg\Event`, hook registrations → event registrations
3. **Run adapted tests in Docker** against the TARGET version:

```bash
docker compose -f docker/elgg{TARGET}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin-id>/tests/phpunit.xml
```

4. **Compare with baseline** from Phase 1.8.4 — same test count, all passing
5. **Commit adapted tests:**

```bash
git commit -m "test: adapt tests for Elgg {TARGET}.x"
```

**If pre-migration tests don't exist** (legacy — plugin was migrated before this gate was added):
- STOP. Go back to Phase 1.8 and write tests against the CURRENT version first.
- Only exception: if the plugin has zero PHP logic (pure views/CSS/JS only), document why tests are skipped in the commit message.

**Step 2.7: Compare with reference** (if a manually-migrated version exists upstream)

### Phase 3: FINALIZE

Review branch history, run security scan (unescaped output, missing CSRF, raw SQL), generate report.

---

## Version-Specific Breaking Changes

Details in `rules/{from}-to-{to}/manifest.json`. Key highlights:

**2.x → 3.x** (largest): metastrings removed, subtypes→strings, page handlers→routes, libraries→autoloading, ~50 functions removed, entity queries unified.

**3.x → 4.x** (structural): start.php→elgg-plugin.php+Bootstrap, `\DI\object()`→`\DI\create()`, `Zend\Mail`→`Laminas\Mail`, entity attribute setters changed, canWriteToContainer() requires type+subtype, `run_sql_script()` removed, `forward()` removed, JS `elgg.action/get/getJSON/post` → `elgg/Ajax` module, plugin dirs must match composer.json lowercase name, `elgg_register_entity_type()` → entities key in elgg-plugin.php.

**4.x → 5.x**: hooks+events merged, private settings→metadata, PHP 8.0+.

**5.x → 6.x**: RequireJS/AMD→ES modules, MySQL 8.0+.

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
| Tables not created on activation | Docker fresh install doesn't run activate.php — create tables manually for testing |
| Plugin activates but site 500s | Always test RENDER after activation — hooks fire on page load and can crash |
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
├── skills/
│   ├── elgg-migrate/SKILL.md       # This file
│   └── elgg-test-writer/SKILL.md   # Test writing skill
├── bin/migrate.php                  # CLI runner
├── bin/migrate-plugin.sh            # Batch script (branch + migrate + commit)
├── src/Rules/V2ToV3/                # 18 automated rules
├── src/Rules/V3ToV4/                # 12 automated rules
├── rules/2x-to-3x/                 # 28+ rules (18 auto + LLM)
├── rules/3x-to-4x/                 # 30 rules (13 auto + 17 LLM)
├── tests/                           # 217 tests, 1022 assertions
├── docker/elgg{3,4}/                # Docker environments
└── tmp/                             # Guinea pig plugins (gitignored)
```
