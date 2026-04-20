# Version-specific breaking changes and architecture patterns

Reference material for migrations — read the section matching the version step you're on.

## Version-Specific Breaking Changes

Details in `rules/{from}-to-{to}/manifest.json`. Key highlights:

**2.x → 3.x** (largest): metastrings removed, subtypes→strings, page handlers→routes, libraries→autoloading, ~50 functions removed, entity queries unified.

**3.x → 4.x** (structural): start.php→elgg-plugin.php+Bootstrap, `\DI\object()`→`\DI\create()`, `Zend\Mail`→`Laminas\Mail`, entity attribute setters changed, canWriteToContainer() requires type+subtype, `run_sql_script()` removed, `forward()` removed, JS `elgg.action/get/getJSON/post` → `elgg/Ajax` module, plugin dirs must match composer.json lowercase name, `elgg_register_entity_type()` → entities key in elgg-plugin.php. **AMD JS removals**: `elgg/init` removed — drop `require('elgg/init')` from all AMD modules. `elgg.echo()` removed from the `elgg` AMD module — require `elgg/i18n` and call `i18n.echo()`. `elgg.provide()` removed — replace `elgg.provide('elgg.ui.foo')` + deprecated_settings pattern with a plain `$.extend(settings, opts)`.

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
- PHP 8.2 minimum

### Migration Checklist by Version Step

**2.x → 3.x: Introduce declarative config alongside procedural code**
- [ ] Create `composer.json` from `manifest.xml` (`type: elgg-plugin`, `require: { php: ">=7.0", elgg/elgg: "^3.0", composer/installers: "~1.0" }`, translate plugin deps)
- [ ] Keep `manifest.xml` (3.x still reads it)
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
- [ ] Lowercase plugin directory name AND `composer.json` `name` field (must match exactly — Elgg 4+ requirement)
- [ ] Bump `composer.json`: `php >=7.4`, `elgg/elgg ^4.0`, `composer/installers ^2.0`, add `config.allow-plugins.composer/installers: true`
- [ ] Verify every `manifest.xml` plugin dependency is mirrored in `composer.json` `require`
- [ ] `composer validate --strict` passes inside the elgg4 container
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
- [ ] Bump `composer.json`: `php >=8.2`, `elgg/elgg ^5.0`; bump 3rd-party deps to PHP 8.2 compatible versions
- [ ] `composer update` and `composer validate --strict` pass inside the elgg5 container
- [ ] Rename `'hooks'` key → `'events'` key in elgg-plugin.php
- [ ] Change `\Elgg\Hook` → `\Elgg\Event` in ALL handler signatures
- [ ] Add route middleware: `UserPageOwnerGatekeeper`, `GroupPageOwnerGatekeeper`
- [ ] Convert `prepare_form_vars()` functions → `PrepareFields` event handler class
- [ ] Remove deprecated actions that core now handles
- [ ] Migrate private settings → metadata
- [ ] Consider renaming handler classes (e.g., `Hooks.php` → `Events.php`) for clarity

**5.x → 6.x: Modernize JS and add capabilities**
- [ ] Bump `composer.json`: `php >=8.2`, `elgg/elgg ^6.0`; bump 3rd-party deps for PHP 8.2
- [ ] `composer update` and `composer validate --strict` pass inside the elgg6 container
- [ ] Convert RequireJS AMD `define()/require()` → ES module `import/export`
- [ ] Replace `elgg_define_js()` → `elgg_register_esm()`
- [ ] Replace `elgg_require_js()` → `elgg_import_esm()`
- [ ] Add `'restorable' => true` to entity capabilities where appropriate
- [ ] Update MySQL to 8.0+ compatible syntax
- [ ] Update PHP to 8.2+ features where beneficial

---
