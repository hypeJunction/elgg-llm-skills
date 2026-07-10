# Elgg Migration Failure Catalog

Canonical catalog of the failure CLASSES this project has actually hit while migrating Elgg
plugins across major versions. Every entry is grouped by version step and reduced to a **static
detection signature** (a removed symbol, a regex, a forbidden file, or a config/timing pattern), a
**fix**, a **test-to-write**, and its source refs (`bd` issues, `.wolf/buglog.json` bug-NNN,
`.wolf/cerebrum.md` Do-Not-Repeat dates, bd memories).

`gate` column: **YES** = already detected by `PostMigrationVerifier` or `SecuritySweep`
(runs on `migrate.php --verify`); **rule** = handled by an automated AST rule under
`src/Rules/`; **NO** = no static gate — a data/render/e2e failure verified only by an
integration round-trip or a rendered-page battery, NOT a candidate for a static check.

> **The authoritative, test-backed coverage record is the "Gate coverage audit"
> table at the foot of this file** (bd elgg-migrate-99qbj, 2026-07-09). Many
> per-entry `gate:` lines below predate the verifier checks that now cover them and
> are indicative only; the audit table names the concrete check/rule and the passing
> test for every FC class. Result: **0 FC classes admit a static gate without one** —
> every remaining `gate: NO` is a by-design data/render/e2e case.

Two data files feed the automated gates and are kept in lock-step with this catalog:
- `references/removed-functions.json` — global symbols that fatal with "Call to undefined function"
  at/after a target major (`checkRemovedFunctions`, cumulative).
- `references/changed-class-contracts.json` — core types whose KIND changed (interface⇄class, or
  removed) so `implements`/`extends`/`use`/`new` fatals on boot (`checkChangedClassContracts`).

> **Two contract-shaped fatals below are deliberately NOT injected into
> `changed-class-contracts.json`** — the keyword-based gate would false-positive on
> correctly-migrated code. They are documented here as `gate: NO` and need a dedicated verifier
> check: **Seed missing `getType()`/`getCountOptions()`** (the base-class line `extends Seed` is
> still correct — only the missing methods are wrong) and **`route:rewrite` boot timing** (not a
> class at all).

---

## 2.x → 3.x

### FC-2x3x-01 — Metastrings query API removed
- **Detection (symbol):** `elgg_get_metastring_id(`, `_elgg_entities_get_metastrings_options(`
  (`removed-functions.json[3.x]`); also raw `metadata.name_id` / `value_id` joins and
  `sanitize_string()` in hand-built SQL.
- **Fix:** The metastrings table was dropped in 3.0. Reimplement sort/filter via QueryBuilder.
  `group_sort` / `object_sort` / `user_sort` are **rewrite-not-migrate** — small 7.x plugins with
  QueryBuilder-based sort options.
- **Test-to-write:** unit — a fixture calling `elgg_get_metastring_id()` yields a `removed-function`
  violation at target `3.x`.
- **gate:** YES · **Sources:** bd elgg-migrate bug-022, `removed-functions.json`

### FC-2x3x-02 — 3.x plugin dropped `start.php` prematurely
- **Detection (file):** `elgg-plugin.php` present AND `start.php` absent at a `3.x` target
  (`check3xStartPhpExists`, warning). In 3.x `start.php` must still exist (returning a closure).
- **Fix:** keep `start.php` for 3.x; only delete it at the 3.x→4.x step.
- **Test-to-write:** unit — plugin dir with `elgg-plugin.php` but no `start.php` warns at `3.x`.
- **gate:** YES · **Sources:** `PostMigrationVerifier::check3xStartPhpExists`

### FC-2x3x-03 — 3.0 search hook stopped returning `['entities']` (latent null TypeError)
- **Detection (regex):** handlers registered on the `search` hook that `return ['entities' => …]`
  / return `null` and do not funnel through `elgg_search()` or
  `elgg_list_entities(…, 'elgg_search')`. Latent 3.x → 7.x.
- **Fix:** use `elgg_search()` / `elgg_list_entities(..., 'elgg_search')`; fix at the version it
  broke (3.0) and forward-merge; add a render battery per version.
- **Test-to-write:** e2e — `/search` and `/members` return 200 (not 500) on every version tier.
- **gate:** NO · **Sources:** buglog bug-025, bd elgg-migrate-dhwqm, cerebrum feedback_elgg3_search_rewrite_latent

### FC-2x3x-04 — Site secret scrubbed → 3.x+ BootService hard-throws
- **Detection (config):** `elgg_datalists`/config `__site_secret__` empty or removed. 2.x
  regenerates it lazily (renders fine); 3.x+ `BootService` throws "The site secret is not set".
- **Fix:** re-seed a fresh secret (`z` + 31 url-safe chars) before the 3.x datalists→config phinx
  migration. Chain/anonymization concern, not plugin code.
- **Test-to-write:** chain — 3.x tier home page returns 200 after anonymize.
- **gate:** NO · **Sources:** buglog bug-010

---

## 3.x → 4.x

### FC-3x4x-01 — `start.php` / `activate.php` / `deactivate.php` must be DELETED
- **Detection (forbidden file):** any of `start.php`, `activate.php`, `deactivate.php` present at a
  `4.x` target (`checkForbiddenFiles`). 4.x rejects the plugin ("a start.php was found…").
- **Fix:** delete the files entirely (not empty/comment-only). Move activation side effects to
  `Bootstrap::activate()`. Note: `activate.php` is NOT run by 4.x `Plugin::activate()`.
- **Test-to-write:** unit — a 4.x plugin dir containing `start.php` fails `--verify`.
- **gate:** YES · **Sources:** cerebrum DNR 2026-04-15, `VERSION_BOUNDARIES[4.x].forbidden_files`, bd memory activate-php-is-not-run

### FC-3x4x-02 — CSS/JS registration functions removed
- **Detection (symbol):** `elgg_register_css(`, `elgg_load_css(` (`removed-functions.json[4.x]`);
  also `elgg_register_js`, `elgg_define_js`, `elgg_register_simplecache_view` (bd memory).
- **Fix:** `elgg_register_external_file('css', $name, $url)` + `elgg_load_external_file('css',$name)`;
  or declare `view_extensions` in `elgg-plugin.php`.
- **Test-to-write:** unit — `elgg_register_css()` flags `removed-function` at `4.x`.
- **gate:** YES (css) / rule (`CssRegistration`) · **Sources:** cerebrum DNR 2026-04-15, bd memory elgg-register-css-removed

### FC-3x4x-03 — `elgg_format_attributes()` removed
- **Detection (symbol):** `elgg_format_attributes(` (`removed-functions.json[4.x]`).
- **Fix:** `_elgg_services()->html_formatter->formatAttributes($attrs)` (5.x+
  `elgg_format_element('', $attrs)`), or build attributes manually with `htmlspecialchars()`.
- **Test-to-write:** unit — flagged at `4.x`.
- **gate:** YES · **Sources:** cerebrum DNR 2026-04-15, 2026-05-08

### FC-3x4x-04 — `ElggFile::detectMimeType()` removed
- **Detection (symbol/regex):** `ElggFile::detectMimeType(` (`removed-functions.json[4.x]`); also
  regex `->detectMimeType\s*\(`. NB: the call-shaped gate reliably catches only the unqualified
  static form — instance `$file->detectMimeType()` needs a dedicated method scan.
- **Fix:** `mime_content_type($path)` guarded by `is_file($path)` (NOT `file_exists()` — that is
  true for the directory an empty filename resolves to; see bug-011). Save the file entity first,
  then write bytes.
- **Test-to-write:** unit — `ElggFile::detectMimeType(` flags at `4.x`; integration — image seed +
  render `/file/all` returns 200 (guards missing-file mime lookups).
- **gate:** partial (static only) · **Sources:** cerebrum DNR 2026-04-16, buglog bug-011

### FC-3x4x-05 — `access_get_show_hidden_status()` removed
- **Detection (symbol):** `access_get_show_hidden_status(` (`removed-functions.json[4.x]`); the
  save/restore `access_show_hidden_entities()` pattern is gone too.
- **Fix:** `elgg_call(ELGG_SHOW_DISABLED_ENTITIES | ELGG_IGNORE_ACCESS, function () { … })`.
- **Test-to-write:** unit — flagged at `4.x`.
- **gate:** YES · **Sources:** cerebrum DNR 2026-04-22

### FC-3x4x-06 — `create_metadata()` / `update_metadata()` removed
- **Detection (symbol):** `create_metadata(`, `update_metadata(` (`removed-functions.json[4.x]`).
- **Fix:** `new \ElggMetadata` + `_elgg_services()->metadataTable->create($md, $allow_multiple)`;
  update = `metadataTable->get($id)`, mutate, `$md->save()`.
- **Test-to-write:** unit — both flagged at `4.x`.
- **gate:** YES · **Sources:** cerebrum DNR 2026-04-19

### FC-3x4x-07 — `Elgg\Di\ServiceFacade` trait removed
- **Detection (class contract):** `use Elgg\Di\ServiceFacade;` / in-class `use ServiceFacade;`
  (`changed-class-contracts.json[4.x]`, `illegal_keyword: use`).
- **Fix:** remove the trait use + the `name()` method; register via `DI\create()` in
  `elgg-services.php` with the DI array key as the service name.
- **Test-to-write:** unit — a service class with `use ServiceFacade;` flags `changed-class-contract`
  at `4.x`.
- **gate:** YES · **Sources:** cerebrum DNR 2026-04-16

### FC-3x4x-08 — `Elgg\Notifications\NotificationEvent` is an interface
- **Detection (class contract):** `new Elgg\Notifications\NotificationEvent(`
  (`changed-class-contracts.json[4.x]`, `illegal_keyword: new`).
- **Fix:** instantiate a concrete impl — `SubscriptionNotificationEvent` (object subscription) or
  `InstantNotificationEvent` (instant/direct).
- **Test-to-write:** unit — `new NotificationEvent(` flags at `4.x`; concrete subclasses do NOT.
- **gate:** YES · **Sources:** cerebrum DNR 2026-04-17

### FC-3x4x-09 — Hook/event confusion (`view`/`register`/`prepare`/`route` are HOOKS in 4.x)
- **Detection (config/regex):** hook names under the `'events'` key of `elgg-plugin.php`, or
  `elgg_register_event_handler('view'|'register'|'route'|…)` (`check4xHookEventConfusion`).
- **Fix:** move to the `'hooks'` key / use `elgg_register_plugin_hook_handler()`.
- **Test-to-write:** unit — `elgg-plugin.php` with `'view' =>` under `events` flags at `4.x`.
- **gate:** YES · **Sources:** `PostMigrationVerifier`, cerebrum DNR 2026-04-20

### FC-3x4x-10 — camelCase plugin id at callsites silently returns false
- **Detection (regex):** `elgg_get_plugin_from_id('CamelCase')`,
  `elgg_get_plugin_setting(…, 'CamelCase')`, namespaced `PLUGIN_ID` constants — 4.x lowercases ids;
  camelCase returns `false` (not the default).
- **Fix:** lowercase every plugin id at every callsite.
- **Test-to-write:** unit — `LowercasePluginIdCallsites` rule rewrites a camelCase id arg.
- **gate:** rule (`V3ToV4/LowercasePluginIdCallsites`) · **Sources:** bd elgg-migrate-qmqm, bd memory hypeattachments-case-sensitive

### FC-3x4x-11 — camelCase→lowercase rename strands plugin SETTINGS (data)
- **Detection (data):** active lowercase plugin entity with no metadata; settings still on the
  disabled camelCase twin; freshly-restored plugins stuck `enabled='no'`.
- **Fix:** ship an `Elgg\Upgrade\Batch` that copies metadata camelCase→lowercase entity and enables
  on-disk ∩ prod-active disabled entities. Never polyfill.
- **Test-to-write:** integration — a plugin with a camelCase-era setting reads the value post-migration.
- **gate:** NO · **Sources:** buglog bug-040, bug-023, bd memory elgg-camelcase

### FC-3x4x-12 — Handler signature: 4.x handlers receive a single object
- **Detection (regex):** `function \w+\(\$hook,\s*\$type,\s*\$return,\s*\$params\)`; bare undefined
  `$type` / `$return` / `$params` inside migrated handlers.
- **Fix:** single `\Elgg\Hook`/`\Elgg\Event` param; `$hook->getType()`/`getValue()`/`getParam()`.
  `register/menu:*` value is a `MenuItems` collection — use `$return->merge($items)`, not `array_merge`.
- **Test-to-write:** unit — a 4-arg hook handler flags; integration — menu hook returns items.
- **gate:** NO · **Sources:** cerebrum DNR 2026-04-20, bd memory elgg-4-x-handler-signatures

### FC-3x4x-13 — 4.x does NOT auto-run `install/mysql.sql`
- **Detection (file):** `install/mysql.sql` present with no `Bootstrap::activate()` override that
  reads+executes it (`DefaultPluginBootstrap::activate()` is a no-op in 4.x).
- **Fix:** override `Bootstrap::activate()`: prefix-swap + `executeStatement()` each statement.
- **Test-to-write:** integration — activation creates the plugin's custom table.
- **gate:** NO · **Sources:** cerebrum DNR 2026-04-17

### FC-3x4x-14 — `\Elgg\GatekeeperException` relocated
- **Detection (symbol):** `\Elgg\GatekeeperException` → `\Elgg\Exceptions\Http\GatekeeperException`
  (latent fatal until the handler fires).
- **Fix:** rename the import/catch.
- **Test-to-write:** unit — `ExceptionClassRenames` rule rewrites the FQN.
- **gate:** rule (`V3ToV4/ExceptionClassRenames`) · **Sources:** bd memory elgg4-gatekeeper-relocated

### FC-3x4x-15 — `elgg_set_ignore_access()` removed
- **Detection (symbol):** `elgg_set_ignore_access(` → `elgg_call(ELGG_IGNORE_ACCESS, fn)`
  (`removed-functions.json[6.x]`; the AST rule handles the 3x→4x rewrite).
- **Fix:** wrap in `elgg_call(ELGG_IGNORE_ACCESS, function () { … })`.
- **Test-to-write:** unit — `ElggCallIgnoreAccess` rule rewrites the pattern.
- **gate:** rule (`V3ToV4/ElggCallIgnoreAccess`) + YES (≥6.x) · **Sources:** bd elgg-migrate-8o2b

---

## 4.x → 5.x

### FC-4x5x-01 — `add_translation()` removed
- **Detection (symbol):** `add_translation(` in language files (`removed-functions.json[5.x]`).
- **Fix:** `return [ … ];` directly — Translator `include`-loads the file and expects the array.
- **Test-to-write:** unit — `add_translation('en', …)` flags at `5.x`+; integration —
  `/settings/user` (account language) returns 200.
- **gate:** YES · **Sources:** cerebrum DNR 2026-04-28, bd elgg-migrate-mcau4

### FC-4x5x-02 — 5.x global function removals
- **Detection (symbol):** `get_current_language(`, `get_default_access(`,
  `check_entity_relationship(` (`removed-functions.json[5.x]`); also `current_page_url(` (removal
  version 5.x; currently pinned at `[6.x]` — see Known-refinements).
- **Fix:** `elgg_get_current_language()`; `elgg_get_config('default_access') ?? ACCESS_PUBLIC`;
  `elgg_get_relationships([...'limit'=>1])[0]`; `elgg_get_current_url()`.
- **Test-to-write:** unit — each flags at `5.x`.
- **gate:** YES · **Sources:** cerebrum DNR 2026-04-28, bd memory get-current-language-removed

### FC-4x5x-03 — Over-migration: 5.x event API leaks onto ≤4.x branches
- **Detection (future-api):** `elgg_trigger_event_results(` on a `3.x`/`4.x` target
  (`checkFunctions[5.x]`). The `hooks`→`events` key rename and
  `elgg_register_plugin_hook_handler`→`elgg_register_event_handler` are 5.x forms;
  `elgg_trigger_plugin_hook()` still works (deprecated) in 4.x/5.x, removed in 6.x.
- **Fix:** keep the target-appropriate API; do not sweep future patterns downward.
- **Test-to-write:** unit — `elgg_trigger_event_results()` flags `future-version-api` at `4.x`.
- **gate:** YES · **Sources:** bd memory agent-migration-pitfalls, `PostMigrationVerifier`

### FC-4x5x-04 — `ElggSession::setLoggedInUser()` moved to `session_manager`
- **Detection (regex):** `elgg_get_session()->setLoggedInUser(` /
  `ElggSession::setLoggedInUser` → `_elgg_services()->session_manager->setLoggedInUser($user)`.
- **Fix:** use the `session_manager` service (also `removeLoggedInUser()`).
- **Test-to-write:** unit — test bootstrap uses `session_manager`.
- **gate:** NO · **Sources:** cerebrum DNR 2026-04-20, 2026-04-28

### FC-4x5x-05 — 5.x DI/service + cache class removals
- **Detection (regex/symbol):** `PluginHooksService` / `\DI\get('hooks')` / `use ElggCache`;
  `elgg_entity_gatekeeper()` called without the GUID arg; `get_entity($stringGuid)` (needs `int`).
- **Fix:** drop `$hooks` from constructors/DI; `ElggCache`→`Elgg\Cache\BaseCache`;
  `elgg_entity_gatekeeper($guid)`; cast `(int)` before `get_entity()`.
- **Test-to-write:** unit — service class instantiates without the `hooks` DI entry.
- **gate:** NO · **Sources:** cerebrum DNR 2026-05-08

### FC-4x5x-06 — 5.x menu/JS API changes
- **Detection (regex):** `require(['jquery-ui'` (split into `jquery-ui/position` etc.);
  `elgg_view_menu_item(`; `array_keys($menu)` / `$menu[$section]` on a `PreparedMenu`;
  nested-array `views.extensions` (`['base' => ['ext' => []]]`).
- **Fix:** drop `jquery-ui`; `elgg_view('navigation/menu/elements/item', …)`; iterate
  `foreach ($menu as $section)`; use a plain string extension target.
- **Test-to-write:** unit — `AmdRemovedApis` / `JqueryUiRequires` rule rewrites the require array.
- **gate:** rule (`V3ToV4/AmdRemovedApis`, `JqueryUiRequires`) · **Sources:** cerebrum DNR 2026-04-20, bd memory jquery-ui-split

### FC-4x5x-07 — 5.x requires a non-empty subtype; `$e->subtype =` throws
- **Detection (regex):** entity `save()` with empty subtype; `$entity->subtype = $value` assignment;
  `MetadataField::getValues()` subclass returning a scalar (fatals `count()` in PHP 8).
- **Fix:** default groups to `'group'`; `$entity->setSubtype($value)`; getValues() must return
  `array_values([...stdClass...])`.
- **Test-to-write:** integration — creating a group with empty subtype fails cleanly / defaults.
- **gate:** NO · **Sources:** cerebrum DNR 2026-05-08

### FC-4x5x-08 — Test mocking: `Elgg\Event` has a required constructor
- **Detection (regex, tests):** `getMockBuilder(Event::class)->getMock()` without
  `disableOriginalConstructor()`; `disableOriginalConstructor()` on ElggEntity mocks (breaks `__set`).
- **Fix:** `disableOriginalConstructor()` for `Elgg\Event`; for entities use real factories or
  `onlyMethods([...])`.
- **Test-to-write:** (this IS the test guidance) — elgg-test-writer template note.
- **gate:** NO · **Sources:** cerebrum DNR 2026-05-08

---

## 5.x → 6.x

### FC-5x6x-01 — `\Elgg\Hook` removed entirely
- **Detection (class contract):** `use Elgg\Hook;` / type-hint `Hook $hook`
  (`changed-class-contracts.json[6.x]`, `illegal_keyword: use`). In 5.x it extended `\Elgg\Event`;
  in 6.x it is gone.
- **Fix:** `use Elgg\Event;`, `\Elgg\Event $event`, `$hook->`→`$event->`; rename a colliding local
  `$event = $hook->getParam('event')` to `$notification_event`.
- **Test-to-write:** unit — a handler importing `Elgg\Hook` flags `changed-class-contract` at `6.x`;
  `Elgg\HooksRegistrationService\Hook` does NOT.
- **gate:** YES · **Sources:** cerebrum DNR 2026-05-11, buglog bug-001

### FC-5x6x-02 — Plugin-hook procedural functions removed (6.x) + the 5.x message/redirect family
- **Detection (symbol):** the procedural plugin-hook family — `elgg_trigger_plugin_hook(`,
  `elgg_register_plugin_hook_handler(`, `elgg_unregister_plugin_hook_handler(`,
  `elgg_clear_plugin_hook_handlers(` — is a **6.x** removal (`removed-functions.json[6.x]`). The
  message/redirect family — `register_error(`, `system_message(`, `forward(`, `current_page_url(`,
  `elgg_get_version(` — is core-verified as a **5.x** removal (`removed-functions.json[5.x]`, commit
  d9df460); `elgg_redirect(` is a **3.x** removal. Do NOT treat the message/redirect family as 6.x.
- **Fix:** hook family → `elgg_trigger_event_results`, `elgg_register_event_handler`,
  `elgg_unregister_event_handler`, `elgg_clear_event_handlers` (auto-renamed at 5x->6x by
  `V5ToV6\RemovedFunctions`). Message/redirect family (auto-renamed at 4x->5x by
  `V4ToV5\RemovedFunctions`): `register_error`→**`elgg_register_error_message`** (NOT
  `elgg_register_error`, which does not exist); `system_message`→`elgg_register_success_message`;
  `forward`→`elgg_redirect_response`; `current_page_url`→`elgg_get_current_url`;
  `elgg_get_version`→`elgg_get_release`. `elgg_redirect`→`elgg_redirect_response` auto-renamed at
  2x->3x by `V2ToV3\RemovedFunctionRenames`.
- **Test-to-write:** unit — the hook family flags at `6.x`, the message/redirect family flags at
  `5.x`; a fixture calling `elgg_register_error_message()` (the correct replacement) is NOT flagged;
  `tests/Rules/Shared/RenameMapPlacementTest.php` asserts every rename block equals its
  removed-functions.json removal major.
- **gate:** YES · **Sources:** cerebrum DNR 2026-05-11, buglog bug-elgg7-register-error-name, bd
  elgg-migrate-jfrc1

### FC-5x6x-03 — `Seed` gained abstract `getType()` / `getCountOptions()` in 6.1
- **Detection (regex):** `extends \Elgg\Database\Seeds\Seed` (or imported `Seed`) in a class that
  lacks BOTH `function getType` and `function getCountOptions`. Autoload during handler validation
  makes the missing-method fatal fire on EVERY page load.
- **Fix:** implement `public static function getType(): string` and
  `public function getCountOptions(): array`.
- **Test-to-write:** unit — a `Seed` subclass missing either method is flagged; one with both passes.
- **gate:** NO — *not* in `changed-class-contracts.json` on purpose: the keyword gate would flag the
  still-correct `extends Seed` line on every migrated seeder. Needs a dedicated method-presence check.
- **Sources:** cerebrum DNR 2026-05-11, bd elgg-migrate-gqrjf, bd memory seeder-rule

### FC-5x6x-04 — `Elgg\Upgrade\Batch` became an abstract class
- **Boundary:** the change lands at **5.0**, not 6.0. Verified against upstream:
  `interface Batch` in Elgg 4.0, `abstract class Batch` in 5.0 and 6.0. (The FC id
  keeps its historical `5x6x` label; it was first observed at the 5x→6x tier
  because plugins skipped straight past it.)
- **Detection (class contract):** `implements Batch` (`changed-class-contracts.json[5.x]`,
  `illegal_keyword: implements`). Was an interface in 3.x-4.x. The verifier applies
  contracts cumulatively (`major <= target`), so this fires for any target ≥ 5.x.
- **Fix:** `extends \Elgg\Upgrade\AsynchronousUpgrade` (admin-run) or `\Elgg\Upgrade\SystemUpgrade`;
  implement `run(Result $result, $offset): Result`.
- **Test-to-write:** unit — `implements Batch` flags at `5.x` and `6.x`, is silent at `4.x`;
  `extends AsynchronousUpgrade` passes.
- **gate:** YES · **Sources:** `changed-class-contracts.json` (Reflection-verified),
  upstream Elgg 4.0/5.0 sources, verify-migration-chain 2026-06-05

### FC-5x6x-05 — AMD → ESM
- **Detection (symbol/future-api):** `elgg_load_js(`, `elgg_require_js(`, `elgg_define_js(`
  (`removed-functions.json[6.x]`); inversely `elgg_import_esm(` / `elgg_register_esm(` on a
  `≤5.x` branch (`checkFunctions[6.x]` — chain-contamination, bd xs2g6).
- **Fix:** register `.mjs` views + `elgg_import_esm()` / `elgg_register_esm()`.
- **Test-to-write:** unit — `elgg_require_js()` flags at `6.x`; `elgg_import_esm()` flags
  `future-version-api` at `4.x`.
- **gate:** YES · **Sources:** `removed-functions.json`, bd memory elgg7-importmap, bd elgg-migrate-xs2g6

---

## 6.x → 7.x

### FC-6x7x-01 — `ELGG_CACHE_PERSISTENT` removed
- **Detection (symbol):** constant `ELGG_CACHE_PERSISTENT` (documented in
  `removed-functions.json[7.x]`). NB: a bare constant has no `(` — the call-shaped gate does NOT
  flag it; needs a constant scan.
- **Fix:** the persistent cache layer was dropped in 7.x — use `ELGG_CACHE_RUNTIME` /
  `ELGG_CACHE_FILESYSTEM`.
- **Test-to-write:** unit — a constant scan flags `ELGG_CACHE_PERSISTENT` at `7.x`.
- **gate:** NO (call-shaped gate blind to constants) · **Sources:** bd elgg-migrate-75ax8

### FC-6x7x-02 — `ElggObject` abstract; `elgg_new_entity()` removed
- **Detection (symbol/regex):** `elgg_new_entity(` (`removed-functions.json[7.x]`); `new \ElggObject`
  with no subtype (ElggObject is abstract in 7.x).
- **Fix:** `new \ElggUndefinedObject()` / `new \ElggObject()` then set `->subtype`.
- **Test-to-write:** unit — `elgg_new_entity(` flags at `7.x`; integration — object create returns 200.
- **gate:** partial (`elgg_new_entity` YES) · **Sources:** cerebrum feedback_elgg7_core_breaks, `removed-functions.json`

### FC-6x7x-03 — Menu `register` value is an array, not `->add()`
- **Detection (regex):** `$return->add(` / `$menu->add(` inside a `register`/menu handler returning
  a value.
- **Fix:** `$return[] = \ElggMenuItem::factory([...]); return $return;`.
- **Test-to-write:** integration — the menu renders its custom item on 7.x.
- **gate:** NO · **Sources:** cerebrum feedback_elgg7_core_breaks

### FC-6x7x-04 — 7.x global function removals
- **Detection (symbol):** `elgg_is_admin_user(`, `elgg_get_logged_in_user(`,
  `elgg_get_entities_from_relationship(`, `elgg_is_registered_viewtype(`,
  `elgg_get_registered_entity_types(`, `elgg_reset_system_cache(`, `elgg_geocode_location(`
  (`removed-functions.json[7.x]`).
- **Fix:** see per-symbol replacements in `removed-functions.json` (e.g.
  `elgg_reset_system_cache()`→`_elgg_services()->systemCache->clear()`).
- **Test-to-write:** unit — each flags at `7.x`.
- **gate:** YES · **Sources:** `removed-functions.json`, bd memories elgg-7-x-*

### FC-6x7x-05 — CSS view relocation (`css/elements/X` → `elements/X.css`)
- **Detection (file):** core-view overrides at `views/default/css/elements/*.php` (orphaned — HTTP
  200 but unstyled); theme still styling legacy `.elgg-state-<type>` (renamed to `.elgg-message-<type>`).
- **Fix:** relocate overrides with a 7.x counterpart to `views/default/elements/*.css`; extend
  `elgg.css` for no-counterpart files; rename `.elgg-state-*`→`.elgg-message-*`.
- **Test-to-write:** e2e — snapshot-diff anonymous pages across versions (drift gate).
- **gate:** NO (see `scan-frontend-residue.sh`) · **Sources:** cerebrum feedback_elgg7_css_view_relocation, buglog bug-024, bug-042

### FC-6x7x-06 — ESM importmap key = full view path minus `.mjs` (no `js/` strip)
- **Detection (regex):** bare specifiers like `'framework/gallery/init'` or
  `'elements/navigation/dropdown'` that omit the `js/`-prefixed view path; unmapped vendored libs.
- **Fix:** prefix specifiers with the real view path (`js/framework/gallery/*`); register vendored
  libs in the importmap; verify against the rendered importmap JSON.
- **Test-to-write:** e2e — console has no "Failed to resolve module specifier" on each page type.
- **gate:** NO · **Sources:** buglog bug-033, bug-1x2a3, bd elgg-migrate-k60rr, bd memory elgg7-importmap

### FC-6x7x-07 — jQuery no longer a global (deferred ESM)
- **Detection (regex):** `window.jQuery` / bare `jQuery` / `$` used without `import('jquery')`;
  vendored libs calling `.load` (jQuery 3 removed it); third-party GTM tags referencing `window.jQuery`.
- **Fix:** `import('jquery')` from the importmap and expose `window.jQuery`/`$` before dependent code.
- **Test-to-write:** e2e — no `$ is not defined` / `jQuery is not defined` pageerror.
- **gate:** NO · **Sources:** buglog bug-035, bd elgg-migrate-b1wmu, bd elgg-migrate-nehf2

### FC-6x7x-08 — `elgg/i18n` has a DEFAULT export only
- **Detection (regex):** `import { echo } from 'elgg/i18n'` (or any named import from `elgg/i18n`).
- **Fix:** `import i18n from 'elgg/i18n'; const echo = (...a) => i18n.echo(...a);`.
- **Test-to-write:** vitest — module imports resolve; `i18n.echo` is callable.
- **gate:** NO · **Sources:** buglog bug-036

### FC-6x7x-09 — `elgg_format_element('')` rejects an empty tag name
- **Detection (regex):** `elgg_format_element\(\s*['"]{2}\s*,` (empty first arg).
- **Fix:** emit `htmlspecialchars((string) $value)` directly instead of a zero-tag element.
- **Test-to-write:** integration — a form with an embed-enabled longtext field renders (no
  `$tag_name is required`).
- **gate:** NO · **Sources:** buglog bug-018

### FC-6x7x-10 — Doctrine DBAL named-param keys must NOT carry the colon
- **Detection (regex):** `executeStatement`/`executeQuery` param arrays with keys like
  `[':relationship_id' => …]`.
- **Fix:** drop the `:` from every param-array key (DBAL matches `:name` to key `name`).
- **Test-to-write:** integration — the custom-table write path succeeds (no `MissingNamedParameter`).
- **gate:** NO · **Sources:** buglog bug-038

### FC-6x7x-11 — `canWriteToContainer()` requires a non-null string subtype
- **Detection (regex):** `canWriteToContainer(` with `$subtype` defaulted to
  `ELGG_ENTITIES_ANY_VALUE`/`null`, or referenced before assignment.
- **Fix:** resolve `$subtype` (e.g. `Group::SUBTYPE`) before the container check.
- **Test-to-write:** e2e/write — `/groups/add` returns 200 for an authed user.
- **gate:** NO · **Sources:** buglog bug-017

### FC-6x7x-12 — Members/search moved to route controllers (bypass legacy hooks)
- **Detection (regex/behavioral):** custom `members:list` event handlers; method call inside a
  double-quoted string without braces (`"members/listing/$event->getType()"` interpolates the
  property + literal `()`).
- **Fix:** re-register the `collection:user:*` routes / brace method calls
  (`"{$event->getType()}"`); wire `elgg_search`.
- **Test-to-write:** e2e — `/members` and `/members/{newest,online,all}` return 200.
- **gate:** NO · **Sources:** buglog bug-034, bd elgg-migrate-dhwqm, bd elgg-migrate-fedz2

### FC-6x7x-13 — Install: minimum password length raised to 16
- **Detection (config):** `min_password_length` default 16; admin password `< 16` chars →
  `batchInstall` fails admin creation silently.
- **Fix:** use a ≥16-char admin password in install scripts.
- **Test-to-write:** infra — Docker install completes and `/admin` is reachable.
- **gate:** NO · **Sources:** bd memories elgg7-admin-password-min-length, elgg7-admin-creation-misleading-error

---

### FC-6x7x-14 — Core functions took non-nullable `string` (a 500 only real data can trigger)
- **Detection (script):** `bin/verify-strict-string-args.sh <plugins-dir>`. Flags a PROPERTY read
  (`$e->description`, `$e->$prop`) passed straight to `elgg_get_excerpt(string $text, int)` or
  `elgg_strip_tags(string $string, ?string)`. Method calls are safe (`getDisplayName(): string`).
- **Fix:** `(string)` cast, or keep an existing truthiness guard. See rule
  `035-strict-string-params` in `rules/6x-to-7x/manifest.json`.
- **Why nothing caught it:** PHP was lenient before 8.x; the AST rules only hunt removed symbols;
  and synthetic smoke tests seed entities that always HAVE a description. It fires only against
  real data. On bodyology it fataled `/profile/{username}` for every member (hypeseo's SEF
  metadata builder) and `/folders/all`, and lay dormant in seven more plugins.
- **Careful:** `strip_tags(null)` / `trim(null)` are DEPRECATIONS, not fatals. Do not confuse them.
- **Test-to-write:** integration — render an entity with no description.
- **gate:** rule (035) + `verify-strict-string-args.sh` · **Sources:** bodyology 2026-07-09

### FC-6x7x-15 — A permission handler that calls the API which fires its own event (infinite recursion)
- **Detection (regex):** inside a handler registered on `permissions_check:comment`, a call to
  `$entity->canComment(...)`. `UserCapabilities::canComment()` TRIGGERS that same event.
- **Symptom:** `Maximum call stack size ... reached. Infinite recursion?` — a hard 500 for every
  logged-in NON-admin. Admins are immune: `canBypassPermissionsCheck()` short-circuits before the
  event fires, so the page looks fine to whoever is testing.
- **Fix:** return the value core already computed and passed in as the event's default —
  `canWriteToContainer($user->guid, 'object', 'comment')` — never re-enter the event.
- **Also:** these handlers receive `null` for an anonymous visitor. `canComment(null)` /
  `canWriteToContainer(null)` are TypeErrors. Guard `$user instanceof \ElggUser` first.
- **Test-to-write:** integration — call the real `$discussion->canComment($user->guid)`, so a
  reintroduced recursion blows up in the suite rather than in production.
- **gate:** NO · **Sources:** bodyology 2026-07-09 (hypediscussions)

### FC-6x7x-16 — `elgg.security` / AMD `require()` / `.js` ESM views
- **Detection (regex):** `elgg.security.` in any `.mjs`/`.js`; `require([` in a PHP view or JS;
  an ESM-syntax file with a `.js` extension.
- **Fix:**
  - `elgg.security.addToken(...)` → `import security from 'elgg/security'`. Elgg 7 does not hang
    `security` off the global `elgg`. It was a TypeError on every page with a dropzone input, so
    chunked uploads never started.
  - `require(['x'], cb)` → `elgg_import_esm('x')` + an inline `<script type="module">`. The AMD
    loader is gone; it is a bare `ReferenceError: require is not defined`.
  - Elgg 7 registers **only `.mjs` views** in the importmap. An ESM-syntax `.js` view yields
    `Failed to resolve module specifier`. Rename it.
  - There is no `elgg/events` module; the client bus is the DEFAULT export of `elgg/hooks`
    (`hooks.register(...)`, `hooks.trigger(...)`). There is no `elgg/components/` directory and no
    RequireJS `text!` plugin.
  - A UMD jQuery plugin (select2, tokeninput) needs a GLOBAL jQuery, which Elgg 7 no longer
    exposes. Set `window.jQuery = window.$ = $` and then load it with a **dynamic** `await
    import('select2')` — a static import is hoisted above the assignment.
- **gate:** NO · **Sources:** bodyology 2026-07-10 (hypeajax, hypeautocomplete, hypedropzone)

### FC-6x7x-17 — `$path` heuristics resolve to the Elgg root under composer
- **Detection (regex):** in `elgg-plugin.php`, a views alias built from a `$path` chosen by
  `file_exists("$plugin_root/vendor/autoload.php")`.
- **Fix:** use `__DIR__` (`$plugin_root`) for anything that lives beside `elgg-plugin.php`.
  Composer installs a plugin WITHOUT its own `vendor/`, so the heuristic falls through to
  `/var/www/html` and the alias points at `/var/www/html/vendors/<lib>/`. It only "works" in
  development, where a stray plugin-local `composer install` left a `vendor/` behind.
- **Symptom:** `RecursiveDirectoryIterator::__construct(...): Failed to open directory` at boot.
- **gate:** NO · **Sources:** bodyology 2026-07-10 (hypeautocomplete)

## Upgrade batches (`Elgg\Upgrade\Batch`) — the silent-skip family

Every failure below is invisible to a static gate, to a render battery, and to a route crawl.
An orphaned upgrade breaks **data**, not code: entities keep their rows and lose their subtype,
their class and their URL. Nothing 404s, because nothing links to them any more.

Detect the whole family in one query, on the migrated database:

```sql
SELECT e.guid, MAX(CASE WHEN m.name='class' THEN m.value END) AS class
FROM elgg_entities e JOIN elgg_metadata m ON m.entity_guid = e.guid
WHERE e.subtype = 'elgg_upgrade'
GROUP BY e.guid
HAVING MAX(CASE WHEN m.name='is_completed' THEN m.value END) IN ('0') OR
       MAX(CASE WHEN m.name='is_completed' THEN m.value END) IS NULL;
```

`references/7x-prune-orphaned-upgrades.php` (in `elgg-site-upgrade`) classifies each hit.

### FC-UPG-01 — `elgg-cli upgrade` silently skips every asynchronous batch
- **Detection (process):** the chain/cutover runs `elgg-cli upgrade` without the `all` argument.
  After the run, the query above still returns rows.
- **Fix:** `elgg-cli upgrade all -n -f`. `all` runs the asynchronous `Elgg\Upgrade\Batch` jobs;
  bare `upgrade` runs only the SYSTEM (Phinx-adjacent) upgrades. `-n` because the bare command
  blocks on a confirmation prompt and looks like a hang; `-f` to force past the lock an aborted
  run leaves in `elgg_config.upgrade_running`.
- **Consequence if missed:** upgrades accumulate "pending" tier after tier. When a later Elgg
  release DELETES the upgrade class, the work becomes permanently unreachable — see FC-UPG-02.
- **Test-to-write:** integration — after the chain, assert zero pending `elgg_upgrade` entities.
- **gate:** NO (data) · **Sources:** bodyology 2026-07-10; `verify-migration-chain.sh` fixed

### FC-UPG-02 — Orphaned upgrade: the class no longer exists (work never happens)
- **Detection (data):** a pending `elgg_upgrade` entity whose `class` metadata fails
  `class_exists()`. `UpgradeService::getPendingUpgrades()` silently drops any upgrade whose batch
  cannot be instantiated, so it never runs and never errors.
- **Fix:** do the work by hand, then delete the entity. Two mattered on bodyology and BOTH lost
  production content:
  - `\Elgg\Pages\Upgrades\MigratePageTop` — 85 pages stranded on the `page_top` subtype Elgg 3
    removed. They load as `ElggUndefinedObject`, `getURL()` returns `''`, `/pages/view/{guid}`
    404s. Fix: `references/7x-migrate-page-top.sql`.
  - `\Elgg\Discussions\Upgrades\MigrateDiscussionReply(+River)` — 17 replies stranded on
    `discussion_reply`. Every discussion showed ZERO comments. Fix:
    `references/7x-migrate-discussion-replies.sql`.
  Also seen dead: `SetSecurityConfigDefaults`, `MigrateFriendsACL`, `MigrateCronLog`,
  `SecurityEmailChangeConfirmation`, `TrackValidationStatus`, `GroupIconTransfer`,
  `PublicLikesAnnotations`, `AnnotationMigration`, `MoveFiles`. Verify each against live data
  before assuming it was a no-op.
- **Test-to-write:** integration — for every object subtype present, assert `getURL() !== ''`.
  A route crawl cannot see this; an entity with no URL is simply never linked.
- **gate:** NO (data) · **Sources:** bodyology 2026-07-10

### FC-UPG-03 — Orphaned upgrade: camelCase twin from the 4.x plugin-id lowercasing
- **Detection (data):** two `elgg_upgrade` entities share a `class`; the one whose `id` carries a
  camelCase plugin prefix (`hypeMaps:2026041200`) is pending while its lowercase twin
  (`hypemaps:2026041200`) is completed. An upgrade's identity is `{plugin_id}:{version}`.
- **Fix:** delete the camelCase entity. Same family as the stranded plugin settings —
  `references/4x-post-lowercase-plugin-settings.sql`.
- **gate:** NO (data) · **Sources:** bodyology 2026-07-10

### FC-UPG-04 — A batch that never terminates (constant `countItems()`)
- **Detection (regex):** `needsIncrementOffset(): bool` returning `false` in a class whose
  `countItems()` returns a constant (`return 1;`, `return count(self::SOME_ARRAY);`) **or** a
  plain entity count that `run()` never reduces.
- **Why:** `Elgg\Upgrade\Loop::isCompleted()` only ends a `needsIncrementOffset() === false`
  batch when `countItems()` SHRINKS TO ZERO. With `true` it ends on `processed >= count`.
  So `false` is a promise that `run()` deletes/converts rows and the count falls.
- **Fix:** if `run()` does all its work in one pass, return `true`. If it pages with `$offset`,
  return `true` (Elgg only advances the offset when it is `true` — otherwise `run()` re-fetches
  the same first page forever). Only return `false` when `countItems()` genuinely counts
  remaining work, and make sure an unconvertible row is REMOVED, not skipped, or the count
  never reaches zero.
- **Symptom:** the CLI progress bar climbs past the item count without end — hypeinbox's reached
  1.5 million, anypage's 987,000 — and everything queued behind it stays pending.
- **Test-to-write:** unit — assert `needsIncrementOffset() === true` whenever `countItems()` is
  not a function of remaining work.
- **gate:** NO (behaviour) · **Sources:** bodyology 2026-07-10 (hypeinbox, hypediscovery,
  elgg_stars, prototyper_group, anypage, videolist — six plugins, one shape)

### FC-UPG-05 — One failing batch aborts the whole run
- **Detection (runtime):** `Unhandled promise rejection with RuntimeException: Upgrade "…" failed`.
  `UpgradeService::runUpgrades()` rejects the promise if `Result::getFailureCount()` is non-zero,
  and `all($promises)` rejects the batch, so NOTHING after it runs.
- **Fix:** a batch must never `addFailures()` for a row it can never convert. Delete the row (log
  a notice) and `addSuccesses()`. Real instances:
  - `hypegallery` counted two 2016-era serialized `ElggBatch` OBJECTS as failures. With
    `allowed_classes: false` they decode to `__PHP_Incomplete_Class`, never an array, so they can
    never become JSON. They are dead cache AND an object-injection vector — delete them.
  - `hypescraper` called `Result::addFailure()`. **No such method** — it is `addFailures()`. The
    first unparseable row was a fatal, not a failure.
  - core's `\Elgg\Upgrades\AlterDatabaseToMultiByteCharset` ALTERs `elgg_private_settings`,
    a table Elgg 4 removed. It dies before converting anything. Do the conversion yourself
    (`references/7x-utf8mb4-plugin-tables.sql`) and mark the upgrade completed.
- **Also:** `elgg_log($msg, 'NOTICE')` throws in Elgg 7 — pass `\Psr\Log\LogLevel::NOTICE`.
- **gate:** NO (runtime) · **Sources:** bodyology 2026-07-10

### FC-UPG-06 — A batch querying `elgg_private_settings`
- **Detection (symbol):** `private_settings` in any SQL string outside a comment.
- **Fix:** Elgg 4 removed the table and moved plugin settings into metadata. Read them with
  `$plugin->getAllMetadata()`. Note `getAllSettings()` MERGES `elgg-plugin.php` defaults and can
  never tell you whether a key is actually stored.
- **gate:** NO · **Sources:** bodyology 2026-07-10 (prototyper_group)

## All version steps (version-agnostic)

### FC-ALL-01 — `unserialize()` PHP object injection (RCE)
- **Detection (security):** `unserialize(` without `allowed_classes => false` (`SecuritySweep`
  CRITICAL). Legacy plugins store serialized blobs.
- **Fix:** `json_decode()` or `unserialize($s, ['allowed_classes' => false])`; migrating stored
  data serialize→json MUST ship an `Elgg\Upgrade\Batch`.
- **Test-to-write:** unit — a raw `unserialize(` yields an `unserialize` error violation.
- **gate:** YES · **Sources:** bd elgg-migrate-2mra, bd elgg-migrate-f2sy, `SecuritySweep`

### FC-ALL-02 — serialize→json / URL rewrite corrupts serialized length prefixes (data)
- **Detection (data):** a plain SQL `REPLACE` over PHP-serialized blobs (breaks `s:<len>:` prefixes
  → `unserialize()` fails); switching `serialize()`→`json_encode()` for stored data without a Batch.
- **Fix:** per-row `unserialize → str_replace → serialize` via DBAL, or a proper `Batch`.
- **Test-to-write:** integration — round-trip the migrated blob and assert it unserializes.
- **gate:** NO · **Sources:** buglog bug-044, MEMORY feedback_serialize_to_json_migration

### FC-ALL-03 — Dangling upgrade-class registration
- **Detection (config):** a class under `elgg-plugin.php 'upgrades' => [ … ]` that resolves to no
  file (`checkDanglingUpgradeClasses`). `Foo::class` on an undefined class does NOT autoload, so
  pages render but `elgg-cli upgrade` aborts ("Upgrade class … was not found").
- **Fix:** remove the stale registration or restore/rename the class.
- **Test-to-write:** unit — a registered-but-absent upgrade class flags `dangling-upgrade-class`.
- **gate:** YES · **Sources:** buglog bug-015, bug-027, bd elgg-migrate-kg3kb

### FC-ALL-04 — Forward-port regression (remediation resurrects legacy signatures)
- **Detection (regex):** legacy 3-arg event handler `function \w+\(\$event,\s*\$type,\s*\$object\)`
  reappearing on a higher branch; entity-subclass method signatures that don't match core
  (`canComment($user_guid = 0, $default = null)` vs `ElggComment::canComment(int $user_guid = 0): bool`).
- **Fix:** keep the single-object `\Elgg\Event` signature and core-matching method signatures; a
  bulk remediation pass can REGRESS files already fixed on earlier branches — grep before/after.
- **Test-to-write:** integration — deleting/commenting an object does not fatal (data-dependent
  render battery).
- **gate:** NO · **Sources:** buglog bug-013, bug-039, bd elgg-migrate-zjvx

### FC-ALL-05 — `route:rewrite` fires at boot, BEFORE `init`
- **Detection (timing/config):** a `route:rewrite` handler registered via `elgg-plugin.php` `events`
  (i.e. at `init`) instead of `Bootstrap::boot()`; any service touched in the handler must be
  boot-available (a filesystem cache with wrong perms 500s every page).
- **Fix:** register the handler in `Bootstrap::boot()` early; guard early-boot services with
  `ELGG_CACHE_RUNTIME` fallback / lazy init.
- **Test-to-write:** e2e — pretty/SEF entity URLs resolve (no site-wide 404), home page 200 under a
  cache-permission fault.
- **gate:** NO — *not* a class contract (no class/keyword), so intentionally absent from
  `changed-class-contracts.json`. · **Sources:** cerebrum DNR 2026-05-08, buglog bug-045, bug-046

### FC-ALL-06 — `elgg-plugin.php` include-time side effects; class constants for subtype
- **Detection (regex):** filesystem/`mkdir` side effects above the `return [ … ]`; `MyClass::SUBTYPE`
  / `\Ns\Class::class` used for subtype/class values in the `entities` block (the file is parsed
  before the classes/ autoloader is wired).
- **Fix:** move side effects to `Bootstrap::boot()`/`init()`; use `'class' => 'Ns\\Class'` string
  literals in the `entities` block.
- **Test-to-write:** unit — `elgg-plugin.php` returns an array with no side effects; entities block
  uses string literals.
- **gate:** NO · **Sources:** cerebrum DNR 2026-04-15, bd memory elgg-plugin-php-do-not-use-class-constants, MEMORY feedback_elgg_plugin_class_string_literals

### FC-ALL-07 — `hype*` `lib/functions.php` global helpers not loaded in time
- **Detection (regex):** procedural helpers defined in `lib/functions.php` but declared only via
  composer `autoload.files` (too late for git-tracked customs) or `Bootstrap` `require_once`.
- **Fix:** `require_once __DIR__ . '/lib/functions.php';` at the TOP of `elgg-plugin.php`.
- **Test-to-write:** integration — a helper defined in `lib/functions.php` is callable at boot.
- **gate:** NO · **Sources:** MEMORY feedback_hype_plugin_lib_functions_autoload, feedback_git_tracked_plugin_global_helpers

### FC-ALL-08 — Optional plugin deps called unguarded
- **Detection (regex):** calls to functions/services provided by optional deps (hypeLists,
  hypeShortcode, …) without a `function_exists()` / `elgg()->has(...)` guard.
- **Fix:** guard every optional-dep callsite; without it the plugin fails to activate / renders
  errors in a standalone Docker stack.
- **Test-to-write:** integration — the plugin activates in a stack WITHOUT the optional dep.
- **gate:** NO · **Sources:** cerebrum DNR 2026-04-15

---

### FC-ALL-13 — A route whose `resource` view does not exist (permanent, invisible 404)
- **Detection (script):** `bin/verify-route-resources.sh <plugins-dir> [core-dir]`. For every
  `'resource' => 'x/y'` in `elgg-plugin.php` (or an `elgg_register_route()` call), assert
  `views/default/resources/x/y.php` exists somewhere on the view path.
- **The shape:** a 2.x page handler ported as a catch-all —
  `['path' => '/library/{segments}', 'resource' => 'library']` — where the plugin only ever had
  `resources/library/{all,view,edit}.php` and the old handler switched on `$page[0]` itself.
  `resources/library.php` never existed. `elgg_view_resource()` raises
  `ResourceNotFoundException` and EVERY `/library/*` URL 404s, including the permalink
  `getURL()` advertises.
- **Why nothing catches it:** the plugin activates cleanly, boots cleanly, and a route crawl
  never requests a URL nothing links to. Only asking each ENTITY for its own URL finds it.
- **Fix:** name each route explicitly, one resource view per path. Route parameters reach a
  resource view through `$vars` **only** — `Elgg\Router` never calls `setParam()`, so
  `get_input('guid')` reads null and the gatekeeper throws. Use
  `elgg_extract('guid', $vars, get_input('guid'))`.
- **Also:** a page that streamed bytes with raw `header()`/`readfile()` cannot be a resource view
  in Elgg 7. Use `elgg_download_response()` (core, since 5.0).
- **Test-to-write:** integration — fetch `$entity->getURL()` for one entity of every subtype.
- **gate:** `verify-route-resources.sh` · **Sources:** bodyology 2026-07-10 (bodyology_library,
  bodyology_feedback, feedback were live regressions; 9 more latent — bd elgg-migrate)

## Known data-file refinements

- **RESOLVED 2026-07-08 — removal-version placement is now core-verified.** A
  `function_exists()` sweep against real 3.x/4.x/5.x/6.x/7.x cores (via
  `bin/verify-removed-functions.sh` + `Elgg\Application::loadCore()`) re-placed 38
  functions to their EARLIEST-absent major (their true removal floor), e.g.
  `elgg_set_plugin_setting`/`elgg_set_ignore_access`/`elgg_instanceof` → **4.x**;
  `current_page_url`/`elgg_view_menu_item`/`forward`/`register_error`/`system_message`
  → **5.x** (empirically gone at 5.1, NOT 6.x as this project's history-reconstructed
  notes guessed — a 4.x/5.x-target migration now flags them). Zero placements were
  contradicted (the old data was late, never wrong). Rerun the tool after any Elgg
  release to keep it honest. The auto-rewrite side is now reconciled too (bd
  elgg-migrate-jfrc1): `removed-function-renames.json` moved the message/redirect family to its
  `5.x` block (consumed by `V4ToV5\RemovedFunctions`), `elgg_redirect`/`_elgg_rmdir`/
  `_elgg_html_decode`/`elgg_get_logged_in_user` to `3.x` and `elgg_flush_caches` to `4.x`
  (each with a per-step data-driven consumer), and FC-5x6x-02 above now narrates them at their
  true majors. `tests/Rules/Shared/RenameMapPlacementTest.php` fails the build if any rename
  block drifts from removed-functions.json.
- **`ElggFile::detectMimeType`** and **`ELGG_CACHE_PERSISTENT`** are documented in
  `removed-functions.json` but only partially / not caught by the call-shaped gate (instance-method
  form; bare constant). A dedicated method-call + constant scanner would close these.
- **`Seed` missing `getType()`/`getCountOptions()`** needs a method-presence verifier check (see
  FC-5x6x-03) — it cannot be expressed by the keyword-based `changed-class-contracts` gate.

---

## Gate coverage audit (bd elgg-migrate-99qbj, 2026-07-09)

Evidence-based three-bucket sort of all **53** FC classes. Verified by mapping each entry to its
`PostMigrationVerifier`/`SecuritySweep`/AST-rule check and to a passing test in
`skills/elgg-migrate/tests`; the full engine suite is green (692 tests, 0 failures). Some per-entry
`gate:` lines above are stale relative to this table — the table is authoritative.

**Bucket (a) — statically gated by a passing test (48):**

| FC | check / rule | test |
|----|--------------|------|
| FC-2x3x-01 | removed-function[3.x] | Fc2xTo3xGateTest |
| FC-2x3x-02 | check3xStartPhpExists | Fc2xTo3xGateTest |
| FC-2x3x-03 | checkSearchHookReturn | Fc2xTo3xGateTest, FailureCatalogGateTest |
| FC-2x3x-04 | checkSiteSecretScrub | Fc2xTo3xGateTest, FailureCatalogGateTest |
| FC-3x4x-01 | checkForbiddenFiles | Catalog3xTo4xGateTest |
| FC-3x4x-02 | removed-function + CssRegistration rule | Catalog3xTo4xGateTest |
| FC-3x4x-03 | removed-function[4.x] | Catalog3xTo4xGateTest |
| FC-3x4x-04 | checkDetectMimeTypeInstance (partial) | Catalog3xTo4xGateTest, FailureCatalogGateTest |
| FC-3x4x-05 | removed-function[4.x] | Catalog3xTo4xGateTest |
| FC-3x4x-06 | removed-function[4.x] | Catalog3xTo4xGateTest |
| FC-3x4x-07 | changed-class-contract[4.x] | Catalog3xTo4xGateTest |
| FC-3x4x-08 | changed-class-contract[4.x] | Catalog3xTo4xGateTest |
| FC-3x4x-09 | check4xHookEventConfusion | Catalog3xTo4xGateTest |
| FC-3x4x-10 | LowercasePluginIdCallsites rule | Catalog3xTo4xGateTest, LowercasePluginIdCallsitesTest |
| FC-3x4x-12 | checkLegacyHandlerSignature | Catalog3xTo4xGateTest, FailureCatalogGateTest |
| FC-3x4x-13 | checkInstallSqlNotAutoRun | Catalog3xTo4xGateTest, FailureCatalogGateTest |
| FC-3x4x-14 | ExceptionClassRenames rule | FailureCatalogGateTest |
| FC-3x4x-15 | ElggCallIgnoreAccess rule (+removed[6.x]) | ElggCallIgnoreAccessTest |
| FC-4x5x-01 | removed-function[5.x] | PostMigrationVerifier4xTo5xTest |
| FC-4x5x-02 | removed-function[5.x] | RemovedFunctionsTest(V4ToV5), PostMigrationVerifier4xTo5xTest |
| FC-4x5x-03 | checkFunctions future-api[5.x] | PostMigrationVerifier4xTo5xTest |
| FC-4x5x-04 | checkRelocatedSymbols (relocated-symbol) | FailureCatalogGateTest |
| FC-4x5x-05 | check5xServiceRemovals | FailureCatalogGateTest |
| FC-4x5x-06 | AmdRemovedApis / JqueryUiRequires rules | FailureCatalogGateTest, AmdRemovedApisTest |
| FC-4x5x-07 | check5xSubtypeAssignment | FailureCatalogGateTest |
| FC-4x5x-08 | check5xTestMocking | FailureCatalogGateTest |
| FC-5x6x-01 | changed-class-contract[6.x] | FailureCatalog5xTo6xTest |
| FC-5x6x-02 | removed-function[6.x]/[5.x] | FailureCatalog5xTo6xTest, RenameMapPlacementTest |
| FC-5x6x-03 | checkSeedAbstractMethods | FailureCatalog5xTo6xTest, FailureCatalogGateTest |
| FC-5x6x-04 | changed-class-contract[6.x] | FailureCatalog5xTo6xTest |
| FC-5x6x-05 | removed-function[6.x] + future-api | FailureCatalog5xTo6xTest |
| FC-6x7x-01 | checkRemovedConstants | FailureCatalogGateTest |
| FC-6x7x-02 | removed-function elgg_new_entity (partial) | PostMigrationVerifier6xTo7xTest |
| FC-6x7x-03 | checkMenuAddValue | FailureCatalogGateTest |
| FC-6x7x-04 | removed-function[7.x] + ResetSystemCache rule | ResetSystemCacheTest, PostMigrationVerifier6xTo7xTest |
| FC-6x7x-05 | checkCssViewRelocation | FailureCatalogGateTest |
| FC-6x7x-06 | checkEsmBareSpecifiers | FailureCatalogGateTest |
| FC-6x7x-07 | checkJqueryGlobal | FailureCatalogGateTest |
| FC-6x7x-08 | checkI18nNamedImport | FailureCatalogGateTest |
| FC-6x7x-09 | checkEmptyFormatElement | FailureCatalogGateTest |
| FC-6x7x-10 | checkDbalColonParams | FailureCatalogGateTest |
| FC-6x7x-11 | checkCanWriteToContainerSubtype | FailureCatalogGateTest |
| FC-6x7x-12 | checkUnbracedMethodInterpolation | FailureCatalogGateTest |
| FC-6x7x-13 | checkAdminPasswordLength | FailureCatalogGateTest |
| FC-ALL-01 | SecuritySweep unserialize | SecuritySweepTest |
| FC-ALL-03 | checkDanglingUpgradeClasses | PostMigrationVerifierTest |
| FC-ALL-05 | checkRouteRewriteTiming | FailureCatalogGateTest |
| FC-ALL-06 | checkElggPluginSideEffects | FailureCatalogGateTest |
| FC-ALL-07 | checkLibFunctionsAutoload | FailureCatalogGateTest |
| FC-ALL-08 | checkUnguardedOptionalDeps | FailureCatalogGateTest |

**Bucket (b) — gate: NO by design; no static gate is possible (5, one partial):**

- **FC-3x4x-11** camelCase→lowercase strands plugin SETTINGS — DB-state/data; verifiable only by an
  integration settings round-trip (ships an `Elgg\Upgrade\Batch`).
- **FC-6x7x-02** *(partial)* the `elgg_new_entity()` removal IS gated (bucket a); the residual
  `new \ElggObject` abstract-instantiation subset is a render/integration concern.
- **FC-ALL-02** serialize→json / URL-rewrite corrupts serialized length prefixes — data round-trip;
  needs a Batch + integration unserialize assertion.
- **FC-ALL-04** *(partial)* the static 3-arg legacy-handler subset IS caught by
  checkLegacyHandlerSignature (bucket a); the data-dependent forward-port render battery is by design.
- **FC-ALL-05..08, FC-6x7x-05..13** all carry an additional render/e2e "test-to-write" beyond their
  static gate — the static half is covered (bucket a); the render half is intentionally e2e-only.

**Bucket (c) — genuinely uncovered though a static gate is possible: EMPTY (0).**

Conclusion: every FC class that admits a static gate already has one plus a passing test; no static
gate tests remain to be written. Bead elgg-migrate-99qbj can be CLOSED.
