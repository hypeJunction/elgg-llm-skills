# Elgg CMS/Framework Version History & Migration Reference

## Version Overview

| Version | PHP Requirement | Release Branch | Key Theme |
|---------|----------------|---------------|-----------|
| 1.x     | ~5.4           | 1.12          | Legacy procedural API |
| 2.x     | >=5.6          | 2.3           | Composer, AMD JS, Font Awesome |
| 3.x     | >=7.0 (7.2 in 3.3) | 3.3      | DI container, routing, metadata denormalization |
| 4.x     | >=7.4          | 4.3           | PHP 8 compat, elgg-plugin.php, type hints |
| 5.x     | >=8.0          | 5.1           | Hooks/events merge, private settings removed |
| 6.x     | >=8.1          | 6.1           | ES modules, deleted state, MySQL 8.0 |

---

## 1.x to 2.0 Breaking Changes

### PHP & Infrastructure
- PHP 5.6+ required (up from 5.4)
- Elgg can be installed as a Composer dependency (not just at document root)
- `fxp/composer-asset-plugin` required for source installs
- Migrated from ext/mysql to PDO MySQL
- Introduced `Zend\Mail` for email delivery

### File Structure
- Elgg installable via Composer: site root may differ from Elgg root
- `elgg_get_root_path()` returns app root, not necessarily Elgg core root
- Don't load `engine/start.php` directly; use `\Elgg\Application::start()`

### JavaScript
- Dropped `jquery-migrate`, upgraded jQuery to ^2.1.4
- All scripts moved to bottom of page (no more `<script>` in `<head>`)
- Inline scripts must use `require(['elgg', 'jquery'], function(elgg, $) { ... })`

### Views
- JS/CSS views moved out of `js/` and `css/` directories:
  - `js/view` -> `view.js`
  - `css/view` -> `view.css`
- Cacheable views must have file extensions (e.g., `.html`, `.js`, `.css`)
- Icons migrated to Font Awesome (sprites removed)
- Removed view variables: `$vars['url']`, `$vars['user']`, `$vars['config']`, `$CONFIG`
  - Use `elgg_get_site_url()`, `elgg_get_logged_in_user_entity()`, `elgg_get_config()`
- `$entity->view` metadata no longer specifies rendering view
- Viewtype is static after initial `elgg_get_viewtype()` call

### Removed Views (major ones)
- `canvas/layouts/*`, `metatags`, `page_elements/*`, `settings/{plugin}/edit`
- `usersettings/{plugin}/edit`, `widgets/{handler}/view`
- `input/calendar`, `input/datepicker`, `input/pulldown`
- `output/calendar`, `output/confirmlink`
- `profile/icon`

### Removed Functions (major ones)
- `delete_entities()`, `get_entities()`, `list_entities()`
- `get_entities_from_*()`, `list_entities_from_*()`
- `extend_view()`, `elgg_get_view_location()`
- `execute_delayed_query()`, `get_db_link()`, `get_db_error()`
- `menu_item()`, `make_register_object()`
- All `mysql_*()` functions

### Removed Methods
- `ElggEntity::getOwner()`, `::setContainer()`, `::getContainer()`
- `ElggEntity::getIcon()`, `::setIcon()`, `::clearMetadata()`
- `ElggEntity::clearRelationships()`, `::clearAnnotations()`
- `ElggData::initialise_attributes()`, `::getObjectOwnerGUID()`

### Plugin Changes
- Discussion moved from groups to own plugin (`object, groupforumtopic` -> `object, discussion`)
- Likes: objects no longer likable by default (register `likes:is_likable` hook)
- Login-over-HTTPS feature dropped

### Events/Hooks
- Relationship events fire only once with `"relationship"` type
- `all` keyword no longer affects handler call order
- `export/` URLs removed

### Database
- PDO MySQL replaces ext/mysql
- `execute_delayed_*_query()` handlers now receive `\Doctrine\DBAL\Driver\Statement`

---

## 2.x to 3.0 Breaking Changes

### PHP & Infrastructure
- PHP 7.0+ required (7.2 in later 3.x releases)
- `$CONFIG` global is now a proxy; direct array modification fails
- Composer asset plugin no longer required
- `elgg()` returns DI container, not Application instance
- `engine/start.php` removed

### Database Schema (MASSIVE changes)
- Storage engine changed from MyISAM to InnoDB
- `datalists` table removed (merged into `config`)
- Metastrings denormalized: `metastrings` table removed, values stored directly in metadata/annotations tables
- `entity_subtypes` table dropped; `subtype` column holds string directly
- Subtables removed: `sites_entity`, `groups_entity`, `objects_entity`, `users_entity` -> all moved to metadata
- `password` and `hash` columns removed from `users_entity`
- `geocode_cache` table removed
- `type`, `subtype`, `access_id` columns dropped from `river` table
- `ACCESS_FRIENDS` migrated to actual access collection (subtype `friends`)
- Access collections table gained `subtype` column

### Metadata Changes
- Metadata no longer access controlled (no `access_id`)
- Metadata no longer enabled/disabled
- Metadata no longer has `owner_guid`
- Boolean values stored as integers (`false`=`0`, `true`=`1`)

### Entity Changes
- Multi-site support removed; `site_guid` attribute removed
- All entities MUST have a subtype (defaults: `user`, `group`, `site`)
- Entity subtypes are now strings, not IDs
- `ElggEntity` subtable attributes moved to metadata
- Entity icons: only master size saved, others created on demand
- Empty entity URLs no longer normalized to site URL
- Class constructors accept only `stdClass` or `null` (no more GUID/username)

### Routing (NEW system)
- `elgg_register_page_handler()` deprecated
- New: `elgg_register_route()` with named routes
- New: `elgg_generate_url()` and `elgg_generate_entity_url()`
- All core page handlers removed; logic moved to resource views
- Labelling convention: `item:object:blog`, `collection:object:blog`, `add:object:blog`, etc.

### Views
- Layout system simplified: `one_column`, `one_sidebar`, `two_sidebar`, `content` -> all use `page/layouts/default`
- ~70+ views removed (see full list in upgrade notes)
- Discussion replies moved to comments system
- Pages plugin: `page_top` subtype merged into `page`

### Removed Functions/Methods (major)
- `elgg_register_library()`, `elgg_load_library()` -> use `require_once` or autoloading
- `can_write_to_container()` -> `ElggEntity->canWriteToContainer()`
- `add_subtype()`, `update_subtype()`, `remove_subtype()`, `get_subtype_id()` -> `elgg_set_entity_class()`
- All `elgg_get_entities_from_*()` deprecated -> use `elgg_get_entities()` with unified options
- All `elgg_list_entities_from_*()` deprecated -> use `elgg_list_entities()`
- `ElggEntity::addToSite()`, `::getSites()`, `::removeFromSite()`
- `ElggFile::setFilestore()` (custom filestores no longer supported)
- All metastring functions, cache pool classes

### Query Builder (NEW)
- Raw SQL in `wheres`, `joins`, `order_by`, `group_by`, `selects` deprecated
- Use closures with `\Elgg\Database\QueryBuilder`:
  ```php
  elgg_get_entities([
    'wheres' => [
      function(\Elgg\Database\QueryBuilder $qb, $alias) {
        $joined = $qb->joinMetadataTable($alias, 'guid', 'status');
        return $qb->compare("$joined.name", 'in', ['draft'], ELGG_VALUE_STRING);
      }
    ]
  ]);
  ```

### Removed Hooks/Events
- `pagesetup, system`, `login, user`, `upgrade, upgrade`
- `index, system`, `email, system`, `display, view`
- `object:notifications, <type>`

### JavaScript
- `elgg.widgets`, `lightbox.js`, `elgg.embed`, `elgg.discussion` -> AMD modules
- `jQuery.cookie` -> `elgg.session.cookie`

### DI Container
- Plugins define services in `elgg-services.php`
- `elgg()` returns DI container

### Permissions
- Permission hooks not triggered for admin users or when access is ignored
- `elgg_check_access_overrides()` removed

### Theme Changes
- Aalborg theme no longer bundled (core theme based on it)
- Topbar/navbar/header combined into single responsive topbar
- Default width 1280px, rem units, 8-point grid, flexbox, mobile-first
- CSS preprocessed with CSS Crush

---

## 3.x to 4.0 Breaking Changes

### PHP & Infrastructure
- PHP 7.4+ required (for PHP 8 compatibility)
- Doctrine DBAL v2 -> v3 (`$qb->fetch()` returns array, not object)
- PHP-DI updated: `\DI\object()` -> `\DI\create()`
- `Zend\Mail` -> `Laminas\Mail`
- jQuery updated to 3.5.x
- jQuery UI updated to 1.12.x (no longer fully loaded by default)

### Plugin Bootstrapping
- `start.php`, `activate.php`, `deactivate.php`, `views.php` no longer included
- Use `elgg-plugin.php` and/or `PluginBootstrap` class
- Plugin manifest (`manifest.xml`) no longer used -> `elgg-plugin.php` + `composer.json`
- Root composer project no longer treated as semi-plugin

### Entity Attributes
- `type`, `subtype`, `enabled` cannot be set via magic setter
- Use `setSubtype()`, `enable()`, `disable()`
- `ElggUser`: `admin` and `banned` not settable via magic setter
- Use `makeAdmin()`, `removeAdmin()`, `ban()`, `unban()`

### Database Schema
- `access_id`, `owner_guid`, `enabled` removed from `metadata` table
- `enabled` removed from `river` table
- `relationship` column max length increased to 255 (from 50)

### Notifications
- Pre-1.9 notification handling completely removed
- Subscription relationship: `notifymethod` -> `notify:method`
- `ElggEmail::getTo()` always returns array
- Notification settings now have a "purpose"
- Notifications plugin removed (features moved to core)

### OkResponse/ErrorResponse/RedirectResponse
- Classes split apart (no longer inherit from OkResponse)
- All extend `Elgg\Http\Response`, implement `ResponseBuilder`
- `elgg_error_response()` default HTTP code changed to 400

### Hookable Field Configurations
- `elgg_get_config('pages')` -> `elgg()->fields->get('object', 'page')`
- `elgg_get_config('group')` -> `elgg()->fields->get('group', 'group')`
- `elgg_get_config('profile_fields')` -> `elgg()->fields->get('user', 'user')`
- Hooks: `profile:fields, group` -> `fields, group:group`
- Hooks: `profile:fields, user` -> `fields, user:user`

### Menus
- `_elgg_setup_vertical_menu` -> use `prepare_vertical` var
- `_elgg_menu_transform_to_dropdown` -> use `prepare_dropdown` var
- Filter tabs: `elgg_get_filter_tabs()` removed, use `register, menu:filter:filter` hook
- Title menu auto-populates from entity menu when entity provided to layout

### Tag Metadata Registration
- `elgg_register_tag_metadata_name()`, `elgg_get_registered_tag_metadata_names()` removed
- Use `search:fields` hooks instead

### Container Permissions
- `ElggEntity::canWriteToContainer()` now requires `$type` and `$subtype`

### Removed Dependencies
- `bower-asset/jquery-treeview`, `bower-asset/jquery.imgareaselect`
- `npm-asset/formdata-polyfill`, `npm-asset/jquery-form`, `npm-asset/weakmap-polyfill`

### JavaScript
- Ajax helpers removed: `elgg.action()`, `elgg.get()`, `elgg.getJSON()`, `elgg.post()` -> `elgg/Ajax`
- `Elgg/Plugin` class removed
- `ElggPriorityList` class removed
- `boot, system` and `ready, system` JS triggers removed

### Massive Callback Renames
- All `_elgg_*` and plugin function callbacks renamed to class-based handlers
- Example: `_elgg_entity_menu_setup` -> `Elgg\Menus\Entity::registerEdit()` and `::registerDelete()`
- (See full list in upgrade notes - hundreds of renames)

### Type Hints Added
- Many functions gained type hints for parameters and return types
- This can cause `TypeError` exceptions in existing code

---

## 4.x to 5.0 Breaking Changes

### PHP & Infrastructure
- PHP 8.0+ required
- CKEditor updated to v5 (impacts embed plugin, wire mentions)
- Faker library switched from fzaninotto to FakerPHP

### Events and Hooks MERGED
- Hooks and events merged into single "events" concept
- `hooks` service removed
- All hooks register in `events` section of `elgg-plugin.php`
- `ElggHook` type hint -> `ElggEvent`
- `create, <type>` events can no longer prevent entity creation (use `create:before`)

### Private Settings REMOVED
- Entire private settings concept removed
- All private settings copied to metadata
- All related functions removed

### Breadcrumbs -> Menu System
- Breadcrumbs integrated into menu system
- Helper functions changed to use `elgg_register_menu_item()`
- Breadcrumb-related events removed (use regular menu events)

### Session
- Various session functions moved from `elgg_get_session()` to `elgg()->session_manager`

### Files Plugin
- Files stored with file entity (not owner)
- File icons changed; icon images only for image file types
- Icon sizes use default sizes

### Embed Plugin REMOVED

### JavaScript
- Hook functions moved to AMD module `elgg/hook`
- `'init', 'system'` JS event no longer triggered
- Removed: `elgg.register_hook_handler`, `elgg.trigger_hook` -> use `elgg/hooks` module
- Concept of "instant hooks" removed

### Exceptions Reworked
- `\Elgg\Exceptions\InvalidParameterException` removed
- Replaced with more appropriate exception types

### ElggRiverItem
- No longer allows arbitrary runtime data

### Metadata Query Magic Removed
- `metadata_value` string with `,` no longer auto-split into array

### Massive Type Hinting
- Hundreds of functions gained strict type hints for parameters and return types
- Return type changes: many functions return `null` instead of `false`/`bool` on failure
- Examples: `get_entity()`, `get_user()`, `elgg_get_page_owner_entity()` return `null` not `false`

### Deprecated APIs
- `elgg_register_plugin_hook_handler` -> `elgg_register_event_handler`
- `elgg_trigger_plugin_hook` -> `elgg_trigger_event_results`
- `elgg_unregister_plugin_hook_handler` -> `elgg_unregister_event_handler`
- `get_user_by_email` -> `elgg_get_user_by_email`
- `get_user_by_username` -> `elgg_get_user_by_username`

### Removed Functions
- `blog_prepare_form_vars`, `bookmarks_prepare_form_vars`, `discussion_prepare_form_vars`
- `file_prepare_form_vars`, `groups_prepare_form_vars`, `messages_prepare_form_vars`
- `pages_prepare_form_vars`, `thewire_latest_guid`
- `elgg_get_breadcrumbs`, `elgg_pop_breadcrumb`
- `elgg_set_email_transport`
- `elgg_trigger_deprecated_plugin_hook`
- `elgg_ws_expose_function` -> use `elgg-plugin.php` or `register, api_methods` event

### Moved Classes
- `ElggAutoP` -> `Elgg\Views\AutoParagraph`
- `ElggCache` -> `Elgg\Cache\BaseCache`
- `ElggDiskFilestore` -> `Elgg\Filesystem\Filestore\DiskFilestore`
- `ElggFilestore` -> `Elgg\Filesystem\Filestore`
- `ElggRewriteTester` -> `Elgg\Router\RewriteTester`
- `Elgg\Database\SiteSecret` -> `Elgg\Security\SiteSecret`

### Removed Events
- `access:collections:addcollection, collection` -> `create, access_collection`
- `access:collections:deletecollection, collection` -> `delete, access_collection`
- `prepare, breadcrumbs` -> `register, menu:breadcrumbs`
- `widget_settings, <widget_handler>`
- `REFERER` constant removed (use `REFERRER`)

### Upgrades
- Async/system upgrades must extend abstract classes (not implement interfaces)
- Access `ElggUpgrade` entity via `$this->getUpgrade()`

### Gatekeepers
- `PageOwnerCanEditGatekeeper` requires pageowner to be set and logged-in user

---

## 5.x to 6.0 Breaking Changes

### PHP & Infrastructure
- PHP 8.1+ required
- `intl` PHP module required
- PHPUnit 10.5
- MySQL 8.0+ required
- MariaDB 10.6+ required

### ES Modules (RequireJS REMOVED)
- RequireJS/AMD modules replaced with native ECMAScript modules
- `elgg_define_js()` removed -> use `elgg_register_esm()`
- `elgg_require_js()` removed -> use `elgg_import_esm()`
- `elgg_unrequire_js()` removed
- Events removed: `config, amd` and `elgg.data, site` (use `elgg.data, page`)
- Sub-Resource Integrity checks temporarily unavailable
- JavaScript testing temporarily dropped

### Entity Deleted State (NEW)
- Entities can be marked as deleted (soft delete) and restored
- `ElggFile::delete()` now always deletes both symlink and target file

### Annotations
- Default join alias changed from `n_table` to `a_table`
- `enabled` column removed from annotations
- Annotations can no longer be enabled/disabled

### Entity Icons
- Cropping coordinates stored uniformly; `x1`, `x2`, `y1`, `y2` metadata removed
- Use `ElggEntity::getIconCoordinates()`
- `icontime` metadata removed; use `ElggEntity::hasIcon()`

### Headings Restructured
- H1 = always page title (not logo/site name)
- H2 = modules (info, sidebar, widgets)
- H3 on entity summaries replaced by regular text

### CSS/HTML Structure
- Entity summaries wrapped in `<article>` element
- Sidebar uses `<aside>` element
- Modules use `<section>` instead of `<div>`
- Grid helper classes (`elgg-grid`, `elgg-col`, `elgg-row`) removed -> use CSS grid
- Duplicate CSS classes like `elgg-body` + `elgg-layout-body` deduplicated

### Removed Functions
- `elgg_disable_annotations()`, `elgg_enable_annotations()`
- `elgg_set_view_location()`
- `elgg_strrchr()`, `elgg_strripos()`
- `elgg_unrequire_css()` -> use `elgg_unregister_external_file('css', $view)`
- `ElggAnnotation->enable()`, `->disable()`
- `ElggEntity->disableAnnotations()`, `->enableAnnotations()`
- `ElggEntity->getTags()` -> use `elgg_get_metadata()`

### View Name Changes
- CSS/JS views from `css/` or `js/` folder must use full view name (no more folder omission)

### Removed Interfaces
- `\Elgg\EntityIcon` interface removed; functions moved to `\Elgg\Traits\Entity\Icons`

### Function Parameter Changes
- `elgg_get_entity_statistics()` now requires `array` of options
- `elgg_get_simplecache_url()` second argument removed (provide full view name)

---

## Plugin File Structure Evolution

### Elgg 1.x Plugin Structure
```
mod/myplugin/
  manifest.xml          # Plugin metadata
  start.php             # Bootstrap file
  activate.php          # Activation logic
  deactivate.php        # Deactivation logic
  views.php             # View registration
  languages/
    en.php
  views/
    default/
      ...
  actions/
    ...
  pages/                # Page handlers
    ...
  classes/              # PSR-0 autoloading
    ...
```

### Elgg 2.x Plugin Structure
Same as 1.x but:
- JS/CSS views moved out of `js/` and `css/` directories
- Views must have file extensions
- AMD modules introduced

### Elgg 3.x Plugin Structure
Same as 2.x but:
- `elgg-services.php` added for DI container definitions
- Named routes replace page handlers
- Libraries deprecated (use require/autoload)

### Elgg 4.x Plugin Structure
```
mod/myplugin/
  elgg-plugin.php       # REPLACES manifest.xml, start.php, views.php, etc.
  composer.json         # Plugin metadata (description, authors, license)
  elgg-services.php     # DI service definitions (uses \DI\create())
  languages/
    en.php
  views/
    default/
      ...
  actions/
    ...
  classes/              # PSR-4 autoloading
    MyPlugin/
      Bootstrap.php     # PluginBootstrap class
      ...
```

### Elgg 5.x Plugin Structure
Same as 4.x but:
- Hooks registered in `events` section of `elgg-plugin.php` (not `hooks`)
- Private settings removed (use metadata)
- Breadcrumbs are menu items

### Elgg 6.x Plugin Structure
Same as 5.x but:
- ES modules replace AMD modules
- Use `elgg_register_esm()` / `elgg_import_esm()` instead of `elgg_define_js()` / `elgg_require_js()`

---

## Key API Migration Patterns

### Hook/Event System Evolution
```
1.x-2.x: elgg_register_plugin_hook_handler('hook', 'type', callback)
         elgg_register_event_handler('event', 'type', callback)
3.x:     Same but deprecated procedural functions
4.x:     Callbacks renamed to class-based handlers
         elgg-plugin.php defines hooks/events
5.x:     Hooks merged into events
         elgg_register_event_handler() for everything
         elgg_trigger_event_results() replaces elgg_trigger_plugin_hook()
6.x:     Same as 5.x
```

### Entity Query Evolution
```
1.x: get_entities_from_metadata(), list_entities_from_annotations(), etc.
2.x: Same (deprecated in 2.x, many removed)
3.x: elgg_get_entities() with unified options array
     QueryBuilder closures replace raw SQL
4.x: Same, type-hinted
5.x: Same, metadata_value comma magic removed
6.x: Annotation join alias changed from n_table to a_table
```

### JavaScript Evolution
```
1.x: Inline scripts, jQuery plugins, elgg.* namespace
2.x: AMD modules via RequireJS, require(['elgg', 'jquery'], ...)
3.x: More AMD modules, many elgg.* APIs removed
4.x: elgg.action/get/post removed -> elgg/Ajax module
5.x: elgg/hook module for JS hooks, init system event removed
6.x: ES modules replace AMD/RequireJS entirely
     elgg_define_js() -> elgg_register_esm()
     elgg_require_js() -> elgg_import_esm()
```

### Routing Evolution
```
1.x-2.x: elgg_register_page_handler('handler', callback)
3.x:     elgg_register_route('name', [...]) (page handlers deprecated)
4.x+:    Named routes only, defined in elgg-plugin.php
```

### Plugin Configuration Evolution
```
1.x-3.x: manifest.xml + start.php + views.php + activate.php
4.x+:    elgg-plugin.php (single file) + composer.json + optional Bootstrap class
```

---

## Migration Pitfalls: Lessons from 2.x → 3.x (April 2026)

These are hard-won lessons from migrating 45 hypeJunction plugins. Each pitfall caused a runtime failure that `php -l` syntax checking could not detect.

### QueryBuilder Closures

**`$qb->subquery()` does not exist.** Several LLM-generated migrations used `$qb->subquery('entity_relationships')` which compiles but generates garbage SQL at runtime. For `NOT EXISTS` / `EXISTS` subqueries, use raw SQL inside the closure:

```php
// WRONG — $qb->subquery() is not a real method
$options['wheres'][] = function($qb, $alias) use ($user_guid) {
    $rel = $qb->subquery('entity_relationships');  // DOES NOT EXIST
    ...
};

// CORRECT — raw SQL inside closure
$options['wheres'][] = function($qb, $alias) use ($user_guid) {
    $dbprefix = elgg_get_config('dbprefix');
    return "NOT EXISTS (SELECT 1 FROM {$dbprefix}entity_relationships 
        WHERE guid_one = {$user_guid} AND relationship = 'viewed' 
        AND guid_two = {$alias}.guid)";
};
```

**Valid QueryBuilder methods for joins:**
- `$qb->joinMetadataTable($alias, 'guid', 'metadata_name')` — join metadata
- `$qb->joinAnnotationTable($alias, 'guid', 'annotation_name')` — join annotations
- `$qb->joinEntitiesTable($alias, 'container_guid')` — join entities table
- `$qb->joinRelationshipTable($alias, 'guid', 'relationship')` — join relationships
- `$qb->compare("$alias.column", '=', $value, ELGG_VALUE_STRING)` — comparisons
- `$qb->between("$alias.time_created", $start, $end)` — ranges

### elgg-plugin.php Class Loading

**Do NOT use class constants** (e.g., `MyClass::SUBTYPE`) for values in `elgg-plugin.php`. The file is parsed before autoloaders run, so constants cause `Class not found` fatals.

```php
// WRONG — SUBTYPE constant triggers autoload before classes/ is registered
'subtype' => hjAlbum::SUBTYPE,

// CORRECT — string literals for values, ::class is OK (resolved at compile time)
'subtype' => 'hjalbum',
'class' => \hypeJunction\Gallery\hjAlbum::class,  // ::class is fine
```

### Function Renames (Not Caught by AST Rules)

These function renames are easy to miss because they look correct:

| Wrong (doesn't exist in 3.x) | Correct |
|-------------------------------|---------|
| `elgg_get_current_language()` | `get_current_language()` |
| `elgg_count_entities()` | `elgg_get_entities(['count' => true])` |
| `$group->group_acl` | `$group->getOwnedAccessCollection('group_acl')->id` |

### Metastrings Removal

In Elgg 2.x, metadata was stored via metastring IDs:
```
metadata.name_id → metastrings.id → metastrings.string = 'geo:lat'
metadata.value_id → metastrings.id → metastrings.string = '51.5074'
```

In Elgg 3.x, metadata is stored directly:
```
metadata.name = 'geo:lat'
metadata.value = '51.5074'
```

When rewriting SQL joins:
- Replace `JOIN metastrings ms ON md.value_id = ms.id` with direct `md.value`
- Replace `ms.string` with `md.value` or `n_table.value`
- Replace `md.name_id = {metastring_id}` with `md.name = 'metadata_name'`

### Entity Subtable Removal

`users_entity`, `groups_entity`, `objects_entity`, `sites_entity` tables are gone. Fields like `name`, `username`, `description`, `title` are now metadata.

For sorting: use `'sort_by' => ['property' => 'name', 'direction' => 'ASC']` instead of `ORDER BY ue.name`.

### Docker Validation Gate

Even with all 45 plugins activating successfully, the site may still 500. Plugin activation only checks `start.php` parse + `init` event registration. Views and hooks that run on page render can still crash.

**Always test both:**
1. Plugin activation: `docker logs` should show "N plugin(s) activated, 0 failed"
2. Site render: `curl -sL http://localhost:PORT/` should return HTTP 200, >1000 bytes, no "Fatal Error" in title
3. CSS validation: `curl` the CSS URL, should be >1000 bytes (css-crush silently returns empty on certain CSS errors)

---

## Source URLs

- Upgrade notes index: https://learn.elgg.org/en/stable/appendix/upgrade-notes.html
- 1.x to 2.0: https://learn.elgg.org/en/stable/appendix/upgrade-notes/1.x-to-2.0.html
- 2.x to 3.0: https://learn.elgg.org/en/stable/appendix/upgrade-notes/2.x-to-3.0.html
- 3.x to 4.0: https://learn.elgg.org/en/stable/appendix/upgrade-notes/3.x-to-4.0.html
- 4.x to 5.0: https://learn.elgg.org/en/stable/appendix/upgrade-notes/4.x-to-5.0.html
- 5.x to 6.0: https://learn.elgg.org/en/stable/appendix/upgrade-notes/5.x-to-6.0.html
- CHANGELOG: https://github.com/Elgg/Elgg/blob/6.1/CHANGELOG.md
- Branches: 1.9, 1.10, 1.11, 1.12, 2.0-2.3, 3.0-3.3, 4.0-4.3, 5.0-5.1, 6.0-6.1
