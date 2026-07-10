export const meta = {
  name: 'elgg-7x-migration-verification',
  description: 'Mine the beads+wolf failure history into the elgg-migrate skill as early tests, enforce tests-first, then fan out agents to author unit/integration/e2e + regression tests proving the target site's 2x->7x migration is complete at every level, route and account type',
  phases: [
    { title: 'Catalog', detail: 'mine beads+wolf failures -> skill failure catalog + data-file gaps' },
    { title: 'HardenGate', detail: 'add detection for uncaught failure classes to skill gates' },
    { title: 'GateTests', detail: 'unit tests proving each gate detects its failure class' },
    { title: 'Template', detail: 'elgg-test-writer regression template + tests-first scaffold' },
    { title: 'Discover', detail: 'per-plugin feature + migration-fix surface' },
    { title: 'Author', detail: 'unit/integration/regression tests per plugin' },
    { title: 'Rules', detail: 'AST transform tests per version step 2x..7x' },
    { title: 'E2E', detail: 'Playwright routes x account-types + write-flows' },
    { title: 'Enforce', detail: 'wire tests-first gate into migrate.php + SKILL.md' },
    { title: 'Critic', detail: 'completeness critic lists uncovered' },
    { title: 'GapFill', detail: 'close uncovered gaps, bounded rounds' },
    { title: 'Synthesize', detail: 'confidence report + docker run manifest' },
  ],
}

// The runtime may deliver `args` as a JSON string; parse defensively.
const A = (typeof args === 'string') ? JSON.parse(args) : args
const P = A.pluginsRoot
const FORUM = A.forumRoot
const SKILL = A.skillRoot
const TESTWRITER = A.testWriterRoot
const PROJECT = A.projectRoot
const STACK = A.stackUrl // live local render stack
const plugins = Array.isArray(A.plugins) ? A.plugins : JSON.parse(A.plugins)

const CATALOG_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    classes: { type: 'array', items: { type: 'object', additionalProperties: false, properties: {
      id: { type: 'string' }, name: { type: 'string' }, versionStep: { type: 'string' },
      detection: { type: 'string', description: 'how to statically detect it (symbol/regex/file)' },
      fix: { type: 'string' }, sources: { type: 'array', items: { type: 'string' } },
      gateCovered: { type: 'boolean' },
    }, required: ['id', 'name', 'versionStep', 'detection'] } },
    catalogFile: { type: 'string' },
    dataFilesPatched: { type: 'array', items: { type: 'string' } },
    newSignaturesAdded: { type: 'integer' },
    summary: { type: 'string' },
  },
  required: ['classes', 'catalogFile', 'summary'],
}

const HARDEN_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    filesChanged: { type: 'array', items: { type: 'string' } },
    detectionsAdded: { type: 'array', items: { type: 'string' } },
    lintPass: { type: 'boolean' }, testRun: { type: 'string' }, notes: { type: 'string' },
  },
  required: ['filesChanged', 'lintPass'],
}

const CONV = `
Environment & conventions (READ the real files before writing — do not assume):
- Plugin lives at ${P}/<name> on branch migrate/elgg-7.x. Target runtime is Elgg 7.x ONLY.
- PHP test layout (mirror the plugin's EXISTING files exactly): tests/bootstrap.php, tests/phpunit.xml (+ phpunit-integration.xml), tests/phpunit/unit/*Test.php (extends \\Elgg\\UnitTestCase), tests/phpunit/integration/**/*Test.php (extends \\Elgg\\IntegrationTestCase). Read an existing *Test.php + tests/bootstrap.php in THIS plugin first and copy its namespace/base-class/setup idiom.
- Elgg 7 API only: \\Elgg\\Event (not \\Elgg\\Hook), array-return menu registration, ElggUndefinedObject for undefined objects, elgg_get_user_by_username, _elgg_services()->systemCache->clear(), Upgrade\\Batch is abstract (extends AsynchronousUpgrade). NEVER call removed functions or reintroduce polyfills.
- Every assertion must be MEANINGFUL. No assertTrue(true), no tests that only assert a class exists unless that is the actual regression.
- Write ONLY under the plugin's own tests/ dir. Do NOT edit src/classes. Do NOT git commit or git add. Working tree only.
- Run 'php -l' on every new file. If ${P}/<name>/vendor/bin/phpunit exists, run the unit suite and report real pass/fail; if not, do NOT composer install — set unitRun.ran=false, notes='needs composer install'.
`

const FEATURE_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    plugin: { type: 'string' },
    routes: { type: 'array', items: { type: 'string' }, description: 'route paths/identifiers registered by this plugin' },
    actions: { type: 'array', items: { type: 'string' } },
    widgets: { type: 'array', items: { type: 'string' } },
    entityTypes: { type: 'array', items: { type: 'string' }, description: 'type/subtype pairs owned' },
    forms: { type: 'array', items: { type: 'string' } },
    eventHandlers: { type: 'array', items: { type: 'string' } },
    jsModules: { type: 'array', items: { type: 'string' } },
    upgrades: { type: 'array', items: { type: 'string' }, description: 'Upgrade\\Batch classes' },
    accountTypes: { type: 'array', items: { type: 'string' }, description: 'anon|user|admin affected' },
    migrationFixes: { type: 'array', items: { type: 'object', additionalProperties: false, properties: { ref: { type: 'string' }, summary: { type: 'string' } }, required: ['summary'] }, description: 'commits that FIXED 2x->7x breakage; each needs a regression test' },
    keyBehaviors: { type: 'array', items: { type: 'string' }, description: 'human-readable behaviors that must be tested' },
  },
  required: ['plugin', 'routes', 'keyBehaviors', 'migrationFixes'],
}

const WRITE_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    plugin: { type: 'string' },
    filesWritten: { type: 'array', items: { type: 'string' } },
    unitTestsAdded: { type: 'integer' },
    integrationTestsAdded: { type: 'integer' },
    regressionTestsAdded: { type: 'integer' },
    lintPass: { type: 'boolean' },
    unitRun: { type: 'object', additionalProperties: false, properties: { ran: { type: 'boolean' }, pass: { type: 'boolean' }, summary: { type: 'string' } }, required: ['ran'] },
    uncovered: { type: 'array', items: { type: 'string' }, description: 'features/behaviors still lacking a test' },
    notes: { type: 'string' },
  },
  required: ['plugin', 'filesWritten', 'lintPass', 'unitRun'],
}

const E2E_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    area: { type: 'string' },
    specFilesWritten: { type: 'array', items: { type: 'string' } },
    scenarios: { type: 'array', items: { type: 'object', additionalProperties: false, properties: { name: { type: 'string' }, accountType: { type: 'string' }, route: { type: 'string' } }, required: ['name', 'accountType'] } },
    ran: { type: 'boolean' }, pass: { type: 'boolean' }, notes: { type: 'string' },
  },
  required: ['area', 'specFilesWritten', 'scenarios'],
}

const RULE_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    step: { type: 'string' },
    filesWritten: { type: 'array', items: { type: 'string' } },
    testsAdded: { type: 'integer' },
    ran: { type: 'boolean' }, pass: { type: 'boolean' },
    uncoveredRules: { type: 'array', items: { type: 'string' } },
    notes: { type: 'string' },
  },
  required: ['step', 'filesWritten', 'ran'],
}

const CRITIC_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    uncovered: { type: 'array', items: { type: 'object', additionalProperties: false, properties: {
      kind: { type: 'string', description: 'route|account-type|feature|behavior|version-step' },
      target: { type: 'string' },
      plugin: { type: 'string' },
      why: { type: 'string' },
    }, required: ['kind', 'target', 'why'] } },
    confidencePct: { type: 'integer' },
    summary: { type: 'string' },
  },
  required: ['uncovered', 'confidencePct', 'summary'],
}

// ---- Phase 0: skill hardening was APPLIED ON DISK by the prior run (Catalog/HardenGate/
//      GateTests/Template all completed). Re-verify read-only instead of redoing 855k tokens. ----
phase('Catalog')
const STEPS = ['2x-to-3x', '3x-to-4x', '4x-to-5x', '5x-to-6x', '6x-to-7x']
const catalog = await agent(
  `The elgg-migrate skill was hardened from this project's beads+wolf failure history in a PRIOR run. Do a READ-ONLY verification + summary pass — do NOT re-mine, rewrite, patch, or duplicate anything.\n` +
  `Read: ${SKILL}/references/migration-failure-catalog.md (the catalog); ${SKILL}/references/removed-functions.json and changed-class-contracts.json (confirm each parses via 'php -r' and the added symbols are present); the new gate tests under ${SKILL}/tests; ${TESTWRITER}/templates/MigrationRegressionTest.php.template and BaselineTest.php.template; ${TESTWRITER}/SKILL.md.\n` +
  `Run 'cd ${SKILL} && vendor/bin/phpunit' and record pass/fail (this exercises the new gate tests).\n` +
  `Return the CATALOG schema: classes[] parsed from the catalog file (id,name,versionStep,detection,gateCovered), catalogFile path, dataFilesPatched, newSignaturesAdded, and a summary including the phpunit result and any breakage found. Verification only — write nothing.`,
  { label: 'hardening-verify', phase: 'Catalog', schema: CATALOG_SCHEMA }
)
log(`Skill hardening on disk: ${catalog.classes?.length || 0} failure classes catalogued`)
// Lightweight summaries of the prior run's completed hardening phases (real files are on disk).
const harden = { filesChanged: ['src/PostMigrationVerifier.php', 'src/SecuritySweep.php'], detectionsAdded: [], lintPass: true, notes: 'gate detection added by prior run — see skill git status' }
const gateTests = STEPS.map((s) => ({ step: s, filesWritten: [], testsAdded: 0, ran: false, notes: 'authored by prior run under skill/tests' }))
const template = { filesChanged: ['elgg-test-writer/templates/MigrationRegressionTest.php.template', 'elgg-test-writer/templates/BaselineTest.php.template', 'elgg-test-writer/SKILL.md'], lintPass: true, notes: 'authored by prior run' }

// ---- Phase 1+2: per-plugin discover -> author (pipelined, no barrier) ----
phase('Discover')
const perPlugin = await pipeline(plugins,
  (p) => agent(
    `Discover the full feature + migration surface of Elgg plugin '${p}'.\n${CONV}\nDo READ-ONLY:\n1. Run: git -C ${P}/${p} log --oneline main..HEAD 2>/dev/null || git -C ${P}/${p} log --oneline -60 ; identify commits that FIXED migration breakage (fix(), removed-fn, boot-order, ESM/AMD, batch abstract, camelCase id, hook->event, arity) — each is a regression-test candidate.\n2. Read ${P}/${p}/elgg-plugin.php fully — enumerate routes, actions, widgets, entities(type/subtype), events, view/page handlers, upgrades, CLI, middleware.\n3. Skim classes/ to name the services/routers/validators/batches and their key behaviors.\n4. List existing tests under ${P}/${p}/tests.\nReturn the schema: routes, actions, widgets, entityTypes, forms, eventHandlers, jsModules, upgrades, accountTypes (anon/user/admin that use these), migrationFixes, and keyBehaviors (concrete, testable statements). Do NOT write anything.`,
    { label: `discover:${p}`, phase: 'Discover', schema: FEATURE_SCHEMA }
  ),
  (inv, p) => inv ? agent(
    `Author tests for Elgg plugin '${p}' covering its discovered features and migration fixes.\n${CONV}\nGap spec (discovered surface):\n${JSON.stringify(inv)}\n\nAuthor, in priority order:\n(a) ONE regression test per entry in migrationFixes — assert the FIXED behavior (e.g. handler registered at the right lifecycle, removed-fn replaced, batch is asynchronous, camelCase id resolves lowercased).\n(b) unit tests for pure logic in services/routers/validators/batches (no DB).\n(c) integration tests (extends \\Elgg\\IntegrationTestCase) for elgg-plugin.php registration: the plugin's routes/actions/widgets/entities/events actually register on a booted Elgg 7 — mirror any existing integration test in this plugin.\n(d) a MigrationRegressionTest for this plugin from ${TESTWRITER}/templates/MigrationRegressionTest.php.template driven by the failure catalog ${SKILL}/references/migration-failure-catalog.md — assert the catalog's failure classes are ABSENT for the 7.x target (no removed symbols, no start.php, lowercase plugin-id resolves, Seed/Batch shape correct, no orphaned css/elements overrides).\nCap at ~12 focused, meaningful test methods total; quality over volume. Follow the plugin's EXISTING conventions exactly. Lint every file (php -l). Run the unit suite only if vendor/bin/phpunit already exists. Do not commit.\nReturn the WRITE schema; list anything still uncovered.`,
    { label: `author:${p}`, phase: 'Author', schema: WRITE_SCHEMA }
  ).then((w) => ({ plugin: p, inventory: inv, write: w })) : null
)

const good = perPlugin.filter(Boolean)
const features = good.map((x) => x.inventory).filter(Boolean)
log(`Discovered+authored for ${good.length}/${plugins.length} plugins`)

// ---- Phase 3: migration-rule tests, all version steps (runs the 'all versions' mandate) ----
phase('Rules')
const ruleResults = await parallel(STEPS.map((s) => () => agent(
  `Ensure the elgg-migrate AST transform rules for version step '${s}' are unit-tested in the migration engine.\nEngine root: ${SKILL} (self-contained; vendor/bin/phpunit present; run tests with: cd ${SKILL} && vendor/bin/phpunit).\nRule sources: ${SKILL}/src/Rules/ and manifests ${SKILL}/rules/${s}/. Existing tests: ${SKILL}/tests.\nDo: (1) list the automated (AST) rules for ${s}; (2) find which lack a unit test; (3) author focused unit tests (input code -> transformed output) for the UNCOVERED automated rules, mirroring existing rule-test style; (4) run 'cd ${SKILL} && vendor/bin/phpunit' and report real pass/fail. Write ONLY under ${SKILL}/tests. Do not commit.\nReturn the RULE schema.`,
  { label: `rules:${s}`, phase: 'Rules', schema: RULE_SCHEMA }
)))

// ---- Phase 4: E2E battery — routes x account-types + write-flows, against the live stack ----
phase('E2E')
const allRoutes = Array.from(new Set(features.flatMap((f) => f.routes || []))).slice(0, 400)
const E2E_AREAS = [
  { name: 'account-types-and-auth', focus: 'anonymous vs logged-in user vs admin: login, gatekeeping, access boundaries, admin-only pages' },
  { name: 'profiles-and-pretty-urls', focus: 'profile pages, /@username SEF pretty URLs resolve+canonical-redirect, avatar/cover' },
  { name: 'groups-and-courses', focus: 'group/course listing, view, membership, group tools' },
  { name: 'discussions-and-topics', focus: 'discussion/topic list, view, reply threads' },
  { name: 'files-folders-gallery', focus: 'file/folder/gallery listing, view, download, image serve/icon' },
  { name: 'blog-wall-activity', focus: 'blog, wall posts, activity river, comments' },
  { name: 'messages-and-notifications', focus: 'inbox/messages, notification settings and delivery surfaces' },
  { name: 'search-and-directory', focus: 'site search results, member/entity directory listings, pagers' },
  { name: 'widgets', focus: 'profile+dashboard widgets: Add-widgets panel lists available widgets, add/remove/reorder — this is a KNOWN BUG; author a spec that captures current behavior and the expected behavior' },
  { name: 'admin-and-settings', focus: 'admin dashboard, plugin settings, user settings pages' },
  { name: 'write-flows', focus: 'CREATE/UPLOAD/COMMENT flows end-to-end (the known remaining gap): create a post, upload a file, add a comment' },
]
const e2e = await parallel(E2E_AREAS.map((a) => () => agent(
  `Author Playwright E2E tests for the '${a.name}' area of the target Elgg 7.x site.\nFocus: ${a.focus}.\nE2E project root: ${FORUM}/e2e (Playwright). READ FIRST: ${FORUM}/e2e/playwright.config.ts, ${FORUM}/e2e/tests/auth.setup.ts (login/account fixtures), ${FORUM}/e2e/fixtures.json, and an existing spec (e.g. tests/profile.spec.ts) — reuse its login harness, storage-state auth, and baseURL. Live stack: ${STACK}.\nCover the relevant routes across account types anon/user/admin (discover concrete entity URLs by navigating listing pages or querying the app; do not hardcode GUIDs that may not exist). Assert real render (no fatal/500, expected heading/content, no console errors), not just HTTP 200.\nRelevant discovered routes (subset): ${JSON.stringify(allRoutes.filter((r) => true).slice(0, 60))}.\nWrite spec files under ${FORUM}/e2e/tests. Run a SMOKE subset with playwright if feasible against ${STACK}; report pass/fail. Do not commit.\nReturn the E2E schema.`,
  { label: `e2e:${a.name}`, phase: 'E2E', schema: E2E_SCHEMA }
)))

// ---- Phase 4b: Perf — Lighthouse on public pages, regression vs 2.x legacy ----
phase('Perf')
const PERF_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    area: { type: 'string' },
    scriptFilesWritten: { type: 'array', items: { type: 'string' } },
    pages: { type: 'array', items: { type: 'object', additionalProperties: false, properties: {
      url: { type: 'string' }, perf: { type: 'integer' }, a11y: { type: 'integer' },
      bestPractices: { type: 'integer' }, seo: { type: 'integer' },
      lcpMs: { type: 'number' }, tbtMs: { type: 'number' }, cls: { type: 'number' },
    }, required: ['url'] } },
    bottlenecks: { type: 'array', items: { type: 'string' } },
    regressionsVsLegacy: { type: 'array', items: { type: 'string' } },
    ran: { type: 'boolean' }, notes: { type: 'string' },
  },
  required: ['area', 'scriptFilesWritten', 'pages', 'ran'],
}
const publicRoutes = Array.from(new Set(['/', '/members', '/blog', '/groups', '/activity', '/search', '/register', '/login', ...features.flatMap((f) => (f.routes || []))]))
const PERF_GROUPS = [
  { name: 'landing-and-listings', routes: ['/', '/members', '/groups', '/blog', '/activity'] },
  { name: 'content-and-profiles', routes: publicRoutes.filter((r) => /@|profile|course|topic|file|folder|gallery|view/.test(r)).slice(0, 12) },
  { name: 'auth-and-forms', routes: ['/login', '/register', '/search'] },
]
const perf = await parallel(PERF_GROUPS.map((g) => () => agent(
  `Run Lighthouse performance+quality audits on PUBLIC (anonymous-accessible) pages of the migrated Elgg 7 site and flag bottlenecks + regressions.\n` +
  `7.x stack: ${STACK}. Baseline for regression comparison: the live 2.x LEGACY stack at http://localhost:3001 (same site, pre-migration) — compare equivalent public routes.\n` +
  `Group '${g.name}' routes: ${JSON.stringify(g.routes)} (skip any that require auth; only audit pages that render for anonymous users).\n` +
  `Tooling: use 'npx lighthouse <url> --quiet --chrome-flags="--headless --no-sandbox" --only-categories=performance,accessibility,best-practices,seo --output=json --output-path=<tmp>' (or the lighthouse node API via ${FORUM}/e2e which already has a Chromium). Author a reusable audit script under ${FORUM}/e2e/perf/ (e.g. lighthouse-audit.mjs taking a route list) — do NOT hardcode one-off runs.\n` +
  `Capture per page: performance/a11y/best-practices/seo scores + LCP, TBT, CLS. Identify concrete bottlenecks (render-blocking resources, oversized JS/CSS bundles, uncompressed images, unminified assets, importmap over-fetch, missing cache headers). Flag any 7.x page whose perf score is materially LOWER than the 2.x legacy equivalent as a regression. Do not commit.\n` +
  `Return the PERF schema.`,
  { label: `perf:${g.name}`, phase: 'Perf', schema: PERF_SCHEMA }
)))

// ---- Phase 4c: Enforce — tests-first gate into migrate.php + SKILL.md (single writer) ----
phase('Enforce')
const enforce = await agent(
  `Wire TESTS-FIRST ENFORCEMENT into the elgg-migrate skill so no plugin code is migrated before tests exist and pass a baseline.\n` +
  `Read ${SKILL}/bin/migrate.php (CLI entry, flags, exit codes), ${SKILL}/SKILL.md, and how the existing gates (VersionGuard, PostMigrationVerifier, SecuritySweep, DependencyAudit) are invoked.\n` +
  `Implement a tests-first GATE: before applying any migration transform to a plugin, migrate.php must verify a plugin test suite exists and a baseline run was captured (RED/known-good), and REFUSE (non-zero exit) with a clear message + remediation if not — add a --require-tests flag defaulting ON, with an explicit --no-tests escape hatch that is logged. Reuse the elgg-migrate failure catalog + MigrationRegressionTest template so the gate points users at generating tests.\n` +
  `Update ${SKILL}/SKILL.md with a mandatory 'Tests-first' section: (1) generate/adopt the plugin test suite + MigrationRegressionTest, (2) run baseline (must pass on current code), (3) migrate one version step, (4) re-run (must stay green), (5) then the existing Docker gates. Cross-link references/migration-failure-catalog.md.\n` +
  `You are the SINGLE writer of migrate.php + SKILL.md this run. php -l migrate.php. Run 'cd ${SKILL} && vendor/bin/phpunit' to confirm nothing broke. Do not commit.\n` +
  `Return the HARDEN schema.`,
  { label: 'enforce-tests-first', phase: 'Enforce', schema: HARDEN_SCHEMA }
)

// ---- Phase 5: completeness critic ----
phase('Critic')
const critic = await agent(
  `You are a COMPLETENESS CRITIC for the target site's 2x->7x migration test suite. Goal: 100% confidence that every route, account type, feature, behavior and version-step is tested.\nEvidence:\nPER-PLUGIN (inventory + what was authored + self-reported uncovered):\n${JSON.stringify(good.map((x) => ({ plugin: x.plugin, routes: x.inventory.routes, keyBehaviors: x.inventory.keyBehaviors, fixes: x.inventory.migrationFixes, authored: x.write })))}\nRULES: ${JSON.stringify(ruleResults.filter(Boolean))}\nE2E: ${JSON.stringify(e2e.filter(Boolean))}\nPERF: ${JSON.stringify(perf.filter(Boolean))}\nFAILURE-CATALOG classes: ${JSON.stringify(catalog.classes.map((c) => ({ id: c.id, step: c.versionStep, gateCovered: c.gateCovered })))}\nGATE-TESTS: ${JSON.stringify(gateTests.filter(Boolean))}\nIdentify what is STILL UNCOVERED: uncovered routes, account-type paths, features/behaviors with no test, version steps with uncovered rules, failure-catalog classes with no gate test, public pages with no Lighthouse audit or with an unexplained perf regression, and any plugin whose unit suite FAILED or could not run. Be concrete and specific (name the route/plugin/behavior/class). Return the CRITIC schema with a realistic confidencePct.`,
  { phase: 'Critic', schema: CRITIC_SCHEMA }
)

// ---- Phase 6: bounded gap-fill loop ----
phase('GapFill')
let gaps = (critic?.uncovered || [])
const fills = []
let round = 0
while (gaps.length && round < 2) {
  round++
  log(`Gap-fill round ${round}: ${gaps.length} uncovered`)
  const batch = gaps.slice(0, 16)
  const r = await parallel(batch.map((g) => () => agent(
    `Close this test-coverage gap for the target site's 7.x migration.\nGap: ${JSON.stringify(g)}\n${CONV}\nE2E lives at ${FORUM}/e2e/tests (Playwright, reuse auth.setup.ts, stack ${STACK}); plugin PHP tests live under ${P}/<plugin>/tests. Rule tests under ${SKILL}/tests. Pick the right level for this gap and author a MEANINGFUL test that covers it. Lint/run what is cheaply runnable. Do not commit.\nReturn the WRITE schema.`,
    { label: `gapfill:r${round}`, phase: 'GapFill', schema: WRITE_SCHEMA }
  )))
  fills.push(...r.filter(Boolean))
  const rc = await agent(
    `Re-audit remaining coverage gaps after gap-fill round ${round}. Previously uncovered: ${JSON.stringify(gaps)}. Just-authored this round: ${JSON.stringify(r.filter(Boolean).map((x) => ({ plugin: x.plugin, files: x.filesWritten })))}. Return the CRITIC schema listing what is STILL uncovered.`,
    { phase: 'Critic', schema: CRITIC_SCHEMA }
  )
  gaps = rc?.uncovered || []
}

// ---- Phase 7: synthesis ----
phase('Synthesize')
const report = await agent(
  `Produce the final MIGRATION VERIFICATION REPORT (markdown) for the target site's 2x->7x migration.\nInclude: (1) SKILL HARDENING — failure catalog (${catalog.classes.length} classes), data-file patches, gate detections added, gate tests, test template, tests-first enforcement in migrate.php+SKILL.md; (2) coverage matrix — plugins covered, unit/integration/regression counts, e2e areas, rule steps, Lighthouse pages; (3) which plugin/gate/rule unit suites passed / failed / could-not-run; (4) PERFORMANCE — Lighthouse scores summary, top bottlenecks, and any regressions vs the 2.x legacy baseline; (5) a DOCKER RUN MANIFEST: exact commands to run the Docker-dependent layers (per-plugin integration phpunit + full e2e playwright + lighthouse against ${STACK}) authored but not all run in-flight; (6) REMAINING gaps to 100%: ${JSON.stringify(gaps)}; (7) a blunt confidence assessment. Base it strictly on this evidence:\nCATALOG: ${JSON.stringify(catalog)}\nHARDEN: ${JSON.stringify(harden)}\nGATETESTS: ${JSON.stringify(gateTests.filter(Boolean))}\nTEMPLATE: ${JSON.stringify(template)}\nENFORCE: ${JSON.stringify(enforce)}\nPER-PLUGIN: ${JSON.stringify(good.map((x) => ({ plugin: x.plugin, write: x.write })))}\nRULES: ${JSON.stringify(ruleResults.filter(Boolean))}\nE2E: ${JSON.stringify(e2e.filter(Boolean))}\nPERF: ${JSON.stringify(perf.filter(Boolean))}\nGAPFILL: ${JSON.stringify(fills.map((x) => ({ plugin: x.plugin, files: x.filesWritten })))}\nReturn the markdown report as text.`,
  { phase: 'Synthesize' }
)

return {
  failureCatalogClasses: catalog.classes.length,
  dataFilesPatched: catalog.dataFilesPatched || [],
  gateDetectionsAdded: harden.detectionsAdded || [],
  gateTestSteps: gateTests.filter(Boolean).length,
  testsFirstEnforced: enforce.filesChanged || [],
  pluginsCovered: good.length,
  ofTotal: plugins.length,
  ruleSteps: ruleResults.filter(Boolean).length,
  e2eAreas: e2e.filter(Boolean).length,
  perfGroups: perf.filter(Boolean).length,
  gapFillRounds: round,
  remainingGaps: gaps,
  report,
}
