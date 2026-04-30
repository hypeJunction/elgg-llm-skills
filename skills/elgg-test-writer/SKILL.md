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
4. **RUN IN DOCKER** — ALL operations (PHPUnit, Playwright, npm) run inside containers. Nothing executes on the host.
5. **UI TESTS ARE MANDATORY** — Every plugin with user-facing features MUST have Playwright tests that assert both UI state and database state.

---

## Skill layout (templates, not live infra)

This skill ships **templates** that get copied into each plugin
repository. It does not run any shared Docker stack of its own, and it
does not depend on the elgg-migrate skill for infrastructure. After
`npx skills add`:

    <skill-dir>/
      SKILL.md                  # this file
      bin/migrate.php           # AST migration engine CLI
      bin/scaffold-docker.sh    # copy docker/ into a plugin
      bin/scaffold-ci.sh        # copy .github/workflows/ into a plugin
      src/                      # ElggMigrate\ PHP namespace
      rules/{2..6}x-to-{3..7}x/ # per-version rule manifests
      composer.json             # nikic/php-parser dep + PSR-4 autoload
      phpunit.xml               # test runner config
      tests/                    # PHPUnit tests for src/
      formulas/                 # plugin-test-scaffold beads formula
      templates/elgg{N}/        # per-target Elgg test stack (N = 2..7)
      templates/DEVELOPMENT.md  # plugin-level testing docs template
      references/ci/            # GitHub Actions workflow templates

Each `templates/elgg{N}/` directory holds a self-contained docker stack
— `Dockerfile`, `docker-compose.yml`, `elgg-install.sh`,
`elgg-composer.json`, `index.php`, `.env.example` — that the skill
copies into the plugin under test at `<plugin>/docker/`. Every plugin
ends up with its own isolated stack (own containers, volumes, network,
and ports scoped to `${PLUGIN_ID}-elgg{N}`); nothing is shared between
plugins.

Resolve `$SKILL` once at session start as the absolute path of the
directory containing this SKILL.md, and `$SKILL_TEMPLATES` as
`$SKILL/templates`. Every docker command in this skill is run **from
the plugin root** and references `docker/docker-compose.yml` relative
to that root — never a path inside the skill directory.

### Phase 0: Scaffold the plugin's docker stack

Before any test work, run the bootstrap script to copy the per-plugin
docker stack into the plugin repository. The script is deterministic —
no prompts, no LLM inference. It resolves `PLUGIN_ID` from
`composer.json`, `manifest.xml`, or the directory name, infers the Elgg
major version from the `elgg/elgg` composer constraint, and writes
every file from `templates/<elggN>/` into `<plugin>/docker/`.

```bash
# From inside the plugin directory:
$SKILL/bin/scaffold-docker.sh

# Or from anywhere:
$SKILL/bin/scaffold-docker.sh --plugin-dir=/abs/path/to/plugin

# Pin the version explicitly if composer.json is ambiguous:
$SKILL/bin/scaffold-docker.sh --elgg-version=elgg4
```

The script writes:

    <plugin>/docker/Dockerfile
    <plugin>/docker/docker-compose.yml
    <plugin>/docker/elgg-install.sh       (chmod +x)
    <plugin>/docker/elgg-composer.json
    <plugin>/docker/index.php
    <plugin>/docker/.env.example
    <plugin>/docker/.env                  (PLUGIN_ID filled in)
    <plugin>/DEVELOPMENT.md               (if missing)
    <plugin>/.gitignore                   (appends docker/.env)

Existing files are left alone unless `--force` is passed. After the
scaffold runs, every subsequent command — `docker compose -f
docker/docker-compose.yml ...` — touches only files inside the plugin
repo and never reaches into the skill directory.

## Container Infrastructure

All test operations run inside Docker containers.

| Service | Purpose | Compose file |
|---------|---------|-------------|
| `elgg` | PHPUnit integration tests, Elgg bootstrap | `docker/docker-compose.yml` |
| `node` | Playwright browser tests, npm operations | `docker/docker-compose.yml` (profile: test) |
| `db` | MySQL database (shared by elgg + node) | `docker/docker-compose.yml` |

### Debugging inside containers

```bash
# PHP/Apache error log (most common: fatal errors, undefined methods)
docker compose -f docker/docker-compose.yml exec elgg tail -f /var/log/apache2/error.log

# Elgg container logs (startup, plugin activation)
docker compose -f docker/docker-compose.yml logs elgg

# Interactive shell in Elgg container (inspect files, run ad-hoc PHP)
docker compose -f docker/docker-compose.yml exec elgg bash

# Check which plugins are active
docker compose -f docker/docker-compose.yml exec elgg php -r "
  require 'vendor/autoload.php';
  \$app = \Elgg\Application::getInstance(); \$app->bootCore();
  foreach (elgg_get_plugins('active') as \$p) echo \$p->getID() . PHP_EOL;
"

# MySQL interactive query
docker compose -f docker/docker-compose.yml exec db mysql -uelgg -pelgg elgg

# Check test DB tables exist (IntegrationTestCase)
docker compose -f docker/docker-compose.yml exec db mysql -uelgg -pelgg elgg \
  -e "SHOW TABLES LIKE 'c_i_elgg_%'"

# Node/Playwright container — debug browser tests
docker compose -f docker/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugin/tests/playwright && npm ci && npx playwright test --debug"

# Rebuild after Dockerfile changes
docker compose -f docker/docker-compose.yml build --no-cache
```

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

// In Docker: ELGG_BASE_URL=http://elgg (container networking)
// On host (not recommended): ELGG_BASE_URL=http://localhost:8480
export default defineConfig({
  testDir: './tests',
  baseURL: process.env.ELGG_BASE_URL || 'http://elgg',
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
- Tests run inside the `node` Docker service, NOT on the host machine
- Default base URL is `http://elgg` (Docker container networking)
- DB host is `db` on port `3306` (internal Docker networking, not host-mapped ports)
- Use `workers: 1` — parallel workers cause DB race conditions with shared Elgg state
- Environment variables (`ELGG_BASE_URL`, `ELGG_DB_HOST`, etc.) are set in docker-compose.yml

#### Elgg helpers

```typescript
// tests/playwright/helpers/elgg.ts
import { Page, expect } from '@playwright/test';
import mysql from 'mysql2/promise';

// In Docker: node container connects to db service directly on port 3306
// Environment variables are set in docker-compose.yml node service
const DB_CONFIG = {
  host: process.env.ELGG_DB_HOST || 'db',
  port: Number(process.env.ELGG_DB_PORT || 3306),
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
docker compose -f docker/docker-compose.yml exec elgg \
  composer require --dev phpunit/phpunit:^9.6 --no-interaction

# 2. Create test DB tables (IntegrationTestCase uses c_i_elgg_ prefix)
docker compose -f docker/docker-compose.yml exec elgg php -r "
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
# PHPUnit — in Elgg container
docker compose -f docker/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin>/tests/phpunit.xml --no-coverage

# Playwright — in node container (shares Docker network with elgg + db)
docker compose -f docker/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugin/tests/playwright && npm ci && npx playwright test"
```

#### After activating/deactivating plugins, refresh test data:

```bash
docker compose -f docker/docker-compose.yml exec elgg php -r "
\$pdo = new PDO('mysql:host=db;dbname=elgg', 'elgg', 'elgg');
foreach (['entities','metadata','private_settings','entity_relationships','config'] as \$t) {
    \$pdo->exec(\"TRUNCATE TABLE c_i_elgg_\$t\");
    \$pdo->exec(\"INSERT INTO c_i_elgg_\$t SELECT * FROM elgg_\$t\");
}
echo 'Refreshed.' . PHP_EOL;
"
```

### Behavior coverage rubric (read before the checklist)

The coverage checklist below lists *what to test*. This rubric is about
*whether your tests would actually catch a regression* — which is the point
of pre-migration tests and the part that's easy to skip.

A test suite can have high line coverage, pass the checklist, and still
miss the migration breaking the plugin. That happens when tests exercise
the code without actually asserting on the user-visible behavior that
would regress. The rubric below is a set of questions to ask about every
test before calling coverage "done."

**For every user-visible feature, ask:**

1. *If a migration silently removed this feature, would any test fail?*
   If the answer is "maybe" or "the function would still run," the test
   doesn't cover the behavior — it covers the code path. Assert on the
   observable outcome (DOM change, DB row, HTTP status, response body),
   not on whether a function was called.

2. *If a migration changed the route for this feature, would any test
   fail?* If the test hardcodes a route it's likely to survive a
   breaking change that moved the route. Good Playwright tests click
   links rather than typing URLs directly; when URLs are necessary, use
   `elgg_generate_url()` so the test follows the plugin's real route
   definition.

3. *If a migration changed the permission model, would any test fail?*
   A permission test that only runs as the owner tests the happy path,
   not the permission. Include a negative case (non-owner attempt, 403
   expected).

4. *If a migration introduced a subtle data-type change (int → string,
   null → empty string), would any test fail?* Brittle strict-equality
   assertions sometimes catch these; loose assertions ("truthy") miss
   them. Prefer typed assertions on the fields the migration might
   touch.

5. *If the background queue / cron / async work stopped running after
   migration, would any test fail?* Features that depend on async
   processing (notifications, search indexing, file processing) need
   tests that actually run the queue, not just enqueue the job.

**For every hook/event registration, ask:**

- Does the test *trigger* the event that the handler listens on, or
  does it just call the handler directly? A test that calls the handler
  directly doesn't catch "migration forgot to register the handler."
- Does the test assert on the effect of the handler running, or just
  that the handler returned without error?

**For every action, ask:**

- Does the test POST through the real action dispatcher (which runs
  CSRF, input validation, and the full action lifecycle), or does it
  invoke the action file directly? The latter misses CSRF, routing,
  and middleware regressions.

**When you can honestly answer "yes, a regression would fail a test"
for every feature, the coverage is real.** If you can't, the gap is the
test to write next.

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
- **Namespaced constants in test bootstrap**: if a plugin lib file (`lib/functions.php`) uses a namespaced constant (e.g. `hypeJunction\Geo\PLUGIN_ID`), define it in the test bootstrap BEFORE calling `\Elgg\Application::loadCore()`. Use the lowercase plugin ID value — 4.x normalizes all plugin IDs to lowercase. If the Bootstrap defines the constant in `load()` or `init()`, the test bootstrap must replicate it.
- **Undefined `$type`/`$return`/`$params` in migrated hook handlers**: the signature rewrite rule converts `($hook, $type, $return, $params)` → `(\Elgg\Hook $hook)` but does NOT fix the handler body. Tests that invoke these handlers will fail with "Undefined variable" PHP notices/fatals. Grep the plugin's classes for bare `$type`, `$return`, `$params` after migration and replace with `$hook->getType()`, `$hook->getValue()`, `$hook->getParam('key')`.
- **`register/menu:*` hook return value is a `MenuItems` collection**: `$hook->getValue()` returns an `Elgg\Collections\Collection`, not a plain array. `array_merge($return, $items)` will throw. Use `$return->merge($items)` instead.

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
docker compose -f docker/docker-compose.yml exec elgg \
  composer require --dev phpunit/phpunit:^9.6 --no-interaction
```

Use PHPUnit 9.x for PHP 7.4 (Elgg 3.x/4.x), PHPUnit 10.x for PHP 8.1+ (Elgg 5.x+).

### Test database setup (REQUIRED for IntegrationTestCase)

Elgg's `IntegrationTestCase` uses a separate DB prefix (`c_i_elgg_`) for test isolation. These tables must exist before integration tests can run. Create them by cloning the production schema:

```bash
docker compose -f docker/docker-compose.yml exec elgg php -r "
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
docker compose -f docker/docker-compose.yml exec elgg php -r "
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
| Hardcoded ports in Playwright tests | Use `ELGG_BASE_URL` env var — in Docker, base URL is `http://elgg` |
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
| Entity delete in test causes cascade crashes | Other active plugins' event handlers fire on delete — if a handler has wrong signature, it crashes. Deactivate problematic plugins, OR patch the plugin handler to accept both legacy `($event, $type, $entity)` and Elgg 4 `(\Elgg\Event $event)` signatures polymorphically (see below). |
| `elgg_get_entities()` search by metadata title | Use `'metadata_name_value_pairs'` for metadata search, but `title` is an attribute, not metadata — query by owner_guid + subtype + sort instead |
| `new \Elgg\Hook(...)` fails with "Cannot instantiate interface" | `\Elgg\Hook` is an INTERFACE. Mock it: `$hook = $this->getMockBuilder(\Elgg\Hook::class)->getMock();` then stub with `$hook->method('getValue')->willReturn(...)`, `getEntityParam`, `getParam`, `getParams`, `getName`, `getType`. Do NOT use `->onlyMethods([...])` on an interface — that breaks PHPUnit's abstract method generation. Interface mocks auto-stub all methods. |
| `use Elgg\HooksRegistrationService\Hook;` in tests | Import the INTERFACE `use Elgg\Hook;`, not the concrete internal class. PHPUnit's mock of the internal class requires constructor args that the mock generator can't supply, causing `Too few arguments` errors. |
| `elgg_set_plugin_setting()` undefined | Removed in Elgg 4. Use `elgg_get_plugin_from_id('plugin_id')->setSetting($name, $value)`. Same for `elgg_get_plugin_setting()` (use `->getSetting()`). |
| `\elgg()->crypto` / `\elgg()->accounts` throws `DI\NotFoundException` | `\elgg()` returns the **public** container which does not expose `crypto` or most internal services. Use `_elgg_services()->crypto` for the internal container. `\elgg()->accounts` IS on the public container in 4.x — verify per-service. |
| Legacy event/hook handler cascade (`ArgumentCountError: Too few arguments to function foo(), 1 passed and exactly 3 expected`) | Another active plugin's event handler is registered with the old 3-arg `($event, $type, $entity)` or 4-arg `($hook, $type, $return, $params)` signature. Elgg 4 always passes 1 `\Elgg\Event` / `\Elgg\Hook` object. **Fix the offending plugin's handler** with a polymorphic guard: `function handler($event, $type = null, $entity = null) { if ($event instanceof \Elgg\Event) { $entity = $event->getObject(); } ... }`. This preserves backward compatibility and unblocks every downstream test. |
| Event test closures use 3-arg signature | Elgg 4 event handlers receive one `\Elgg\Event`. Write `$handler = function ($event) use (&$fired) { $fired = true; };` not `function ($event, $type, $entity)`. Argument-count errors on `\elgg_trigger_event()` are this. |
| Plugin class autoloader not registered when inactive | `IntegrationTestCase` does NOT auto-load a plugin's `classes/` dir unless the plugin is active in the test DB. If you can't activate, register PSR-4 manually in `tests/bootstrap.php`: `spl_autoload_register(function ($class) use ($pluginRoot) { $prefix = 'hypeJunction\\Plugin\\'; if (strncmp($class, $prefix, strlen($prefix)) !== 0) return; $file = $pluginRoot . '/classes/hypeJunction/Plugin/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; if (file_exists($file)) require_once $file; });` |
| `IntegrationTestCase` abstract `up()`/`down()` fatal | Every concrete test class MUST define both `public function up() {}` and `public function down() {}`, even if empty. Missing them yields "contains 2 abstract methods and must therefore be declared abstract" at class-load time. |
| Mock of abstract class with concrete methods fails `MethodCannotBeConfiguredException` | `->getMockForAbstractClass()` only stubs abstract methods by default. If you need to mock a concrete method on an abstract parent (e.g. `Field::getLabel()`), explicitly list it via `->onlyMethods(['getLabel', 'getType', ...])` on the mock builder. |
| PHP reserved-word class names crash autoload | Some ancient vendored libraries (e.g. `respect/validation ~0.9`) ship files like `Rules/String.php` that are unparseable on PHP 7+. Detect with `PHP_VERSION_ID >= 70000` in `up()` and `$this->markTestSkipped(...)` with a link to the blocking bead rather than fighting the autoloader. |
| `*/` inside PHPDoc block comments | Closes the docblock early → fatal parse error. Avoid regex, URL paths, or code patterns that contain `*/` inside `/** ... */` comments. Use `\*\/` descriptions or inline backticks. |
| `assertTrue($plugin->isActive())` fails on Elgg 2.x | In Elgg 2.x, `\ElggPlugin::isActive()` returns the `active_plugin` `\ElggRelationship` object when active (truthy), `false` when not. Use `assertNotFalse()` not `assertTrue()`. From 3.x onward, it returns a real bool. |
| Plugin `isActive()` returns false in test even after `activate()` | In Elgg 3.x, `_elgg_services()->plugins->generateEntities()` (commonly called in test bootstraps to register plugins from disk) can leave a freshly-registered plugin entity in `enabled=''` (blank) state. Subsequent `activate()` returns `false` silently and `getError()` is empty. Force the state in your test bootstrap: `if (!$p->isEnabled()) $p->enable(); if (!$p->isActive()) $p->activate();`. If `enable()` doesn't stick, fall back to a direct `UPDATE elgg_entities SET enabled='yes' WHERE guid=?` once during environment setup. |
| Test looks up `elgg_get_plugin_from_id('hypeFoo')` and gets null | Elgg 3.x normalizes plugin id to lowercase, so a plugin at `mod/hypeFoo/` (camelCase, valid in 3.x) is registered as `hypefoo`. Tests that hardcode the camelCase id silently get `null`. Use the lowercase id, or write a case-tolerant lookup: `elgg_get_plugin_from_id('hypefoo') ?: elgg_get_plugin_from_id('hypeFoo')`. The 3→4 migration enforces lowercase dirs (Iron Law 6), eliminating the dual naming. |
| Smoke-test pattern for legacy 2.x plugins | When writing pre-migration tests for an old 2.x plugin (Elgg 1.x era code) that's about to go through 2→3, full behavior coverage is wasted work — the structure will change again in 3→4 and 4→5, and tests will be rewritten each time. Write **smoke tests** instead: assert the plugin loads (factory function exists, DI container instantiates, services resolve, hook handlers callable) — not behavior. Catches the regressions a structural transform actually introduces (autoload broken, namespace renamed, signature changed) without locking in tests for code that's about to be rewritten. Use plain `PHPUnit\Framework\TestCase` since 2.x has no `IntegrationTestCase`. |

---

## Phase 5: CI Setup

Once a plugin has the docker stack and a test suite, scaffold GitHub
Actions workflows so every push and PR runs the same checks the local
docker stack runs. Reference workflows live under
`references/ci/` and are copied verbatim — they resolve `PLUGIN_ID`
at runtime from `composer.json`, so no per-plugin substitution is
needed at scaffold time.

### Scaffold

```bash
# From inside the plugin directory:
$SKILL/bin/scaffold-ci.sh

# Or from anywhere:
$SKILL/bin/scaffold-ci.sh --plugin-dir=/abs/path/to/plugin

# Overwrite existing workflows:
$SKILL/bin/scaffold-ci.sh --force
```

The script writes:

    <plugin>/.github/workflows/tests.yml
    <plugin>/.github/workflows/lint.yml

### What the workflows do

| File | Jobs | Skips when |
|------|------|------------|
| `tests.yml` | `phpunit`, `playwright` | The plugin lacks `docker/docker-compose.yml`, `tests/phpunit.xml`, or `tests/playwright/package.json` (each job key is a `hashFiles()` guard). |
| `lint.yml` | `php-syntax` (matrix 7.4 / 8.1 / 8.3), `composer-validate`, `json`, `workflow-yaml` | Per-job `hashFiles()` guards — composer-validate skips when no `composer.json`, workflow-yaml skips when no `.github/workflows/*.yml`. |

The test workflow re-uses the per-plugin docker stack
(`docker compose up`) — the runner does not install PHP, MySQL, or
Elgg natively. Green CI = green local. There is no separate CI
install path to maintain.

### Customizing the workflows after scaffold

The reference templates pin choices that suit the typical Elgg 3.x/4.x
plugin. Change them in the plugin's copy when:

- The plugin targets **Elgg 5.x+** (PHP 8.1+) — bump
  `phpunit/phpunit:^9.6` to `^10.5` in the *Install PHPUnit* step.
- The plugin's `composer.json` `require.php` excludes one of the lint
  matrix versions — drop the row from `lint.yml`'s `php-version`
  matrix.
- The plugin uses release branches like `4.x` / `5.x` — add them to
  the `branches:` list under `on.push`.

### Debugging a CI failure

Failures upload diagnostics as artifacts:

- `phpunit-diagnostics/compose.log` — output of `docker compose logs`.
- `phpunit-diagnostics/apache-error.log` — Apache/PHP error log from
  the elgg container (where fatal errors land).
- `playwright-report/` — Playwright HTML report (always uploaded).
- `playwright-diagnostics/compose.log` — on Playwright failure.

Reproduce locally with the exact same commands the workflow uses:

```bash
PLUGIN_ID=<id> docker compose -f docker/docker-compose.yml up -d
docker compose -f docker/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/$PLUGIN_ID/tests/phpunit.xml --no-coverage

docker compose -f docker/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugin/tests/playwright && npm ci && npx playwright test"
```

See `references/ci/README.md` for the full design rationale.
