# elgg-plugin.php generation (3.x → 4.x)

How the `GenerateElggPluginPhp` rule extracts registrations from `start.php`
into the Elgg 4.x declarative config format. Always review the generated
file — the rule is imperfect and some registrations need manual intervention.

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

**Known rule bug — `switch ($event)` body usages.** The rule rewrites the parameter signature but does NOT touch the function body. Code like:

```php
// 3.x source
public static function onChange($event, $type, $entity) {
    switch ($event) {                       // ← was a string parameter
        case 'create': ...
        case 'update': ...
    }
}
```

becomes:

```php
// 4.x output, BROKEN
public static function onChange(\Elgg\Event $event) {
    switch ($event) {                       // ← NOW switching on the Event OBJECT
        case 'create': ...                  // never matches
    }
}
```

After running the rule, grep the rewritten files for `switch ($event` and `if ($event ===` and rewrite to `switch ($event->getName())` / `if ($event->getName() === ...)`.

## Custom plugin admin pages need explicit named routes (4.x)

In 3.x, an admin page at `views/default/admin/<plugin>/<page>.php` was reachable at `/admin/<plugin>/<page>` via Elgg's auto-discovery. In 4.x, **only built-in admin paths auto-discover** — custom plugin admin pages 404 unless you register them as named routes.

The pattern requires three pieces:

**1. Route registration in `elgg-plugin.php`:**

```php
'routes' => [
    'admin:hypeseo:rules' => [
        'path' => '/admin/seo/rules',
        'resource' => 'admin/seo/rules',
        'middleware' => [
            \Elgg\Router\Middleware\AdminGatekeeper::class,
        ],
    ],
    // ... one route per admin page
],
```

**2. Resource view shim** at `views/default/resources/admin/<plugin>/<page>.php` that wraps the existing admin view body in the standard admin layout:

```php
<?php
echo elgg_view_layout('admin', [
    'title' => elgg_echo('admin:hypeseo:rules'),
    'content' => elgg_view('admin/seo/rules', $vars),
    'filter' => false,
]);
```

**3. The existing admin view body** stays where it is at `views/default/admin/<plugin>/<page>.php`. The shim references it via `elgg_view('admin/<plugin>/<page>')`.

This pattern is NOT extracted by `GenerateElggPluginPhp` — the rule only converts top-level page handlers, not menu-item targets that point at admin URLs. After running the rule, audit any `elgg_register_menu_item` registrations whose `'href'` starts with `admin/<plugin>/...` and add the corresponding routes + shims.

The `AdminGatekeeper` middleware enforces admin-only access. Without it, the route would be reachable by any logged-in user.
