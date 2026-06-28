# Migration lessons — why a migration looks done but isn't

Read this once before any multi-version migration. It is the synthesis of hard
failures: each lesson cost real hours and is now enforced by a gate, rule, or
Iron Law. The point of this doc is so the next migrator internalises the *why*;
the gates enforce the *what*.

---

## The four meta-principles

### 1. "Renders HTTP 200" ≠ "works"
The server-side gates (activation, `php -l`, `--verify` version-API check,
homepage + login render) prove the code **loads and parses** — not that it
**runs**. Latent fatals live in code paths that only execute on a specific page,
with specific data, sometimes only when authenticated. A site can return HTTP
200 on `/` while `/members`, `/search`, `/messages/inbox`, or `/blog/add` throw
500. **Activation is the start of testing, not the end.**
→ Enforced by: route battery in `bin/elgg-migrate-verify`; `bin/verify-route-coverage.sh`.

### 2. Completeness = exhaustive exercise + baseline diff (not static analysis)
Migration completeness is **not decidable statically** — dynamic dispatch,
data-dependent branches, and config-gated code mean some paths only run with
specific data/state. You cannot *prove* it complete. You can only build
evidence-based confidence: **crawl every route** (anonymous + authenticated +
parameterized with real entity guids), treat any 5xx or error-log entry as a
regression, and **diff the green-route set against the previous version** — the
live N-1 site is the oracle. A route that worked on N-1 and 5xxes on N is a bug,
full stop. Anything less than "exhaustively exercised and diffed" is a guess.
→ Enforced by: `bin/verify-route-coverage.sh` (run at EVERY version step).

### 3. Bugs are rarely top-version-only — fix at origin, forward-port
A bug found on the latest branch almost always **broke at the version where the
API changed** and is latent on every branch since. It only *surfaced* late
because the latest branch was the first one anyone exercised on that page.
Patching only the top branch leaves the intermediate branches broken and
re-introduces the bug whenever someone re-cuts from an earlier base. Always:
bisect to the origin version → fix there → forward-port up the chain.
→ Enforced by: Iron Law 14 + SKILL.md "Retrospective bug fixing" + `bin/forward-port-fix.sh`.

### 4. Migrations port syntax, not always semantics
The single most common failure mode: an automated or manual migration updates
the API **form** but not the underlying **behaviour**. The code looks migrated —
right namespaces, right method names, passes `--verify` — but does the wrong
thing at runtime. Examples below (search, getTablePrefix, alias rename) are all
this. When a rule renames an API, it must also verify the *contract* still holds.
→ Mitigation: exercise the page (principle 1); diff against baseline (principle 2).

---

## The bug taxonomy (class → signature → fix → gate)

Every class below was found in this migration. Grep your fleet for each.

| Class | Signature | Broke in | Fix | Gate |
|---|---|---|---|---|
| **Dead `search` event** | `elgg_trigger_(plugin_hook\|event_results)('search', …)` then `elgg_extract('entities', …)` | 2.x→3.0 (search rewrite; `search_*_hook` removed) | `elgg_search($options)` / `elgg_list_entities($o,'elgg_search')` | `[dead-search-event]`, rule 034 |
| **CSS view orphaned** | override at `views/default/css/elements/*.css` | 6.x→7.0 (views moved out of `css/`; core.css aggregates `elements/*.css`) | relocate to `views/default/elements/*.css`, or explicit `elgg_extend_view` | `[css-view-orphaned]`, rule 026 |
| **`elgg_view_page` null title** | `elgg_view_page($x, …)` where `$x` is never assigned | 6.x→7.0 (typed `string $title`) | pass a real string (entity/collection display name, `elgg_echo()`) | `[viewpage-null-title]` |
| **Override sig incompatible** | class `extends ElggEntity/ElggComment/…` overrides `canComment`/`canWriteToContainer`/`canEdit`/`canDelete`/`canAnnotate` with wrong arity or types | 6.x→7.0 (core methods typed `int $user_guid` etc.) | match the core signature (types + arity); fatals at class load | `[signature-incompat]`, RegressionTest::testNoIncompatibleCoreOverrides |
| **Removed API still called** | `elgg()->db->getTablePrefix()`, etc. — and a migration *regressed* a correct call back to the removed one | removed in 3.0 | `elgg_get_config('dbprefix')` etc. | route crawl + `Call to undefined …` log check |
| **`elgg_view_module` null title** | `elgg_view_module(<x>, null, …)` or an unassigned-`$var` 2nd arg | 6.x→7.0 (typed `string $title`) | pass a real string or `''` | `[viewmodule-null-title]` |
| **Legacy language file** | `add_translation('xx', $arr)` in `languages/*.php` | removed in 5.0 | convert to top-level `return [ … ];` (fatals at boot when that locale loads) | `[legacy-language-file]` |
| **Removed instance method still called** | `ElggPlugin::getUserSetting()`, `…::getManifest()`, etc. | varies (manifest/getUserSetting removed 4.0/5.x) | the `elgg_get_plugin_user_setting()` / `getDisplayName()` equivalent | `[removed-instance-method]` + route crawl `Call to undefined method` log check |
| **Members route → controller** | plugin hooks `members:list` event / overrides `resources/members` | 6.x→7.0 (core members → route controllers `collection:user:user:*`) | re-register the route at your resource, or adapt to the controller | rule 026 note |
| **Frontend JS residue** | AMD `require([])/define([])`, global `jQuery/$`, Foundation, `elgg_require_js` | 6.x→7.0 (no RequireJS/AMD/global jQuery) | ESM `import`, `.mjs`, `elgg_import_esm` | `[amd-*]`,`[global-jquery]`,`[esm-wrong-ext]`, rule 025 |
| **Annotation/metadata alias rename misapplied** | `a_table.value` in an `elgg_get_metadata()` query (main alias is `n_table`) | 6.x alias rename over-applied | use `n_table` for metadata, `a_table` for annotations | route crawl |
| **Procedural fn moved to class** | call to `vendor_helper_fn()` that the plugin moved into a `Vendor\Class` | varies per plugin | call the class method; delete stale view copies that re-call the procedural fn | route crawl + `Call to undefined function` |
| **Undeclared optional dependency** | `\OtherVendor\Class::method()` with no declared dependency on that plugin | any | guard: `if (class_exists('…') && …)` so it degrades when the optional plugin is absent | route crawl |
| **Wrong-guid → 500 not 404** | resource fatals on a guid that isn't its type/container (e.g. site guid 1) instead of returning 404 | any | gatekeeper / type-check before use | route crawl (distinguish from hard fatals) |
| **Nested plugin won't load** | `mod/<plugin>/mod/<sub>/` (2.x bundled-dependency style) | 4.x+ (only top-level `mod/*` load) | promote to a top-level plugin or vendor via composer | `[css-view-orphaned]`/manual |

---

## Process learnings

- **Never `git merge` whole branches to carry a one-file fix forward.** Per-version
  migrate branches legitimately differ (composer/docker/README/tests) → a full
  merge conflicts on everything unrelated. Cherry-pick where the code form
  matches; direct-edit + commit where it diverged (e.g. across the 4→5
  hooks→events rename). `bin/forward-port-fix.sh` does this.
- **Detached-HEAD trap.** After `git cherry-pick --abort` you can be left on a
  detached HEAD; a commit then lands on a dangling ref, not the branch. Confirm
  `git rev-parse --abbrev-ref HEAD` is a real branch before EVERY commit, and
  audit every branch ref after multi-branch work:
  `for b in 3 4 5 6 7; do git show migrate/elgg-$b.x:<file> | grep -c '<sig>'; done` → all 0.
- **Anonymised data caps content verification.** An anonymiser overwrites
  titles/descriptions/names with `<thing> <guid>` placeholders, so you cannot
  judge *content* fidelity from it — only structure (entity/relationship/metadata
  counts) and that pages render. Verifying real content needs a sealed real
  snapshot. "User 47846" / "Object content for guid N" are the anonymiser, not bugs.
- **Three surfaces, three crawls.** Anonymous routes, authenticated routes, and
  actions/forms (POST) are independent surfaces. The first clean does not imply
  the others — e.g. the `elgg_view_page` null-title class hid entirely on
  authenticated `/messages/*` routes the anonymous crawl never reached.
- **A theme that overrides core element views must hold the highest plugin
  priority** (`setPriority('last')`), or a later-loading core plugin (e.g.
  `activity`'s `resources/index`) overrides its views — pages stay 200 but render
  core defaults.
- **A committed fix is not a deployed fix (release-lag).** A plugin's `migrate/*`
  branch can carry the JS/`.mjs` fix while the *consuming site's* `composer.lock`
  still pins a pre-fix tag — so the built/deployed artifact stays broken even
  though the source scans clean. Verify the **locked/installed** version, not the
  branch tip. Remedy: tag the fix on the plugin repo, then `composer update
  "<vendor>/*" --ignore-platform-reqs` and re-deploy. Whole-fleet release-lag is
  one root cause, not N independent bugs — a single broad update clears most of it.
  *Tell:* the browser surfaces **one new module error per reload** as you fix them
  — ES-module resolution aborts the page on the *first* failing specifier, hiding
  every later one, so each fix "reveals" the next. Don't treat that as endless
  whack-a-mole; scan all `elgg_import_esm()` specifiers at once
  (`bin/scan-frontend-residue.sh` `[esm-importmap-mismatch]`/`[esm-removed-core-module]`).
- **JS console errors are invisible to HTTP-status gates.** curl batteries and the
  render golden-master only see status codes; an importmap specifier failure (or a
  `jQuery is not defined`) throws in the *browser* while the page still returns 200.
  A version step is not done until a real browser pass (Playwright) asserts
  `console.error` + `pageerror` are empty (minus a benign allow-list). The Elgg 7
  importmap key is the **full view path minus `.mjs`** — there is NO `js/`
  auto-prefix like the old AMD loader, so `elgg_import_esm('modal_info')` does
  **not** resolve view `js/modal_info.mjs`; the specifier must equal the view key.

---

## Definition of done (the completeness checklist)

A version step is complete only when ALL hold, on that version's Docker stack:

- [ ] **Render golden master captured against the working baseline (N-1) AND diffed at this version** — `bin/baseline-golden-master.sh`. Unit tests are NOT enough; the render layer is where the bugs hide. ZERO route may regress 2xx/3xx → 5xx vs the baseline.
- [ ] All acceptance gates pass (see SKILL.md "Acceptance Gates").
- [ ] `bin/scan-frontend-residue.sh` clean (no critical findings) — run against the
      **deployed/locked** plugin versions, not just the source branches (release-lag).
- [ ] **Browser console clean** on the deployed build (Playwright): `console.error`
      and `pageerror` empty across page types (minus a documented allow-list). HTTP
      200 does not catch importmap/JS failures.
- [ ] `bin/verify-route-coverage.sh` — **0 × 5xx** across every route, anonymous AND
      `--user/--pass` authenticated; error log free of `PHP Fatal` / `Call to undefined`.
- [ ] An action/form battery exercised (POST), not just GET routes.
- [ ] Green-route set diffed against the previous version — no regressions.
- [ ] Every fix applied at its **origin** version and forward-ported; all branch
      refs audited clean.

"Activates and the homepage renders" is **not** done. It is barely started.
