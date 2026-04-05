---
name: elgg-test-writer
description: >
  Use when writing PHPUnit tests for Elgg plugins, generating test suites,
  or adapting tests between Elgg versions. Triggers on "test elgg plugin",
  "write elgg tests", "elgg integration test".
---

# elgg-test-writer

Generate PHPUnit test suites for Elgg plugins, adapted to the target version's testing API.

## Iron Laws

1. **SCAN BEFORE WRITING** — Read every PHP file in the plugin first. Never write tests for functionality you haven't read.
2. **TEST BEHAVIOR, NOT IMPLEMENTATION** — Test what the plugin does, not how it does it.
3. **MATCH THE ELGG VERSION** — Use the correct base classes and session API for the target version.
4. **RUN IN DOCKER** — Integration tests require a running Elgg instance with database.

---

## Quick Reference

| Elgg | Unit Base | Integration Base | Session API |
|------|-----------|-----------------|-------------|
| 2.x | `PHPUnit\Framework\TestCase` | Custom bootstrap | N/A |
| 3.x | `\Elgg\UnitTestCase` | `\Elgg\IntegrationTestCase` | `elgg_get_session()->setLoggedInUser()` |
| 4.x+ | `\Elgg\UnitTestCase` | `\Elgg\IntegrationTestCase` | `_elgg_services()->session_manager->setLoggedInUser()` |

### What to test

| Category | Source | Test type |
|----------|--------|-----------|
| Entity types | `elgg-plugin.php`, `activate.php` | CRUD lifecycle, class mapping |
| Actions | `actions/` directory | Input validation, side effects, permissions |
| Routes | route registrations | URL resolution, response codes |
| Hooks/Events | handler registrations | Handler execution, return values |
| Views | `views/` directory | Render without errors |
| Permissions | permission hooks | Owner can edit, non-owner cannot |

---

## Workflow

### Phase 1: SCAN — catalog all testable features from plugin source

### Phase 2: SET UP test infrastructure

```
<plugin>/tests/
  bootstrap.php
  phpunit.xml
  phpunit/
    unit/<Namespace>/
    integration/<Namespace>/
```

### Phase 3: WRITE TESTS

Use `\Elgg\IntegrationTestCase` for most tests. Key helpers:

```php
$user = $this->createUser();          // auto-cleaned
$group = $this->createGroup();
$object = $this->createObject(['subtype' => 'blog']);
$response = $this->executeAction('action/name', ['key' => 'val']);
```

**Entity CRUD:**
```php
public function testEntityClassMapping(): void {
    $entity = $this->createObject(['subtype' => Post::SUBTYPE]);
    $this->assertInstanceOf(Post::class, get_entity($entity->guid));
}
```

**Actions:**
```php
public function testActionCreatesEntity(): void {
    $user = $this->createUser();
    _elgg_services()->session_manager->setLoggedInUser($user);
    $this->executeAction('myplugin/save', ['title' => 'Test']);
    $entities = elgg_get_entities(['type' => 'object', 'subtype' => 'mytype', 'owner_guid' => $user->guid]);
    $this->assertCount(1, $entities);
}
```

**Permissions:**
```php
public function testNonOwnerCannotEdit(): void {
    $owner = $this->createUser();
    $other = $this->createUser();
    $post = $this->createObject(['subtype' => Post::SUBTYPE, 'owner_guid' => $owner->guid]);
    _elgg_services()->session_manager->setLoggedInUser($other);
    $this->assertFalse($post->canEdit());
}
```

### Phase 4: RUN AND VERIFY

```bash
# In Docker (integration tests need database)
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin>/tests/phpunit.xml
```

### Coverage checklist

- [ ] Entity class mapped correctly
- [ ] Entity CRUD (create, read, update, delete)
- [ ] Each action has at least one test
- [ ] Each hook/event handler tested
- [ ] Key views render without errors
- [ ] Routes registered and resolve
- [ ] Permissions enforced

---

## Version-Specific Notes

### Elgg 3.x
- Plugin boots via `start.php` — tests may need manual boot (see template below)
- `elgg_get_session()->setLoggedInUser($user)` for session
- `_elgg_services()->hooks` for hook service

### Elgg 4.x+
- Plugin boots via `elgg-plugin.php` — test framework handles activation
- `_elgg_services()->session_manager->setLoggedInUser($user)` for session
- `_elgg_services()->events` — hooks and events unified
- No closures in elgg-plugin.php (use class callbacks)
- `canWriteToContainer()` requires `($uid, $type, $subtype)`

---

## File Templates

### bootstrap.php (3.x)
```php
<?php
$elggRoot = dirname(dirname(dirname(__DIR__)));
require_once $elggRoot . '/vendor/autoload.php';
$testClassesDir = $elggRoot . '/vendor/elgg/elgg/engine/tests/classes';
spl_autoload_register(function ($class) use ($testClassesDir) {
    $file = $testClassesDir . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) require_once $file;
});
require_once dirname(__DIR__) . '/autoloader.php';
\Elgg\Application::loadCore();
```

### phpunit.xml (3.x/4.x)
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="bootstrap.php" colors="true">
    <php>
        <env name="ELGG_DB_PREFIX" value="elgg_"/>
        <env name="ELGG_DB_HOST" value="db"/>
        <env name="ELGG_DB_NAME" value="elgg"/>
        <env name="ELGG_DB_USER" value="elgg"/>
        <env name="ELGG_DB_PASS" value="elgg"/>
    </php>
    <testsuites>
        <testsuite name="unit"><directory>phpunit/unit</directory></testsuite>
        <testsuite name="integration"><directory>phpunit/integration</directory></testsuite>
    </testsuites>
</phpunit>
```

### Test class with plugin boot (3.x only)
```php
<?php
namespace MyPlugin;
use Elgg\IntegrationTestCase;

class PluginTest extends IntegrationTestCase {
    private static bool $pluginBooted = false;
    public function up() {
        if (!self::$pluginBooted) {
            require_once dirname(__DIR__, 5) . '/start.php';
            elgg_trigger_event('init', 'system');
            self::$pluginBooted = true;
        }
    }
    public function down() {}
}
```

In 4.x+, no manual boot needed — `elgg-plugin.php` is loaded by the test framework.

---

## Common Mistakes

| Mistake | Fix |
|---------|-----|
| Using `elgg_get_session()` in 4.x tests | Use `_elgg_services()->session_manager` |
| Running integration tests without Docker | Integration tests need database — use Docker |
| Not cleaning up entities | Use `$this->createObject()` — auto-cleaned by Seeding trait |
| Testing implementation details | Test behavior: "entity saved" not "SQL query ran" |
| Missing `canWriteToContainer` args in 4.x | Always pass `($uid, $type, $subtype)` |

---

## CI Setup

Copy `references/ci/elgg3-github-actions.yml` to `.github/workflows/tests.yml` and replace `PLUGIN_NAME`. The workflow starts MySQL, installs Elgg, activates the plugin, and runs PHPUnit.
