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
4. **NEVER MIGRATE WITHOUT TESTS** — Every migrated plugin MUST have test coverage. Use the `elgg-test-writer` skill or the `plugin-test-scaffold` formula. Migration is NOT complete until tests pass.
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

**Step 2.6: Write tests (GATE)**

This is a **blocking gate**. Migration is NOT complete without test coverage.

Use the `elgg-test-writer` skill or the `plugin-test-scaffold` formula:

```bash
# Scaffold test infrastructure for a plugin
bd mol pour plugin-test-scaffold
```

**Minimum coverage required:**
- [ ] Entity class mapping (each entity type activates correctly)
- [ ] Entity CRUD (create, read, update, delete)
- [ ] At least one test per action
- [ ] Hook/event handlers execute without errors
- [ ] Key views render without fatal errors
- [ ] Permissions (owner can edit, non-owner cannot)

**Run tests in Docker:**
```bash
docker exec <container> php /var/www/html/vendor/bin/phpunit \
  --configuration /var/www/html/mod/<plugin-id>/tests/phpunit.xml
```

If the plugin has no tests directory, create one using the test-writer skill:
1. Scaffold: `tests/phpunit.xml` + `tests/phpunit/integration/` directory
2. Write entity test, action tests, hook tests
3. Run in Docker, fix failures
4. Commit tests separately: `git commit -m "test: add integration tests for <plugin>"`

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
