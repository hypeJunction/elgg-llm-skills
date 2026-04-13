# Version API Boundaries

This document defines which APIs belong to which Elgg version. Use this to verify that migrated code only uses APIs available in the target version.

The `PostMigrationVerifier` class (`src/PostMigrationVerifier.php`) enforces these boundaries automatically via `--verify`.

## API Ownership by Version

### Elgg 2.x APIs (removed in 3.x)

| API | Replacement |
|-----|-------------|
| `elgg_register_page_handler()` | Declarative routes in `elgg-plugin.php` |
| `elgg_register_library()` / `elgg_load_library()` | PSR-4 autoloading |
| `add_group_tool_option()` | `elgg()->group_tools->register()` (3.x), declarative (4.x) |
| `elgg_instanceof()` | `$entity instanceof ElggBlog` |
| `elgg_register_entity_type()` | `'entities'` key in `elgg-plugin.php` |
| `get_input()` return type changes | `get_input()` no longer returns `false` |
| Metastrings API | Metadata stored as values directly |
| `elgg_register_event_handler('pagesetup',...)` | Menu hooks |

### Elgg 3.x APIs (valid in 3.x, some deprecated in 4.x)

| API | Status in 4.x |
|-----|---------------|
| `start.php` (returning closure) | **REMOVED** — delete entirely |
| `manifest.xml` | **REMOVED** — use `'plugin'` key in `elgg-plugin.php` |
| `activate.php` / `deactivate.php` | **REMOVED** — use Bootstrap class |
| `elgg_register_plugin_hook_handler()` | **DEPRECATED** but works |
| `elgg_trigger_plugin_hook()` | **DEPRECATED** but works |
| `\Elgg\Hook` type hint | **VALID** in 3.x and 4.x |
| `elgg_generate_url()` | Valid in 3.x+ |
| `elgg()->group_tools->register()` | Works but use declarative in 4.x |

### Elgg 4.x APIs (current for 3→4 migration target)

| API | Notes |
|-----|-------|
| `elgg-plugin.php` as sole config | No start.php, no manifest.xml |
| `'hooks'` key in elgg-plugin.php | For hook-type registrations |
| `'events'` key in elgg-plugin.php | For event-type registrations |
| `\Elgg\Hook` type hint | Valid, used for hook callbacks |
| `\DI\create()` | Replaces `\DI\object()` |
| `Laminas\Mail` | Replaces `Zend\Mail` |
| `'plugin'` key in elgg-plugin.php | Replaces manifest.xml |
| `'capabilities'` in entity registration | searchable, commentable, likable |
| `'group_tools'` key | Declarative group tool registration |
| `'notifications'` key | Declarative notification handlers |
| Bootstrap class | For imperative init logic |
| `elgg_trigger_plugin_hook()` | Deprecated but correct for 4.x |
| `elgg_register_plugin_hook_handler()` | Deprecated but correct for 4.x |

### Elgg 5.x APIs (DO NOT USE when targeting 4.x)

| API | Why it's wrong in 4.x |
|-----|----------------------|
| `elgg_trigger_event_results()` | **5.x ONLY** — does not exist in 4.x |
| `elgg_register_event_handler()` for hooks | **5.x ONLY** — use `elgg_register_plugin_hook_handler()` |
| `\Elgg\Event` type hint | **5.x ONLY** — use `\Elgg\Hook` for hook callbacks |
| `'events'` key only (no `'hooks'`) | **5.x pattern** — 4.x needs both keys |
| Private settings → metadata | **5.x migration** — 4.x still has private settings |
| Route middleware (UserPageOwnerGatekeeper) | **5.x ONLY** |

### Elgg 6.x APIs (DO NOT USE when targeting 4.x or 5.x)

| API | Why it's wrong |
|-----|---------------|
| `elgg_register_esm()` | **6.x ONLY** — ES modules |
| `elgg_import_esm()` | **6.x ONLY** — ES module imports |
| ES module `import/export` in JS | **6.x ONLY** — use AMD `define()/require()` |
| `'restorable' => true` capability | **6.x ONLY** — soft-delete support |

## Hook vs Event Distinction in 4.x

This is the **most common source of version leakage**. In Elgg 4.x:

**HOOKS** (use `'hooks'` key, `\Elgg\Hook` type hint):
- `view`, `view_vars`
- `register` (menus), `prepare` (menus)
- `route`, `route:rewrite`
- `permissions_check`, `permissions_check:comment`
- `container_permissions_check`, `container_logic_check`
- `access:collections:write`
- `setting`, `plugin_setting`
- `search:fields`
- `action:validate`
- `output`
- `entity:icon:url`, `entity:url`

**EVENTS** (use `'events'` key, `\Elgg\Event` type hint):
- `create`, `update`, `delete`
- `init`, `ready`, `shutdown`
- `login`, `logout`
- `upgrade`, `activate`, `deactivate`

In **Elgg 5.x**, hooks and events merge — everything uses `'events'` and `\Elgg\Event`.

## Version Guard

The `VersionGuard` class detects a plugin's current version by checking:

1. ESM registration calls → 6.x
2. `'events'` key only (no `'hooks'`) in elgg-plugin.php → 5.x
3. elgg-plugin.php only (no start.php, no manifest.xml) → 4.x
4. elgg-plugin.php AND (start.php or manifest.xml) → 3.x
5. start.php only (no elgg-plugin.php) → 2.x

This prevents applying the wrong manifest (e.g., 4x→5x rules on a 2.x plugin).
