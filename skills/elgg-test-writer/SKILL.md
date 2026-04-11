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
5. **UI TESTS ARE MANDATORY** — Every plugin with user-facing features MUST have Playwright tests that assert both UI state and database state.

---

## Quick Reference

| Elgg | Unit Base | Integration Base | Session API |
|------|-----------|-----------------|-------------|
| 2.x | `PHPUnit\Framework\TestCase` | Custom bootstrap | N/A |
| 3.x | `\Elgg\UnitTestCase` | `\Elgg\IntegrationTestCase` | `elgg_get_session()->setLoggedInUser()` |
| 4.x | `\Elgg\UnitTestCase` | `\Elgg\IntegrationTestCase` | `elgg_get_session()->setLoggedInUser()` |
| 5.x+ | `\Elgg\UnitTestCase` | `\Elgg\IntegrationTestCase` | `_elgg_services()->session_manager->setLoggedInUser()` |

### What to test

| Category | Source | PHPUnit | Playwright |
|----------|--------|---------|------------|
| Entity types | `elgg-plugin.php`, `activate.php` | CRUD lifecycle, class mapping | — |
| Actions | `actions/` directory | Input validation, side effects, permissions | Form submit → assert DB state |
| Routes | route registrations | URL resolution, response codes | Navigate → assert page renders |
| Hooks/Events | handler registrations | Handler execution, return values | — |
| Views | `views/` directory | Render without errors | Assert UI elements visible |
| Permissions | permission hooks | Owner can edit, non-owner cannot | Login as different users, assert access |
| Forms | form views + actions | — | Fill form, submit, assert UI + DB |
| Listings | list views | — | Navigate, assert items, pagination |
| Modals/Widgets | JS-driven UI | — | Trigger, assert appear/function |
| Admin pages | `views/default/admin/` | — | Navigate, assert renders |
| AJAX | async actions | — | Trigger action, assert UI update + DB |

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
$user = $this->createUser();          // auto-cleaned after test
$group = $this->createGroup();
$object = $this->createObject(['subtype' => 'blog']);  // subtype REQUIRED in 4.x
```

**IMPORTANT**: `$this->executeAction()` does NOT exist in `IntegrationTestCase` — it's only in `ActionResponseTestCase`. For integration tests, test entity behavior directly instead of through actions.

**Entity CRUD (4.x):**
```php
public function testEntityClassMapping(): void {
    $entity = $this->createObject(['subtype' => 'blog']);
    $loaded = get_entity($entity->guid);
    $this->assertInstanceOf(\ElggObject::class, $loaded);
    $this->assertEquals('blog', $loaded->getSubtype());
}
```

**Entity creation with metadata (4.x):**
```php
public function testEntityMetadataPersists(): void {
    $user = $this->createUser();
    $entity = new \ElggObject();
    $entity->setSubtype('mytype');
    $entity->owner_guid = $user->guid;
    $entity->container_guid = elgg_get_site_entity()->guid;
    $entity->access_id = ACCESS_PUBLIC;
    $entity->title = 'Test Entity';
    $entity->custom_field = 'custom_value';
    $this->assertTrue($entity->save() !== false);

    // Reload from DB to verify persistence
    _elgg_services()->entityCache->delete($entity->guid);
    $loaded = get_entity($entity->guid);
    $this->assertEquals('custom_value', $loaded->custom_field);
    $entity->delete();
}
```

**Permissions (4.x):**
```php
public function testNonOwnerCannotEdit(): void {
    $owner = $this->createUser();
    $other = $this->createUser();
    $post = $this->createObject(['subtype' => 'blog', 'owner_guid' => $owner->guid]);
    $this->assertTrue($post->canEdit($owner->guid));
    $this->assertFalse($post->canEdit($other->guid));
}
```

**Relationships (4.x):**
```php
public function testRelationshipCreated(): void {
    $user = $this->createUser();
    $entity = $this->createObject(['subtype' => 'blog']);
    $user->addRelationship($entity->guid, 'viewed');
    $this->assertTrue($user->hasRelationship($entity->guid, 'viewed'));
}
```

**Hook handler testing (4.x):**
```php
public function testHookModifiesValue(): void {
    $hook_called = false;
    $handler = function (\Elgg\Hook $hook) use (&$hook_called) {
        $hook_called = true;
        return $hook->getValue();
    };
    elgg_register_plugin_hook_handler('register', 'menu:test', $handler);
    elgg_trigger_plugin_hook('register', 'menu:test', [], []);
    $this->assertTrue($hook_called);
    elgg_unregister_plugin_hook_handler('register', 'menu:test', $handler);
}
```

**View rendering (4.x — integration tests only):**
```php
public function testViewRenders(): void {
    $output = elgg_view('my_plugin/my_view', ['key' => 'value']);
    $this->assertIsString($output);
    $this->assertNotEmpty($output);
}
```

**Plugin active skip workaround:**

IntegrationTestCase auto-skips tests if the plugin isn't active in the test DB. This frequently happens because the test DB (`c_i_elgg_` prefix) has separate plugin state. Two fixes:

```php
// Option 1: Override getPluginID to disable the check
public function getPluginID(): string {
    return ''; // empty string = skip the plugin-active check
}

// Option 2: Load plugin functions manually in up()
public function up() {
    $libFile = dirname(__DIR__, 5) . '/lib/functions.php';
    if (!function_exists('my_plugin_function')) {
        require_once $libFile;
    }
}
public function down() {}
```

### Phase 3.5: WRITE PLAYWRIGHT TESTS

Playwright tests verify UI features end-to-end against a running Elgg instance in Docker. They assert both **UI state** (elements visible, text content, navigation) and **database state** (entities created, metadata set, relationships formed).

#### Test structure

```
<plugin>/tests/
  playwright/
    playwright.config.ts
    package.json
    tests/
      <feature>.spec.ts
    helpers/
      elgg.ts           # Elgg-specific helpers (login, DB queries, etc.)
```

#### Playwright config

```typescript
// tests/playwright/playwright.config.ts
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  baseURL: process.env.ELGG_BASE_URL || `http://localhost:${process.env.ELGG_PORT || 8480}`,
  timeout: 30000,
  use: {
    ignoreHTTPSErrors: true,
  },
  // Sequential — tests may share DB state
  workers: 1,
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
```

**CRITICAL Playwright notes:**
- Default port is `8480` for Elgg 4.x Docker (not 8380 — that's Elgg 3.x)
- Use `workers: 1` — parallel workers cause DB race conditions with shared Elgg state
- DB port from host is typically mapped (e.g., `3307` for Elgg 4 Docker, not `3306`)
- Check `docker-compose.yml` for actual port mappings before writing helpers

#### Elgg helpers

```typescript
// tests/playwright/helpers/elgg.ts
import { Page, expect } from '@playwright/test';
import mysql from 'mysql2/promise';

// DB port is the HOST-MAPPED port from docker-compose.yml, NOT 3306
// Check: docker compose -f docker/elgg4/docker-compose.yml port db 3306
const DB_CONFIG = {
  host: process.env.ELGG_DB_HOST || 'localhost',
  port: Number(process.env.ELGG_DB_PORT || 3307),
  user: process.env.ELGG_DB_USER || 'elgg',
  password: process.env.ELGG_DB_PASS || 'elgg',
  database: process.env.ELGG_DB_NAME || 'elgg',
};

export async function loginAs(page: Page, username: string, password: string = 'testpass123') {
  await page.goto('/login');
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  await page.waitForURL(/\//);
}

export async function queryDb(sql: string, params: any[] = []) {
  const conn = await mysql.createConnection(DB_CONFIG);
  const [rows] = await conn.execute(sql, params);
  await conn.end();
  return rows;
}

export async function getEntity(guid: number) {
  return queryDb(
    'SELECT * FROM elgg_entities WHERE guid = ?', [guid]
  );
}

export async function getEntitiesBySubtype(subtype: string, ownerGuid?: number) {
  let sql = 'SELECT * FROM elgg_entities WHERE subtype = ?';
  const params: any[] = [subtype];
  if (ownerGuid) {
    sql += ' AND owner_guid = ?';
    params.push(ownerGuid);
  }
  return queryDb(sql, params);
}

export async function getMetadata(entityGuid: number, name: string) {
  return queryDb(
    'SELECT * FROM elgg_metadata WHERE entity_guid = ? AND name = ?',
    [entityGuid, name]
  );
}

export async function getRelationship(guid_one: number, relationship: string, guid_two: number) {
  return queryDb(
    'SELECT * FROM elgg_entity_relationships WHERE guid_one = ? AND relationship = ? AND guid_two = ?',
    [guid_one, relationship, guid_two]
  );
}
```

#### Test patterns

**Form submission — assert UI + database:**
```typescript
import { test, expect } from '@playwright/test';
import { loginAs, getEntitiesBySubtype, getMetadata } from '../helpers/elgg';

test.describe('Blog plugin', () => {
  test('create blog post via form', async ({ page }) => {
    await loginAs(page, 'testuser');
    await page.goto('/blog/add');

    // Fill form
    await page.fill('input[name="title"]', 'Test Blog Post');
    await page.fill('textarea[name="description"]', 'This is test content');
    await page.selectOption('select[name="status"]', 'published');
    await page.click('button[type="submit"]');

    // Assert UI: redirected to blog view
    await expect(page).toHaveURL(/\/blog\/view\//);
    await expect(page.locator('h1, .elgg-heading-main')).toContainText('Test Blog Post');

    // Assert database: entity created with correct metadata
    const entities = await getEntitiesBySubtype('blog');
    const blog = entities[entities.length - 1];
    expect(blog).toBeTruthy();
    expect(blog.type).toBe('object');

    const status = await getMetadata(blog.guid, 'status');
    expect(status[0]?.value).toBe('published');
  });
});
```

**Listing page — assert items render:**
```typescript
test('blog listing shows posts', async ({ page }) => {
  await loginAs(page, 'testuser');
  await page.goto('/blog/all');

  // Assert UI: list renders with items
  await expect(page.locator('.elgg-list')).toBeVisible();
  const items = page.locator('.elgg-list > .elgg-item');
  await expect(items).toHaveCount.greaterThan(0);

  // Assert pagination if enough items
  const pagination = page.locator('.elgg-pagination');
  // (only assert if expected)
});
```

**Permissions — test as different users:**
```typescript
test('non-owner cannot edit post', async ({ page }) => {
  // Login as owner, create post, get URL
  await loginAs(page, 'owner_user');
  await page.goto('/blog/add');
  await page.fill('input[name="title"]', 'Owner Only Post');
  await page.fill('textarea[name="description"]', 'Content');
  await page.click('button[type="submit"]');
  const postUrl = page.url();
  const editUrl = postUrl.replace('/view/', '/edit/');

  // Login as different user, try to access edit page
  await loginAs(page, 'other_user');
  const response = await page.goto(editUrl);

  // Assert: forbidden or redirected
  expect([403, 302]).toContain(response?.status() ?? 0);
});
```

**AJAX interactions — assert UI update + DB:**
```typescript
test('like button updates UI and database', async ({ page }) => {
  await loginAs(page, 'testuser');
  await page.goto('/blog/all');

  // Click like on first item
  const likeButton = page.locator('.elgg-item').first().locator('.elgg-button-like');
  await likeButton.click();

  // Assert UI: button state changed
  await expect(likeButton).toHaveClass(/elgg-state-active/);

  // Assert database: annotation created
  // (get entity guid from DOM data attribute or URL)
});
```

**Admin pages — assert render:**
```typescript
test('admin settings page renders', async ({ page }) => {
  await loginAs(page, 'admin');
  await page.goto('/admin/plugin_settings/<plugin-id>');

  // Assert: page renders without error
  await expect(page.locator('.elgg-form-settings')).toBeVisible();
  await expect(page.locator('.elgg-system-messages .elgg-message-error')).toHaveCount(0);
});
```

### Phase 4: RUN AND VERIFY

#### PHPUnit setup checklist (one-time per Docker env)

Before running PHPUnit for the first time in a Docker environment:

```bash
# 1. Install PHPUnit (not included in Elgg Docker images)
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  composer require --dev phpunit/phpunit:^9.6 --no-interaction

# 2. Create test DB tables (IntegrationTestCase uses c_i_elgg_ prefix)
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg php -r "
\$pdo = new PDO('mysql:host=db;dbname=elgg', 'elgg', 'elgg');
\$tables = \$pdo->query(\"SHOW TABLES LIKE 'elgg_%'\")->fetchAll(PDO::FETCH_COLUMN);
foreach (\$tables as \$t) {
    \$new = str_replace('elgg_', 'c_i_elgg_', \$t);
    \$pdo->exec(\"DROP TABLE IF EXISTS \$new\");
    \$r = \$pdo->query(\"SHOW CREATE TABLE \$t\")->fetch(PDO::FETCH_ASSOC);
    \$pdo->exec(str_replace(\$t, \$new, \$r['Create Table']));
}
foreach (['config','entities','metadata','private_settings','entity_relationships'] as \$t) {
    \$pdo->exec(\"INSERT INTO c_i_elgg_\$t SELECT * FROM elgg_\$t\");
}
echo 'Done.' . PHP_EOL;
"

# 3. Deactivate plugins with unmigrated hook signatures (they crash tests)
#    Common offender: images_ui (old 4-arg hook callbacks)
```

#### Running tests

```bash
# PHPUnit — in Docker
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin>/tests/phpunit.xml --no-coverage

# Playwright — from host (needs browser + network access to Docker)
cd <plugin>/tests/playwright
npm install
ELGG_PORT=${ELGG_PORT} ELGG_DB_PORT=${DB_PORT} npx playwright test
```

#### After activating/deactivating plugins, refresh test data:

```bash
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg php -r "
\$pdo = new PDO('mysql:host=db;dbname=elgg', 'elgg', 'elgg');
foreach (['entities','metadata','private_settings','entity_relationships','config'] as \$t) {
    \$pdo->exec(\"TRUNCATE TABLE c_i_elgg_\$t\");
    \$pdo->exec(\"INSERT INTO c_i_elgg_\$t SELECT * FROM elgg_\$t\");
}
echo 'Refreshed.' . PHP_EOL;
"
```

### Coverage checklist

**PHPUnit (backend):**
- [ ] Entity class mapped correctly
- [ ] Entity CRUD (create, read, update, delete)
- [ ] Each action has at least one test
- [ ] Each hook/event handler tested
- [ ] Key views render without errors
- [ ] Routes registered and resolve
- [ ] Permissions enforced

**Playwright (UI + database):**
- [ ] Each user-facing form: fill, submit, assert UI response + DB entity created
- [ ] Each listing page: navigate, assert items render
- [ ] Permissions: owner vs non-owner access
- [ ] AJAX interactions: trigger, assert UI update + DB state
- [ ] Admin pages: navigate, assert render without errors
- [ ] Modals/widgets: trigger, assert visible and functional

---

## Version-Specific Notes

### Elgg 3.x
- Plugin boots via `start.php` — tests may need manual boot (see template below)
- `elgg_get_session()->setLoggedInUser($user)` for session
- `_elgg_services()->hooks` for hook service

### Elgg 4.x
- Plugin boots via `elgg-plugin.php` — test framework handles activation
- **Session API**: `elgg_get_session()->setLoggedInUser($user)` — same as 3.x
- `_elgg_services()->session_manager` does NOT exist in 4.x — that's 5.x+
- `_elgg_services()->hooks` for hook service
- No closures in elgg-plugin.php (use class callbacks)
- `canWriteToContainer()` requires `($uid, $type, $subtype)`
- IntegrationTestCase uses DB prefix `c_i_elgg_` — must create test tables first

### Elgg 5.x+
- `_elgg_services()->session_manager->setLoggedInUser($user)` for session
- `_elgg_services()->events` — hooks and events unified into events
- `\Elgg\Event` replaces `\Elgg\Hook`

---

## File Templates

### bootstrap.php (3.x and 4.x — SAME bootstrap works for both)

**CRITICAL**: The path from `tests/` to the Elgg root is always 3 levels up: `tests/` → `mod/plugin/` → `mod/` → `elgg_root/`. Use `dirname(__DIR__, 3)` or `dirname(dirname(dirname(__DIR__)))`.

```php
<?php
/**
 * PHPUnit bootstrap for Elgg plugin tests.
 * Plugin must be installed at {elgg_root}/mod/{plugin_id}/
 */

// tests/ -> mod/plugin/ -> mod/ -> elgg_root/
$elggRoot = dirname(dirname(dirname(__DIR__)));

require_once $elggRoot . '/vendor/autoload.php';

// Load Elgg test classes (UnitTestCase, IntegrationTestCase, etc.)
$testClassesDir = $elggRoot . '/vendor/elgg/elgg/engine/tests/classes';
spl_autoload_register(function ($class) use ($testClassesDir) {
    $file = $testClassesDir . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) require_once $file;
});

// Load plugin autoloader if present
$pluginRoot = dirname(__DIR__);
if (file_exists($pluginRoot . '/vendor/autoload.php')) {
    require_once $pluginRoot . '/vendor/autoload.php';
} elseif (file_exists($pluginRoot . '/autoloader.php')) {
    require_once $pluginRoot . '/autoloader.php';
}

\Elgg\Application::loadCore();
```

**DO NOT** use `dirname(__DIR__, 4)` — that goes one level too high.
**DO NOT** try to locate `engine/tests/phpunit/bootstrap.php` — load autoloader + test classes + `loadCore()` directly.

### phpunit.xml (3.x/4.x)

**CRITICAL**: Only include `<directory>` entries for test suite directories that EXIST. PHPUnit errors if a directory is missing. If the plugin only has integration tests, omit the unit suite.

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
        <!-- ONLY include suites whose directories exist -->
        <testsuite name="integration"><directory>phpunit/integration</directory></testsuite>
        <!-- <testsuite name="unit"><directory>phpunit/unit</directory></testsuite> -->
    </testsuites>
</phpunit>
```

### PHPUnit must be installed in Elgg's vendor

The Elgg Docker images do NOT include PHPUnit by default. Before running tests:

```bash
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  composer require --dev phpunit/phpunit:^9.6 --no-interaction
```

Use PHPUnit 9.x for PHP 7.4 (Elgg 3.x/4.x), PHPUnit 10.x for PHP 8.1+ (Elgg 5.x+).

### Test database setup (REQUIRED for IntegrationTestCase)

Elgg's `IntegrationTestCase` uses a separate DB prefix (`c_i_elgg_`) for test isolation. These tables must exist before integration tests can run. Create them by cloning the production schema:

```bash
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg php -r "
\$pdo = new PDO('mysql:host=db;dbname=elgg', 'elgg', 'elgg');
\$stmt = \$pdo->query(\"SHOW TABLES LIKE 'elgg_%'\");
\$tables = \$stmt->fetchAll(PDO::FETCH_COLUMN);
foreach (\$tables as \$table) {
    \$newTable = str_replace('elgg_', 'c_i_elgg_', \$table);
    \$pdo->exec(\"DROP TABLE IF EXISTS \$newTable\");
    \$row = \$pdo->query(\"SHOW CREATE TABLE \$table\")->fetch(PDO::FETCH_ASSOC);
    \$pdo->exec(str_replace(\$table, \$newTable, \$row['Create Table']));
}
\$pdo->exec('INSERT INTO c_i_elgg_config SELECT * FROM elgg_config');
echo 'Test tables created.' . PHP_EOL;
"
```

**CRITICAL**: You must also copy entity/metadata/relationship data so plugins are recognized in the test environment:

```bash
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg php -r "
\$pdo = new PDO('mysql:host=db;dbname=elgg', 'elgg', 'elgg');
foreach (['entities','metadata','private_settings','entity_relationships','config'] as \$t) {
    \$pdo->exec(\"TRUNCATE TABLE c_i_elgg_\$t\");
    \$pdo->exec(\"INSERT INTO c_i_elgg_\$t SELECT * FROM elgg_\$t\");
}
echo 'Test data refreshed.' . PHP_EOL;
"
```

**Re-run this refresh** after activating/deactivating plugins or changing plugin settings. The test DB is a snapshot — it doesn't auto-sync with the production prefix.

### Unit tests vs Integration tests

**Unit tests** (`\Elgg\UnitTestCase`):
- Do NOT boot the full Elgg app — no database, no plugins loaded
- `elgg_view_exists()` returns false for plugin views (view system not initialized)
- Use for testing pure PHP logic (string manipulation, data transforms, etc.)
- Do NOT test view existence, hook registration, or entity operations in unit tests

**Integration tests** (`\Elgg\IntegrationTestCase`):
- Boot the full Elgg app with database
- Plugins are loaded and activated
- `elgg_view_exists()`, `elgg_trigger_plugin_hook()`, entity CRUD all work
- Require database connection (Docker)
- Use `$this->createUser()`, `$this->createObject()` — auto-cleaned after test

**Rule of thumb**: If your test needs Elgg functions, it's an integration test. Most plugin tests are integration tests.

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

### package.json (Playwright)
```json
{
  "private": true,
  "scripts": {
    "test": "playwright test",
    "test:headed": "playwright test --headed",
    "test:debug": "playwright test --debug"
  },
  "devDependencies": {
    "@playwright/test": "^1.40.0",
    "mysql2": "^3.6.0"
  }
}
```

### Test directory structure (complete)
```
<plugin>/tests/
  bootstrap.php              # PHPUnit bootstrap
  phpunit.xml                # PHPUnit config
  phpunit/
    unit/<Namespace>/        # Unit tests (no DB)
    integration/<Namespace>/ # Integration tests (needs DB)
  playwright/
    package.json             # Playwright deps
    playwright.config.ts     # Playwright config
    helpers/
      elgg.ts                # loginAs(), queryDb(), getEntity(), etc.
    tests/
      <feature>.spec.ts      # One file per feature area
```

---

## Common Mistakes

| Mistake | Fix |
|---------|-----|
| Using `_elgg_services()->session_manager` in 4.x tests | `session_manager` is 5.x+ only — use `elgg_get_session()->setLoggedInUser()` in 3.x/4.x |
| Running integration tests without Docker | Integration tests need database — use Docker |
| Not cleaning up entities | Use `$this->createObject()` — auto-cleaned by Seeding trait |
| Testing implementation details | Test behavior: "entity saved" not "SQL query ran" |
| Missing `canWriteToContainer` args in 4.x | Always pass `($uid, $type, $subtype)` |
| Playwright tests only assert UI | MUST also query database to verify side effects — UI can lie |
| Hardcoded ports in Playwright tests | Use `ELGG_PORT` env var — Docker ports differ per Elgg version |
| Playwright tests not cleaning up test data | Create unique test data per run, or use DB transactions/cleanup |
| Playwright tests skip login | Most Elgg pages require auth — always `loginAs()` first |
| No DB assertion after form submit | Form could "succeed" (200) without actually saving — always verify DB |
| Wrong bootstrap path: `dirname(__DIR__, 4)` | Use `dirname(__DIR__, 3)` — tests/ → plugin/ → mod/ → elgg_root/ (3 levels) |
| Using `elgg_view_exists()` in UnitTestCase | View system not initialized in unit tests — move to IntegrationTestCase |
| phpunit.xml references missing directory | Only include `<directory>` for suites that exist — PHPUnit errors on missing dirs |
| PHPUnit not installed in Docker | Run `composer require --dev phpunit/phpunit:^9.6` in container first |
| PHPUnit version mismatch | PHP 7.4 = PHPUnit 9.x, PHP 8.1+ = PHPUnit 10.x |
| Bootstrap loads `engine/tests/phpunit/bootstrap.php` | Don't search for Elgg's bootstrap — load autoloader + test classes + `loadCore()` directly |
| Elgg 4 rejects plugin with `start.php` | 3.x plugins with start.php can only be tested in Elgg 3 Docker, not Elgg 4 |
| `PluginBootstrap` missing `load()` method | Elgg 4.x requires `load()` — add empty `public function load() {}` to Bootstrap class |
| Namespaced Bootstrap calls `elgg_*()` without `\` | Must use `\elgg_*()` in namespaced code — PHP resolves to namespace otherwise |
| `session_manager` service used in Elgg 4.x tests | Elgg 4.x has `session` not `session_manager` — use `elgg_get_session()->setLoggedInUser()` in 4.x, `_elgg_services()->session_manager` is 5.x+ only |
| Integration tests need test DB tables | IntegrationTestCase uses `c_i_elgg_` prefix — tables must be created first (clone schema from `elgg_` tables) |
| All tests skipped: plugin not active | IntegrationTestCase auto-skips if `getPluginID()` returns a non-active plugin — make sure plugin is activated in Docker first |
| `$this->createUser(['username' => 'x'])` has random name | `createUser()` uses Faker for display name — assert on `username` or `guid`, not `display_name` |
| Search tests fail in isolation | Search hooks (`search:user`, `search:group`) require the search plugin — ensure it's active, or register test hooks |
| `$this->createObject()` needs `subtype` in 4.x | Always pass `['subtype' => '...']` — Elgg 4.x requires subtypes for entity creation |
| Using `$this->executeAction()` in IntegrationTestCase | `executeAction()` is only in `ActionResponseTestCase`, not `IntegrationTestCase` — test entity behavior directly |
| Other plugins' old-style hooks crash tests | Plugins with unmigrated 4-arg hook signatures (e.g., `images_ui`) crash during integration tests — deactivate them before running |
| Test DB not refreshed after plugin changes | After activating/deactivating plugins, re-copy data from `elgg_` → `c_i_elgg_` tables or tests will skip/fail |
| `$this->getAdmin()` returns null | Call `$this->createUser()` and `$user->makeAdmin()` instead — or check `$this->getAdmin()` result before using |
| Entity delete in test causes cascade crashes | Other active plugins' event handlers fire on delete — if a handler has wrong signature, it crashes. Deactivate problematic plugins. |
| `elgg_get_entities()` search by metadata title | Use `'metadata_name_value_pairs'` for metadata search, but `title` is an attribute, not metadata — query by owner_guid + subtype + sort instead |

---

## CI Setup

Copy `references/ci/elgg3-github-actions.yml` to `.github/workflows/tests.yml` and replace `PLUGIN_NAME`. The workflow starts MySQL, installs Elgg, activates the plugin, and runs PHPUnit.
