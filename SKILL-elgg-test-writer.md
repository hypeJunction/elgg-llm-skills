---
name: elgg-test-writer
description: >
  Use when writing PHPUnit tests for Elgg plugins. Generates test suites that
  verify plugin functionality across Elgg versions. Covers entity CRUD, hooks,
  actions, routes, views, and permissions. Adapts test patterns to the target
  Elgg version's testing API.
category: process
triggers:
  - elgg test
  - elgg plugin test
  - write elgg tests
  - test elgg plugin
user-invocable: true
---

# elgg-test-writer

> **Purpose:** Generate PHPUnit test suites for Elgg plugins, adapted to the target Elgg version.
> **Usage:** `/elgg-test-writer <plugin-path> [--elgg-version=3.x]`

## Iron Laws

1. **SCAN BEFORE WRITING** — Read every PHP file in the plugin first. Never write tests for functionality you haven't read.
2. **TEST BEHAVIOR, NOT IMPLEMENTATION** — Test what the plugin does, not how it does it.
3. **MATCH THE ELGG VERSION** — Use the correct test base classes and APIs for the target Elgg version.

---

## Phase 1: SCAN THE PLUGIN

Read all plugin files and build an inventory of testable features:

### What to catalog

| Category | Where to look | What to test |
|----------|--------------|-------------|
| Entity types | `start.php`, `elgg-plugin.php`, `activate.php` | CRUD lifecycle, class mapping |
| Actions | `actions/` directory, action registrations | Input validation, side effects, permissions |
| Routes | `elgg_register_route`, `elgg_register_page_handler` | URL resolution, response codes |
| Hooks/Events | `elgg_register_plugin_hook_handler`, `elgg_register_event_handler` | Handler execution, return values |
| Views | `views/` directory | Render without errors, expected output |
| Permissions | `container_permissions_check`, `permissions_check` hooks | Access control logic |
| Widgets | `elgg_register_widget_type` | Registration, content rendering |
| Menus | Menu hook handlers | Item registration, visibility |
| Notifications | Notification registrations | Event triggers, message formatting |

---

## Phase 2: SET UP TEST INFRASTRUCTURE

### Directory structure
```
<plugin>/
  tests/
    phpunit/
      unit/
        <PluginNamespace>/
          EntityTest.php
          ...
      integration/
        <PluginNamespace>/
          ActionsTest.php
          HooksTest.php
          ...
    phpunit.xml
```

### phpunit.xml template

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
         bootstrap="../../../vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>phpunit/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>phpunit/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

### Base classes by Elgg version

| Elgg Version | Unit Test Base | Integration Test Base | Notes |
|-------------|---------------|----------------------|-------|
| 2.x | `PHPUnit\Framework\TestCase` | Custom bootstrap | No built-in Elgg test support |
| 3.x | `\Elgg\UnitTestCase` | `\Elgg\IntegrationTestCase` | Introduced in 3.0 |
| 4.x+ | `\Elgg\UnitTestCase` | `\Elgg\IntegrationTestCase` | Same API, added type hints |

---

## Phase 3: WRITE TESTS

### Test patterns by category

#### Entity CRUD Tests (Integration)

```php
class PostTest extends \Elgg\IntegrationTestCase {

    public function testCanCreateEntity(): void {
        $entity = $this->createObject([
            'subtype' => Post::SUBTYPE,
            'title' => 'Test post',
        ]);

        $this->assertInstanceOf(Post::class, $entity);
        $this->assertEquals('Test post', $entity->getDisplayName());
    }

    public function testCanDeleteEntity(): void {
        $entity = $this->createObject(['subtype' => Post::SUBTYPE]);
        $guid = $entity->guid;

        $this->assertTrue($entity->delete());
        $this->assertNull(get_entity($guid));
    }

    public function testEntityClassMapping(): void {
        $entity = $this->createObject(['subtype' => Post::SUBTYPE]);
        $loaded = get_entity($entity->guid);

        $this->assertInstanceOf(Post::class, $loaded);
    }
}
```

#### Action Tests (Integration)

```php
class StatusActionTest extends \Elgg\IntegrationTestCase {

    public function testStatusActionRequiresLogin(): void {
        // Ensure not logged in
        _elgg_services()->session_manager->removeLoggedInUser();

        $response = $this->executeAction('wall/status', [
            'body' => 'Test status',
        ]);

        // Should redirect to login or return error
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function testStatusActionCreatesPost(): void {
        $user = $this->createUser();
        _elgg_services()->session_manager->setLoggedInUser($user);

        $response = $this->executeAction('wall/status', [
            'body' => 'Hello world',
            'container_guid' => $user->guid,
        ]);

        // Verify a wall post was created
        $posts = elgg_get_entities([
            'type' => 'object',
            'subtype' => Post::SUBTYPE,
            'owner_guid' => $user->guid,
        ]);

        $this->assertCount(1, $posts);
        $this->assertEquals('Hello world', $posts[0]->description);
    }
}
```

#### Hook/Event Tests (Integration)

```php
class HooksTest extends \Elgg\IntegrationTestCase {

    public function testEntityUrlHookReturnsCustomUrl(): void {
        $user = $this->createUser();
        $post = $this->createObject([
            'subtype' => Post::SUBTYPE,
            'owner_guid' => $user->guid,
            'container_guid' => $user->guid,
        ]);

        $url = $post->getURL();

        $this->assertStringContainsString('wall/', $url);
        $this->assertStringContainsString((string) $post->guid, $url);
    }

    public function testContainerPermissionsHook(): void {
        $user = $this->createUser();
        _elgg_services()->session_manager->setLoggedInUser($user);

        // User should be able to write to their own wall
        $can = $user->canWriteToContainer(0, 'object', Post::SUBTYPE);
        $this->assertTrue($can);
    }
}
```

#### View Tests (Integration)

```php
class ViewsTest extends \Elgg\IntegrationTestCase {

    public function testObjectViewRenders(): void {
        $user = $this->createUser();
        $post = $this->createObject([
            'subtype' => Post::SUBTYPE,
            'owner_guid' => $user->guid,
        ]);

        $output = elgg_view_entity($post);

        $this->assertNotEmpty($output);
        $this->assertStringContainsString($post->getDisplayName(), $output);
    }

    public function testFormViewRenders(): void {
        $user = $this->createUser();
        _elgg_services()->session_manager->setLoggedInUser($user);

        $output = elgg_view('forms/wall/status', [
            'entity' => null,
            'container_guid' => $user->guid,
        ]);

        $this->assertNotEmpty($output);
    }
}
```

#### Route Tests (Integration)

```php
class RoutesTest extends \Elgg\IntegrationTestCase {

    public function testWallRouteIsRegistered(): void {
        // Verify the route exists
        $route = _elgg_services()->routeCollection->get('wall');
        $this->assertNotNull($route);
    }

    public function testWallRouteGeneratesUrl(): void {
        $url = elgg_generate_url('wall', ['segments' => '']);
        $this->assertNotNull($url);
        $this->assertStringContainsString('/wall', $url);
    }
}
```

#### Widget Tests (Integration)

```php
class WidgetTest extends \Elgg\IntegrationTestCase {

    public function testWidgetTypeIsRegistered(): void {
        $widgets = elgg_get_widget_types();
        $this->assertArrayHasKey('wall', $widgets);
    }
}
```

#### Permission Tests (Integration)

```php
class PermissionsTest extends \Elgg\IntegrationTestCase {

    public function testOwnerCanEditPost(): void {
        $user = $this->createUser();
        _elgg_services()->session_manager->setLoggedInUser($user);

        $post = $this->createObject([
            'subtype' => Post::SUBTYPE,
            'owner_guid' => $user->guid,
        ]);

        $this->assertTrue($post->canEdit());
    }

    public function testNonOwnerCannotEditPost(): void {
        $owner = $this->createUser();
        $other = $this->createUser();

        $post = $this->createObject([
            'subtype' => Post::SUBTYPE,
            'owner_guid' => $owner->guid,
        ]);

        _elgg_services()->session_manager->setLoggedInUser($other);
        $this->assertFalse($post->canEdit());
    }
}
```

---

## Phase 4: RUN AND VERIFY

### Running locally (unit tests only)
```bash
vendor/bin/phpunit --configuration mod/<plugin>/tests/phpunit.xml --testsuite unit
```

### Running in Docker (full suite)
```bash
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin>/tests/phpunit.xml
```

### Coverage checklist

For each plugin feature, verify at minimum:
- [ ] Entity class is mapped correctly
- [ ] Entity CRUD works (create, read, update, delete)
- [ ] Each action has at least one test
- [ ] Each hook/event handler has at least one test
- [ ] Key views render without errors
- [ ] Routes are registered and resolve
- [ ] Permissions are enforced (owner can edit, non-owner cannot)
- [ ] Widgets are registered

---

## Elgg Version Differences

### Elgg 2.x Testing
- No built-in `UnitTestCase`/`IntegrationTestCase`
- Must bootstrap Elgg manually in tests
- Use raw `PHPUnit\Framework\TestCase`
- Limited: can't easily test hooks, views, routes without full bootstrap

### Elgg 3.x Testing
- `\Elgg\UnitTestCase` — isolated tests, no database
- `\Elgg\IntegrationTestCase` — full Elgg with database
- Helper methods: `createUser()`, `createGroup()`, `createObject()`
- Automatic cleanup via `Seeding` trait

### Elgg 4.x+ Testing
- Same API as 3.x with added type hints
- `executeAction()` helper for action testing
- PHPUnit 9.x (4.x) or 10.x (6.x)

### Key helper methods (3.x+)

```php
// Create test entities (auto-cleaned up)
$user = $this->createUser();
$group = $this->createGroup();
$object = $this->createObject(['subtype' => 'blog']);

// Set logged-in user
_elgg_services()->session_manager->setLoggedInUser($user);

// Execute an action
$response = $this->executeAction('action/name', ['param' => 'value']);

// Access services
_elgg_services()->routes;
_elgg_services()->hooks;
_elgg_services()->events;
```
