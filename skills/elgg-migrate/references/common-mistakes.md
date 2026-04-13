# Common mistakes and their fixes

Lookup table for the most common migration pitfalls. Each row is a mistake
observed across multiple plugin migrations and the fix that works. When
activation fails or tests regress, check here first — most failures in
practice are entries in this table.

## Common Mistakes

| Mistake | Fix |
|---------|-----|
| Closures in elgg-plugin.php | Use `ClassName::class . '::method' => []` or Bootstrap class |
| Plugin dir case mismatch | Dir must match composer.json `name` (usually lowercase) |
| Skipping Docker gate | Always validate — catches serialization, missing deps, type errors |
| Running 4.x rules on 2.x code | Migrate 2→3 first, commit, then 3→4 |
| Not installing plugin deps | Run `composer install -d <plugin>` before Docker test |
| `run_sql_script()` in activate.php | Removed in 4.x — use `elgg()->db->updateData()` with inline SQL |
| `elgg_register_menu_item()` in elgg-plugin.php | Can't go in config — use Bootstrap::init() or register hook |
| Auto-generated hooks use wrong format | Generator outputs `[Class, 'method']` — must be `Class::class . '::method' => []` |
| `elgg_extend_view()` not in elgg-plugin.php | Generator now extracts to `view_extensions` key — verify after running |
| Conditional hooks lost during migration | `elgg_is_active_plugin()` guards need Bootstrap class, not elgg-plugin.php |
| Old hook handler signatures | `($hook,$type,$return,$params)` → `(\Elgg\Hook $hook)` — rule 018 automates this |
| `get_data()` / `insert_data()` etc. | Removed in 4.x — use `elgg()->db->getData()`, `insertData()`, etc. |
| `Elgg\Database` type hints | Renamed to `Elgg\Application\Database` in 4.x — update `use` imports |
| `self::getInstance()` singleton pattern | Use DI container: `elgg()->{'service.key'}` per `elgg-services.php` |
| Tables not created on activation | Elgg 4.x does NOT run `activate.php` — it calls `Bootstrap::activate()` instead. Move all activate.php logic (table creation, schema setup) into `Bootstrap::activate()` method. Delete activate.php after migration. Use `catch (\Throwable $e)` not `catch (DatabaseException)` because Doctrine DBAL throws PDOException that Elgg doesn't wrap. Also fix TEXT columns: MySQL strict mode rejects `DEFAULT ''` on TEXT — remove the DEFAULT |
| Plugin activates but site 500s | Always test RENDER after activation — hooks fire on page load and can crash. Common cause: plugin registers `head`/`page` or `view_vars` hooks that query custom tables not yet created. Add try/catch around DB queries for custom tables as defense-in-depth |
| `elgg_trigger_event_results()` used in 4.x | That's Elgg 5.x only! In 4.x use `elgg_trigger_plugin_hook()` (deprecated but works) |
| `elgg_register_event_handler` for view/view_vars | In 4.x, `view` and `view_vars` are HOOKS not events — use `elgg_register_plugin_hook_handler()` |
| start.php still exists (even empty) | Elgg 4.x REJECTS plugins with ANY start.php file — delete it completely |
| `::SUBTYPE` constant in elgg-plugin.php | Triggers autoload before classes registered — use string literals (::class is fine) |
| `ElggEntity::save()` missing `: bool` return type | Elgg 4.x added return type hints — subclasses must match |
| `elgg_unregister_css()` in Bootstrap | Removed in 4.x — just delete the call (was defensive cleanup) |
| `elgg_register_js()` / `elgg_define_js()` in Bootstrap | Removed in 4.x — JS views under `views/default/js/` auto-discover as AMD modules |
| `elgg_register_simplecache_view()` in Bootstrap | Removed in 4.x — static `.js`/`.css` views auto-discovered by simplecache |
| `views.php` alias in `view_extensions` | When views.php is removed, update `view_extensions` to use actual view paths |
| Global Elgg functions in namespaced Bootstrap | Must use `\elgg_*()` prefix — namespace resolution fails without backslash |
| `elgg_register_admin_menu_item()` in Bootstrap | Removed in 4.x — admin views at `views/default/admin/` are auto-discovered |
| `elgg_set_plugin_setting()` in actions | Removed in 4.x — use `elgg_get_plugin_from_id('id')->setSetting()` |
| `validate_username()` in hooks/actions | Removed in 4.x — use `elgg()->accounts->assertValidUsername()` with try/catch |
| `forward(REFERRER)` in action files | Removed in 4.x — use `return elgg_ok_response()` or `return elgg_error_response()` |
| `forward()` in resource views | Removed in 4.x — use `throw new \Elgg\Exceptions\Http\EntityNotFoundException()` |
| `register_error()` / `system_message()` | Removed in 4.x — use `return elgg_error_response()` / `elgg_ok_response()` |
| `get_installed_translations()` | Removed in 4.x — use `elgg()->translator->getInstalledTranslations()` |
| `elgg_add_subscription()` | Removed in 4.x — use `$entity->addRelationship($user_guid, 'notify'.$method)` |
| Conditional view extensions in elgg-plugin.php | `elgg_is_active_plugin()` guards must go in Bootstrap::init(), not elgg-plugin.php |
| Symfony Response + exit in AJAX actions | Replace with `return elgg_ok_response($data)` / `elgg_error_response($msg, '', 422)` |
| `elgg_get_registered_tag_metadata_names()` | Removed in 4.x — use `elgg_get_config('registered_tag_metadata_names') ?? ['tags']` |
| `require(['jquery-ui'])` in JS | Elgg 4.x uses granular jQuery UI — use `require(['jquery-ui/widgets/sortable'])` etc. |
| XHR JSON echo + exit in action files | Replace manual `echo json_encode(); exit;` with `return elgg_ok_response($data)` — response system handles content negotiation |
| `views.php` removed but views key lost | When AST rule removes `views.php`, verify the `'views'` key in `elgg-plugin.php` preserves JS/CSS path mappings |
| Helper functions in start.php | Move to `lib/functions.php` and load via `Bootstrap::boot()` — don't put in elgg-plugin.php |
| `elgg_get_config('dbprefix')` in raw SQL | Removed in 4.x — use QueryBuilder `$qb->subquery()` or `elgg()->db->getTablePrefix()` |
| `elgg.action()` in JS | Removed in 4.x — use `elgg/Ajax` module: `var ajax = new Ajax(); ajax.action(...)` |
| `\Elgg\Hook` type hint introduced in 4.x | WRONG — `\Elgg\Hook` single-arg signatures work from 3.x onward. The 3→4 change is DECLARATIVE config, not the type hint |
| Monolithic menu handler function | Split into dedicated namespaced classes: `Menus\Site::register`, `Menus\OwnerBlock::registerUserItem`, etc. |
| `elgg_register_entity_type()` for search | Replaced by `'searchable' => true` in entity `'capabilities'` (4.x). Don't register separately |
| `likes:is_likable` hook for liking | Replaced by `'likable' => true` in entity `'capabilities'` (4.x). Don't register a hook for this |
| `elgg_register_notification_event()` still in Bootstrap | Use declarative `'notifications'` key in elgg-plugin.php (4.x) with handler classes |
| `elgg()->group_tools->register()` in Bootstrap | Use declarative `'group_tools'` key in elgg-plugin.php (4.x): `['blog' => []]` |
| Missing `'plugin'` key in elgg-plugin.php | Required in 4.x to replace manifest.xml: `['name' => 'Plugin Name', 'activate_on_install' => true]` |
| `blog_prepare_form_vars()` procedural function | In 5.x, use `'form:prepare:fields'` event with a `PrepareFields` class handler |
| Hardcoded URLs like `"blog/owner/$name"` | Use `elgg_generate_url('collection:object:blog:owner', ['username' => $name])` from 3.x+ |
| Old translation keys like `'blog:blogs'` | Convention changed in 3.x: `'collection:object:blog'`, `'item:object:blog'`, `'add:object:blog'` |
| Not adding route middleware in 5.x | Routes need `UserPageOwnerGatekeeper`, `PageOwnerGatekeeper`, `GroupPageOwnerGatekeeper` middleware |
| Keeping delete action for entities | Core handles generic entity deletion from 4.x — remove plugin-specific delete actions |
| Missing `'restorable' => true` capability | Add to entity capabilities in 6.x for trash/soft-delete support |
| Not using `require_once` for lib/functions.php | In 4.x, helper functions go in `lib/functions.php` loaded via `require_once(__DIR__ . '/lib/functions.php')` at top of elgg-plugin.php |
| Mixing hooks and events keys in 4.x | In 4.x, use `'hooks'` for hooks (view, view_vars, permissions, etc.) and `'events'` for events (create, update, delete). In 5.x, everything merges into `'events'` |
| `elgg_register_classes()` removed but classes/ directory still exists | The 2→3 `removed-functions` rule strips the calls but does NOT synthesize the PSR-4 autoload replacement. After running automated rules, manually add to `composer.json`: `"autoload": {"psr-4": {"VendorName\\PluginName\\": "classes/VendorName/PluginName/"}}`. If the plugin vendored a third-party library under `vendors/<Lib>/` that the old `elgg_register_classes` registered, also add that namespace to PSR-4. Run `composer dump-autoload -d <plugin>` (NOT `composer install` — `elgg/elgg` is a metadata declaration, not a runtime dep to install into the plugin). |
| 2.x→3.x plugin has stub `composer.json` without `elgg/elgg` require | The SKILL says "the plugin has no composer.json — generate it" but some 2.x plugins ship a stub `composer.json` that's just `name`/`type`/`description` with no `require.elgg/elgg`. **Augment, don't replace**: keep existing fields, add `require.elgg/elgg ^3.0`, `require.php >=7.0`, `require.composer/installers ~1.0`, and the PSR-4 `autoload` block. |
| `composer install -d <plugin>` fails with "elgg/elgg not satisfiable" | Plugin's `require.elgg/elgg` is a metadata declaration of *compatibility*, not a runtime dep. Don't try to install it inside the plugin — Elgg is the host. Use `composer dump-autoload -d <plugin>` instead, which generates `vendor/autoload.php` for the PSR-4 mappings without resolving deps. |
| 2.x plugin `isActive()` returns ElggRelationship object (not bool) | In 2.x, `\ElggPlugin::isActive()` returns the `active_plugin` relationship object when active, `false` when not. Test assertions must use `assertNotFalse()` not `assertTrue()`. From 3.x onward, it returns a real bool. |
| Plugin entity from `generateEntities()` left in disabled state | In Elgg 3.x test environments, when `_elgg_services()->plugins->generateEntities()` registers a fresh plugin entity for the first time, it can leave `enabled` blank/false even though `getError()` is empty and `activate()` returns silently `false`. Force the state in the test bootstrap: `if (!$p->isEnabled()) $p->enable(); if (!$p->isActive()) $p->activate();`. The matching DB row may need a direct `UPDATE elgg_entities SET enabled='yes' WHERE guid=...` if `$p->enable()` doesn't stick. |
| Elgg 3.x normalizes plugin id to lowercase but dir is still camelCase | A plugin at `mod/hypeFilestore/` (camelCase, valid in 3.x) is registered as `hypefilestore` (lowercase) by Elgg 3.x's plugin manager. Test code looking up `elgg_get_plugin_from_id('hypeFilestore')` returns `null`. Use the lowercase id, OR write a case-tolerant lookup: `elgg_get_plugin_from_id('hypefilestore') ?: elgg_get_plugin_from_id('hypeFilestore')`. The 3→4 migration enforces lowercase dir per Iron Law 6, eliminating the dual naming. |
| SecuritySweep flags third-party demo files (e.g. `vendors/cropper/examples/`) | Vendored libraries often ship demo/example files that contain unsanitized `echo` of user input or other patterns the security sweep flags as XSS. These are not reachable in production (the plugin doesn't include them in its routes). Add `vendors/*/examples/`, `vendors/*/demo/`, `vendors/*/test/` to the security sweep exclude list, OR `git rm` the demo dirs as part of the migration cleanup. |
| Smoke-test pattern for legacy 2.x plugins | When migrating an old 2.x plugin (Elgg 1.x era code) through 2→3, comprehensive behavior coverage is wasted work — the structure will change again in 3→4 and 4→5, and tests will be rewritten each time. Write **smoke tests** instead: assert the plugin loads (factory function exists, DI container instantiates, services resolve, hook handlers callable) — not behavior. This catches the regressions a structural transform actually introduces (autoload broken, namespace renamed, signature changed) without locking in tests for code that's about to be rewritten. |
| `docker cp` to bind-mounted dest writes to host bind source | If a Docker container has a bind mount like `/var/www/html/mod/hypeFilestore -> /home/user/.../plugins/hypeFilestore` and you `docker cp ./local/. <container>:/var/www/html/mod/hypeFilestore/`, Docker writes through to the host bind source — re-creating the directory there if you'd previously moved it. This silently re-populates "deleted" workspaces. Always check `docker inspect <container> --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{println}}{{end}}'` before doing `docker cp` into a long-running container. |
| `\Elgg\Di\DiContainer` is abstract in 4.x; `setFactory()` removed | The `\Elgg\Di\DiContainer` base class became `abstract` in Elgg 4.x and now requires `public static function getDefinitionSources(): array`. The old imperative API (`setFactory($key, $callable)` in the constructor) is gone — Elgg internally moved to PHP-DI 6 declarative definitions. Plugins extending DiContainer crash with "must implement getDefinitionSources" then "Call to undefined method setFactory". **Fix:** rewrite the plugin's container as a plain PHP class (don't extend DiContainer at all) with a `__get` magic accessor that lazy-constructs services. Preserve the same outward interface (`hypeFoo()->config`, `->hooks`, etc.) so calling code doesn't change. |
| Hardcoded camelCase `PLUGIN_ID` constant breaks lookup in 4.x | A common 2.x/3.x pattern is `class Config { const PLUGIN_ID = 'hypeFoo'; }` then `elgg_get_plugin_from_id(self::PLUGIN_ID)`. In 4.x, plugin IDs are normalized to lowercase, so the camelCase lookup returns `null` and any code that constructs services from `Config::factory()` (which calls `new Config($plugin)` where `$plugin` is now null) hits a TypeError on the constructor's `ElggPlugin` type hint. **Fix:** lowercase the constant. For 3.x→4.x transitional builds, use a fallback: `elgg_get_plugin_from_id(self::PLUGIN_ID) ?: elgg_get_plugin_from_id('hypeFoo')`. |
| Broken neighbor plugin in `mod/` blocks elgg4 boot of unrelated plugins | Elgg 4.x scans every directory in `/var/www/html/mod/` during plugin entity generation. If ANY of them is missing `composer.json`, the entire boot fails with `\Elgg\Exceptions\Plugin\ComposerException: Missing composer.json for plugin ID X (guid Y)`. This breaks activation of unrelated plugins under test. **Workarounds:** (a) `DELETE FROM elgg_entity_relationships WHERE relationship='active_plugin' AND guid_one=Y` (force-deactivate via SQL using the guid from the error), (b) docker exec the container and stub a minimal composer.json in the broken plugin's dir, (c) move the broken plugin out of `mod/` for the duration of the test. The bigger fix is to not bind-mount unmigrated plugin dirs into the elgg4 container in the first place. |
| Plugin Bootstrap that calls `elgg_register_css` / `elgg_register_external_view` in init() | Both functions were removed in Elgg 4.x. Bootstrap classes that call them get `Error: Call to undefined function elgg_register_css()` at activation time. **Fix:** delete the call. CSS and JS that need to be served from the plugin should live as views under `views/default/css/` and `views/default/js/` — Elgg's simplecache auto-discovers them. Files vendored under `vendors/` (not Elgg views) cannot be served via `elgg_register_css`; consumer plugins must reference them through their own view files. |

