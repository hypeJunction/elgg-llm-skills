# Ideal Plugin Architecture by Elgg Version

This document defines the canonical directory structure and file conventions for Elgg plugins at each major version. Migrated plugins MUST follow the structure for their target version.

## Quick Reference Table

| File / Directory | 2.x | 3.x | 4.x | 5.x | 6.x |
|------------------|:---:|:---:|:---:|:---:|:---:|
| `start.php` | required | closure | **forbidden** | **forbidden** | **forbidden** |
| `manifest.xml` | required | required | **forbidden** | **forbidden** | **forbidden** |
| `elgg-plugin.php` | — | required | required | required | required |
| `composer.json` | optional | optional | required | required | required |
| `activate.php` | optional | optional | **forbidden** | **forbidden** | **forbidden** |
| `deactivate.php` | optional | optional | **forbidden** | **forbidden** | **forbidden** |
| `lib/plugin.php` | common | common | rare | rare | rare |
| `lib/functions.php` | — | rare | common | common | common |
| `classes/` (PSR-4) | rare | required | required | required | required |
| `Bootstrap.php` | — | — | optional | optional | optional |
| `views/default/` | required | required | required | required | required |
| `actions/` | required | required | required | required | required |
| `languages/` | required | required | required | required | required |
| `tests/phpunit/` | rare | optional | recommended | recommended | recommended |
| `tests/playwright/` | — | — | recommended | recommended | recommended |

## Elgg 2.x — Procedural Era

```
myplugin/
├── start.php                      # All init in myplugin_init() registered to system/init event
├── manifest.xml                   # Plugin metadata
├── activate.php                   # Activation logic
├── deactivate.php                 # Deactivation logic
├── lib/
│   └── plugin.php                 # Helper functions, loaded via elgg_register_library()
├── actions/
│   └── myplugin/
│       ├── save.php              # Procedural action with get_input(), forward()
│       └── delete.php
├── classes/                       # Flat, not namespaced
│   └── ElggMyPluginEntity.php
├── views/
│   └── default/
│       └── myplugin/
│           ├── view.php
│           └── edit.php
└── languages/
    └── en.php                     # return ['key' => 'value']
```

**Key conventions:**
- `start.php` registers `myplugin_init` handler at top level
- Page handlers via `elgg_register_page_handler('myplugin', 'myplugin_page_handler')`
- Hook callbacks use 4-arg signature: `function($hook, $type, $return, $params)`
- URLs are hardcoded strings: `"myplugin/owner/{$entity->username}"`
- Entity classes are flat (not namespaced): `ElggBlog`, `ElggMyPluginEntity`

## Elgg 3.x — Transitional Era (DUAL System)

```
myplugin/
├── start.php                      # REDUCED — returns a closure
├── elgg-plugin.php                # NEW — declarative routes, entities, actions
├── manifest.xml                   # Still required
├── composer.json                  # Optional but recommended for autoloading
├── lib/
│   └── plugin.php                 # Deprecated but still works
├── actions/
│   └── myplugin/
│       └── save.php
├── classes/                       # PSR-4 namespaced
│   └── MyPlugin/
│       ├── Hooks.php             # Hook callbacks (NEW: \Elgg\Hook type hint)
│       ├── Events.php            # Event callbacks
│       └── Entity.php            # Namespaced entity class
├── views/
│   └── default/
│       └── myplugin/
└── languages/
    └── en.php
```

**Key conventions:**
- `start.php` returns a closure: `return function() { /* registrations */ };`
- `elgg-plugin.php` contains declarative arrays for routes, entities, actions
- Hook callbacks use single-arg signature: `function(\Elgg\Hook $hook)`
- URL generation: `elgg_generate_url('collection:object:myplugin:owner', ['username' => $name])`
- Entity classes namespaced: `namespace MyPlugin; class Entity extends \ElggObject {}`
- Translation keys follow new convention: `'collection:object:myplugin'`, `'item:object:myplugin'`

**elgg-plugin.php (3.x format):**
```php
<?php
return [
    'routes' => [
        'collection:object:myplugin:all' => [
            'path' => '/myplugin/all',
            'resource' => 'myplugin/all',
        ],
    ],
    'entities' => [
        [
            'type' => 'object',
            'subtype' => 'myplugin',
            'class' => MyPlugin\Entity::class,
            'searchable' => true,
        ],
    ],
    'actions' => [
        'myplugin/save' => [],
    ],
];
```

## Elgg 4.x — Declarative Era

```
myplugin/
├── elgg-plugin.php                # SOLE config file
├── composer.json                  # REQUIRED — replaces manifest.xml
├── lib/
│   └── functions.php              # Loaded via require_once at top of elgg-plugin.php
├── actions/
│   └── myplugin/
│       └── save.php               # Returns elgg_ok_response() / elgg_error_response()
├── classes/
│   └── MyPlugin/
│       ├── Bootstrap.php          # Optional: imperative init logic
│       ├── Entity.php
│       ├── Menus/                 # Menu handlers split into dedicated classes
│       │   ├── Site.php
│       │   ├── OwnerBlock.php
│       │   └── Entity.php
│       ├── Notifications/         # Notification handlers as classes
│       │   └── PublishEventHandler.php
│       ├── Hooks/                 # Hook handlers (still using \Elgg\Hook)
│       │   └── Permissions.php
│       └── Events/                # Event handlers
│           └── EntityLifecycle.php
├── views/
│   └── default/
│       ├── myplugin/
│       └── resources/
│           └── myplugin/          # Resource views (replace page handlers)
├── languages/
└── tests/
    ├── phpunit/
    └── playwright/
```

**Key conventions:**
- NO `start.php`, NO `manifest.xml`, NO `activate.php`, NO `deactivate.php`
- `elgg-plugin.php` is the only configuration entry point
- Menu handlers MUST be split into namespaced classes (one class per menu, one method per item)
- Helper functions go in `lib/functions.php`, loaded via `require_once(__DIR__ . '/lib/functions.php')` at top of `elgg-plugin.php`
- Bootstrap class for imperative init: `\MyPlugin\Bootstrap::class`
- Activation logic moves to `Bootstrap::activate()` method
- Actions return response objects, not `forward()` or `register_error()`

**elgg-plugin.php (4.x format):**
```php
<?php
require_once(__DIR__ . '/lib/functions.php');

return [
    'plugin' => [
        'name' => 'My Plugin',
        'activate_on_install' => true,
    ],
    'bootstrap' => \MyPlugin\Bootstrap::class,
    'entities' => [
        [
            'type' => 'object',
            'subtype' => 'myplugin',
            'class' => \MyPlugin\Entity::class,
            'capabilities' => [
                'commentable' => true,
                'searchable' => true,
                'likable' => true,
            ],
        ],
    ],
    'routes' => [
        'collection:object:myplugin:all' => [
            'path' => '/myplugin/all',
            'resource' => 'myplugin/all',
        ],
    ],
    'actions' => [
        'myplugin/save' => [],
    ],
    'hooks' => [
        'register' => [
            'menu:entity' => [
                \MyPlugin\Menus\Entity::class . '::register' => [],
            ],
        ],
    ],
    'events' => [
        'create' => [
            'object' => [
                \MyPlugin\Events\EntityLifecycle::class . '::onCreate' => [],
            ],
        ],
    ],
    'group_tools' => [
        'myplugin' => [],
    ],
    'notifications' => [
        'object' => [
            'myplugin' => [
                'publish' => \MyPlugin\Notifications\PublishEventHandler::class,
            ],
        ],
    ],
];
```

**Bootstrap class pattern:**
```php
<?php
namespace MyPlugin;

use Elgg\DefaultPluginBootstrap;

class Bootstrap extends DefaultPluginBootstrap
{
    public function init(): void
    {
        // Conditional registrations, menu items, ajax views
    }

    public function activate(): void
    {
        // Schema setup, default data — replaces activate.php
    }

    public function deactivate(): void
    {
        // Cleanup — replaces deactivate.php
    }
}
```

## Elgg 5.x — Unified Events Era

Same structure as 4.x with these changes:

```
myplugin/
├── elgg-plugin.php                # 'hooks' key REMOVED, all under 'events'
├── classes/
│   └── MyPlugin/
│       ├── Events/                # Renamed from Hooks/ for clarity
│       │   └── Permissions.php   # Now uses \Elgg\Event type hint
│       └── Forms/                 # NEW: form preparation classes
│           └── PrepareFields.php
└── ...
```

**Key conventions:**
- `'hooks'` key is removed — everything goes under `'events'`
- `\Elgg\Hook` type hint is replaced by `\Elgg\Event`
- Handler classes often renamed: `Hooks/` → `Events/`
- Form preparation moves from `prepare_form_vars()` function to `PrepareFields` class with `'form:prepare:fields'` event
- Route middleware: `UserPageOwnerGatekeeper`, `PageOwnerGatekeeper`, `GroupPageOwnerGatekeeper`
- Private settings removed — migrated to metadata

**elgg-plugin.php (5.x changes):**
```php
return [
    'events' => [
        // What was 'hooks' in 4.x
        'register' => [
            'menu:entity' => [
                \MyPlugin\Events\EntityMenu::class . '::register' => [],
            ],
        ],
        // What was 'events' in 4.x
        'create' => [
            'object' => [
                \MyPlugin\Events\EntityLifecycle::class . '::onCreate' => [],
            ],
        ],
        // Form preparation
        'form:prepare:fields' => [
            'myplugin/save' => [
                \MyPlugin\Forms\PrepareFields::class => [],
            ],
        ],
    ],
    'routes' => [
        'collection:object:myplugin:owner' => [
            'path' => '/myplugin/owner/{username}',
            'resource' => 'myplugin/owner',
            'middleware' => [
                \Elgg\Router\Middleware\UserPageOwnerGatekeeper::class,
            ],
        ],
    ],
];
```

## Elgg 6.x — ES Modules Era

Same structure as 5.x with:

```
myplugin/
├── views/
│   └── default/
│       └── myplugin/
│           ├── module.js          # ES module syntax (import/export)
│           └── another.js         # Registered via elgg_register_esm()
└── ...
```

**Key conventions:**
- JS uses ES module syntax (`import/export`) instead of AMD `define()/require()`
- `elgg_register_esm()` replaces `elgg_define_js()`
- `elgg_import_esm()` replaces `elgg_require_js()`
- Entity capability `'restorable' => true` for trash/soft-delete support
- MySQL 8.0 minimum, PHP 8.1 minimum

**JS module (6.x):**
```javascript
// views/default/myplugin/module.js
import elgg from 'elgg';
import { Ajax } from 'elgg/Ajax';

export function initialize() {
    const ajax = new Ajax();
    elgg.register_hook_handler('ready', 'system', () => {
        // ...
    });
}
```

## Migration Compliance Checks

When migrating to a target version, the plugin MUST:

1. **Have all required files for the target version**
2. **NOT have any forbidden files for the target version**
3. **Use only APIs available in the target version** (enforced by `--verify`)
4. **Follow the directory structure conventions above**
5. **Use the correct hook/event/handler patterns**
6. **Have all classes properly namespaced under PSR-4**

The `VersionGuard` class detects the current version. The `PostMigrationVerifier` enforces forbidden file checks and API boundary checks.
