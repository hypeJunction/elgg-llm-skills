# Testing Elgg 3.x Plugins — Learnings & Patterns

## Test Framework Setup

### Dependencies
Elgg 3.x ships test base classes in `vendor/elgg/elgg/engine/tests/classes/` but they're NOT autoloaded. You need:

1. PHPUnit 8.x (`composer require --dev phpunit/phpunit:^8.0`)
2. Custom autoloader for Elgg test classes in your bootstrap

### Bootstrap (tests/bootstrap.php)

```php
<?php
$elggRoot = dirname(dirname(dirname(__DIR__))); // mod/plugin/tests → root
require_once $elggRoot . '/vendor/autoload.php';

// Autoload Elgg test base classes
$testClassesDir = $elggRoot . '/vendor/elgg/elgg/engine/tests/classes';
spl_autoload_register(function ($class) use ($testClassesDir) {
    $file = $testClassesDir . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) require_once $file;
});

// Load plugin autoloader
require_once dirname(__DIR__) . '/autoloader.php';

// Load Elgg core functions (NOT bootCore — that's for web requests)
\Elgg\Application::loadCore();
```

### phpunit.xml

```xml
<phpunit bootstrap="bootstrap.php" colors="true">
    <php>
        <!-- Use existing Elgg database -->
        <env name="ELGG_DB_PREFIX" value="elgg_"/>
        <env name="ELGG_DB_HOST" value="db"/>
        <env name="ELGG_DB_NAME" value="elgg"/>
        <env name="ELGG_DB_USER" value="elgg"/>
        <env name="ELGG_DB_PASS" value="elgg"/>
    </php>
    <testsuites>
        <testsuite name="integration">
            <directory>phpunit/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

## Base Classes

| Class | Purpose | DB Access |
|-------|---------|-----------|
| `\Elgg\IntegrationTestCase` | Full Elgg + database | Yes |
| `\Elgg\UnitTestCase` | Isolated, no database | No |

### Required abstract methods (Elgg 3.x specific!)
Both base classes require `up()` and `down()` methods (NOT `setUp()`/`tearDown()`):

```php
class MyTest extends \Elgg\IntegrationTestCase {
    public function up() {}   // Required — runs before each test
    public function down() {} // Required — runs after each test
}
```

## Key Gotchas

### 1. Plugin Not Active in Test Environment
`IntegrationTestCase` boots a fresh Elgg instance per test class. Your plugin is NOT activated automatically. Entity class mappings, hooks, and routes won't exist.

**Solution:** Manually trigger plugin initialization in `up()`:

```php
private static bool $pluginBooted = false;

public function up() {
    if (!self::$pluginBooted) {
        $pluginDir = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
        require_once $pluginDir . '/start.php';
        elgg_trigger_event('init', 'system');
        // Also register entity classes
        elgg_set_entity_class('object', MyEntity::SUBTYPE, MyEntity::class);
        self::$pluginBooted = true;
    }
}
```

### 2. Entity Class Mapping Must Be Explicit
`$this->createObject(['subtype' => 'mysubtype'])` returns `ElggObject`, not your custom class, unless `elgg_set_entity_class()` has been called. The `activate.php` file doesn't run in tests.

**Solution:** Call `elgg_set_entity_class()` in `up()` or in the bootstrap.

### 3. Session Management
Elgg 3.x uses `elgg_get_session()`, NOT `_elgg_services()->session_manager` (that's 4.x+):

```php
// Elgg 3.x
elgg_get_session()->setLoggedInUser($user);

// Elgg 4.x+
_elgg_services()->session_manager->setLoggedInUser($user);
```

### 4. Database Prefix
The test framework defaults to prefix `c_i_elgg_` to isolate from production data. If you want to use the existing Elgg database tables, set `ELGG_DB_PREFIX` to `elgg_` in phpunit.xml.

**Warning:** This means tests can modify production data. Use with caution or use a dedicated test database.

### 5. `loadCore()` vs `bootCore()`
- `loadCore()` — loads engine function files (elgg_*, etc.). Use in bootstrap.
- `bootCore()` — full boot including DB, plugins, hooks. The `IntegrationTestCase` calls this internally.
- `Application::start()` / `Application::index()` — web request handlers. Never use in tests.

## What Works Without Plugin Activation

These tests work even without the plugin's init handler running:
- Basic entity CRUD (create, read, update, delete) via `$this->createObject()`
- Entity attribute access
- Direct function calls on entity instances
- Core Elgg API calls

## What Requires Plugin Activation

These need the plugin's init handler to have run:
- Route registration tests
- Hook handler tests
- Widget registration tests
- Action registration tests
- Permission hook tests
- Entity URL hook tests
- Menu item registration tests

## Running Tests

```bash
# In Docker container:
php vendor/bin/phpunit --configuration mod/<plugin>/tests/phpunit.xml

# With test isolation (separate DB prefix):
ELGG_DB_PREFIX=test_ php vendor/bin/phpunit --configuration mod/<plugin>/tests/phpunit.xml
```
