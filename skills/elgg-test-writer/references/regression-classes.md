# Regression bug classes → test assertions

`bin/scaffold-smoke-tests.sh` emits **two** static source-scan guards (no Elgg
boot — most of these classes fatal *at class load or page render*, so a booted
test crashes before it can assert; catch the pattern in the source instead):

- `tests/phpunit/unit/RegressionTest.php` (from `templates/RegressionTest.php.template`)
  — the **standing** guard, hard-wired to the Elgg 7.x fatal classes. Runs on
  every plugin as a permanent CI net.
- `tests/phpunit/unit/MigrationRegressionTest.php` (from
  `templates/MigrationRegressionTest.php.template`) — the **tests-first**
  guard, parameterized by `const TARGET_MAJOR`. It is the RED-before /
  GREEN-after gate for a specific migration step and covers the full
  target-gated catalog (removed functions/constants/`detectMimeType`, changed
  class contracts, forbidden/required bootstrap files, camelCase plugin-id
  callsites, hook/event confusion, Seed/`Batch` shape, `add_translation`,
  unsafe `unserialize`, `route:rewrite@init`, non-literal manifest entities,
  incompatible core overrides, menu `->add()`, orphaned css). Its embedded maps
  mirror `elgg-migrate/references/{removed-functions.json,changed-class-contracts.json,migration-failure-catalog.md}`
  — keep them in lock-step when a new removal or contract change lands.

The table below documents the **standing 7.x guard** (`RegressionTest`).

Each row below is a runtime-fatal class observed during the Elgg 2.x→7.x fleet
migration that a normal "activates + HTTP 200" smoke test missed. The canonical
engine-side detector is `elgg-migrate/bin/scan-frontend-residue.sh`; this
template mirrors it so the guard travels with the plugin and fails its own CI.

| Bug class | Trigger | RegressionTest method | Origin |
|---|---|---|---|
| **signature-incompat** | a class `extends` an Elgg entity base and overrides `canComment` / `canWriteToContainer` / `canEdit` / `canDelete` / `canAnnotate` with a different param arity or types than Elgg 7 core | `testNoIncompatibleCoreOverrides` | bug-007, bug-013 |
| **viewpage-null-title** | `elgg_view_page(null, …)` or `elgg_view_page($x, …)` where `$x` is never assigned in the file → Elgg 7's typed `string $title` TypeErrors | `testNoNullTitleViews` | elgg-migrate-z1ces, scan rules |
| **viewmodule-null-title** | `elgg_view_module(…, null, …)` → typed `string $title` | `testNoNullTitleViews` | scan rules |
| **legacy-language-file** | `add_translation()` in `languages/*.php` — removed in Elgg 5.0, fatals at boot when the locale loads | `testNoLegacyLanguageFiles` | scan rules |
| **removed-instance-method** | `->getManifest()` or `$plugin->get/setUserSetting()` — removed ElggPlugin methods | `testNoRemovedInstanceMethods` | migration-lessons taxonomy |
| **css-view-orphaned** | Elgg 7 target with a `views/default/css/elements/*.css` override that has no relocated twin and is not loaded explicitly → silently unstyled | `testNoOrphanedCssElementViews` | bug-024 |

## Updating the canonical core signatures

`testNoIncompatibleCoreOverrides` compares against a hard-coded `CORE_SIG` map
(param `type $name` list, defaults stripped). When a new major version retypes
a core method, update **both**:

- `templates/RegressionTest.php.template` → `CORE_SIG`
- `elgg-migrate/bin/scan-frontend-residue.sh` → `CORE_SIG`

and add a fixture under
`elgg-migrate/tests/fixtures/scan-frontend-residue/` plus a row in
`elgg-migrate/tests/ScanFrontendResidueTest.php`.

## What this does NOT catch

These need a live render / route crawl (see `scan-frontend-residue.sh` companion
gates and `bin/verify-route-coverage.sh`), not a source scan:

- bad **call-site** argument types (e.g. passing an array where core wants
  `string $subtype` — bug-017)
- removed **procedural** functions actually reachable at runtime (covered by
  `migrate.php --check` + the route crawl's `Call to undefined` log scan)
- semantic regressions where the API form is right but behaviour is wrong
  (e.g. the dead `search` event) — exercise the page.
