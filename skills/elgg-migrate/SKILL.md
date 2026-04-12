---
name: elgg-migrate
description: >
  Use when migrating Elgg CMS plugins between major versions (2.x→3.x, 3.x→4.x, etc.),
  upgrading plugin APIs, or modernizing legacy Elgg code. Triggers on "migrate elgg",
  "upgrade plugin", "elgg breaking changes".
---

# elgg-migrate

Migrate Elgg plugins one major version at a time using automated AST rules + LLM-guided fixes, verified in Docker.

## Required Reading

Before starting any migration, the agent MUST consult the relevant docs in `docs/`:

| Doc | When to read |
|-----|--------------|
| `docs/version-api-boundaries.md` | Before applying any rules — confirms which APIs are valid for the target version |
| `docs/plugin-architecture-by-version.md` | During setup and when writing ARCHITECTURE.md — defines target structure |
| `docs/coding-standards.md` | Before running rules (baseline) and before committing (verify) — version-specific style rules |
| `docs/security-review-checklist.md` | After running `--security` — interpret findings |
| `docs/llm-security-review.md` | During the LLM security review step — second-stage workflow |
| `docs/post-migration-documentation.md` | When writing ARCHITECTURE.md — template |

**Linear knowledge rule**: When migrating from version N to N+1, only read the sections of these docs relevant to N and N+1. Do NOT read sections about versions beyond N+1 — that knowledge will leak into your migration and cause version drift.

## Iron Laws

1. **NEVER SKIP A MAJOR VERSION** — 2.x→3.x→4.x→5.x→6.x. Skipping guarantees missed breaking changes.
2. **NEVER MIGRATE WITHOUT A BRANCH** — Branch name is the TARGET version: `migrate/elgg-{TARGET}.x` (e.g., 3→4 = `migrate/elgg-4.x`).
3. **VERIFY IN DOCKER** — Plugin must activate and site must render before proceeding.
4. **TESTS BEFORE MIGRATION** — Write tests against the CURRENT working version BEFORE running any migration rules. Tests are your regression safety net. If tests don't exist, write them first (Phase 1.8). Migration CANNOT start until pre-migration tests pass in Docker.
5. **CLOSURES CANNOT GO IN elgg-plugin.php** — Elgg 4+ serializes plugin config. Use class-based callbacks or Bootstrap.
6. **DIRECTORY NAME MUST MATCH composer.json** — Elgg 4+ requires plugin dir matches the `name` field (lowercase).
7. **LINEAR VERSION KNOWLEDGE ONLY** — When migrating from version N to N+1, the agent MUST only apply N+1 APIs, patterns, and conventions. Do NOT use APIs from version N+2 or later. Example: when migrating 3.x→4.x, use `\Elgg\Hook` (4.x), NOT `\Elgg\Event` (5.x); use `elgg_trigger_plugin_hook()` (4.x, deprecated), NOT `elgg_trigger_event_results()` (5.x). Run `--verify` after every migration to catch leakage.
8. **SECURITY SWEEP AFTER EVERY MIGRATION** — Run `--security` after applying rules. Fix critical findings before committing. Security debt from legacy code gets inherited — catch it at the version boundary.
9. **DOCUMENT AFTER MIGRATION** — After each version step, generate a plugin architecture summary documenting the current structure, registered hooks/events, entities, routes, and any migration notes for future reference.
10. **FOLLOW ELGG CODING STYLE** — Migrated code must follow Elgg's coding standards for the target version. Run PHP_CodeSniffer with Elgg's ruleset after each change. See `docs/coding-standards.md` for version-specific rules.

---

## Cost of failure (not every rule is equal)

The Iron Laws read as if every violation is equally bad. They're not. Knowing
the asymmetry matters because under pressure you'll cut corners somewhere —
cut them where the cost is low, not where it's catastrophic.

**Unrecoverable (never cut):** Iron Laws 1, 2, 3, 4, 7. Skipping a major
version corrupts every downstream migration in ways that surface months
later. Migrating without a branch means no way to bisect a regression.
Proceeding without Docker verification means the migration's "success" is
theater. Skipping pre-migration tests means you have no way to detect
regressions after the fact. Version-knowledge leakage means the plugin works
in your environment and fails in someone else's.

**Expensive but recoverable:** Iron Laws 5, 6, 8, 9. Closures in
elgg-plugin.php fail loudly on activation — you'll catch it. Directory case
mismatch fails loudly too. A missed security finding is a real risk but
usually survivable; security debt is a known quantity, not a hidden bomb.
Missing ARCHITECTURE.md is expensive for future work, not for current work.

**Cosmetic:** Iron Law 10 (coding style). A style regression is annoying,
not dangerous. If you have to defer one gate because you're out of time,
defer this one — never defer tests or Docker verification.

When the gates conflict (say, the security sweep and the Docker gate both
need attention and you only have bandwidth for one), work from
unrecoverable toward cosmetic. Tests and Docker first, security and
verification second, style last.

## When to stop and escalate

This workflow is designed to succeed on common cases. Some cases are not
common, and forging ahead on them produces fake progress — a "migrated"
plugin that's actually broken. Stop and surface the block to the human
(don't just silently skip) when:

- You've tried three or more distinct approaches to fix the same activation
  failure and none worked. Each attempt teaches you something; three
  failures means you're missing information the code doesn't contain.
- The plugin uses an API that doesn't appear in any manifest rule, you
  can't find a reference migration anywhere upstream, and the target
  version's docs don't obviously say what to use instead.
- A pre-migration test fails against the *current* version in a way you
  can't explain — meaning the plugin was already broken and you don't know
  whether the break is intentional.
- You find yourself wanting to use `--no-guard`, comment out a failing
  test, or bypass a gate "just this once" to unblock progress. That urge
  is the signal — the gate exists *because* the case you're hitting is the
  case it was built for.
- The security review surfaces a pattern you don't fully understand
  (especially around authentication, authorization, or data flow).
- A gate passes but something feels wrong — the page renders but looks
  subtly different, tests pass but faster than they should, the migration
  diff is smaller than you expected. Anomalies the gates weren't designed
  to catch are exactly the ones worth investigating.
- Data migration is needed (schema change, metadata reshape) and there's
  no existing `Elgg\Upgrade\Batch` pattern that fits the case.

Escalation means: commit what you have, open a beads issue describing what
you tried and why it didn't work, and hand off to the human. Don't delete
work, don't "come back to it later" implicitly, don't fake a pass.

## Agent failure modes (invisible to the gates)

The acceptance gates catch problems in the code. They don't catch problems
in the agent doing the migration. These are the failure modes worth naming
explicitly, because they're silent:

**Hallucinated APIs.** Making up functions like `elgg_get_plugin_entity()`
that sound plausible but don't exist in any version. Guard: when you're
about to use an API that doesn't appear in the manifest rules, grep the
target version's `vendor/elgg/elgg` for it first. If you can't find it,
you're inventing.

**Cross-version knowledge leakage.** Using `\Elgg\Event` during a 3→4
migration because you "know" it's the modern way — but 4.x wants
`\Elgg\Hook`. Guard: always run `--verify` after rules. Exit code 3 is this
failure, and ignoring it is how the leakage lands in production.

**Fabricated gate results.** Claiming "tests passed" without running them,
or running them and skimming the output without reading the summary line.
Guard: when you report a gate passed, the report should include the actual
command you ran and the actual output — if it's summarized, you didn't
check.

**Skipping upstream checks because "probably nothing there."** The upstream
check is the highest-leverage step in the whole workflow. Skipping it
means doing hours of work that someone else has already done. Guard: the
check is fast, run it every time, even when you're sure.

**Guessing `composer.json` fields from memory.** The required-fields table
exists because these fields are easy to get wrong. Read the table — don't
approximate.

**Conflating hook and event semantics.** They're different across versions
(4.x has both, 5.x merges them, 3.x uses hooks with Hook type hints). Guard:
before registering a handler, check which key (`'hooks'` vs `'events'`)
the target version expects, and which type hint its handlers take.

**Losing the plot under context pressure.** On long migrations, the agent
starts skipping steps to "get to the end." Guard: if you find yourself
shortcutting, commit what you have, push it, and hand off to a fresh
session with a beads issue describing exactly where you stopped.

## Recovery playbook

When things go wrong — and they will — the right move is almost never
"start over." These are the recovery patterns for common failure shapes:

**AST rules produced broken code.** Don't edit the broken output. Instead,
`git reset --hard HEAD~1` to the commit before the automated pass, then
re-run `bin/migrate.php` with `--dry-run` first to see exactly what the
rules want to change. If a specific rule is wrong, note it for the learning
loop and hand-apply the correct transformation.

**Activation fails in Docker with an opaque error.** Tail the Apache error
log (`docker compose exec elgg tail -f /var/log/apache2/error.log`) and
re-trigger activation. The real error is usually in the log, not in the
PHP exception message. Cross-reference against the Common Mistakes table —
most activation failures in practice are entries in that table.

**Tests pass in pre-migration but fail after adaptation.** The failure is
usually in the adaptation, not the migration. Diff the adapted test
against the original and the reference migration (if one exists). If the
reference plugin's tests use a different pattern, adopt that pattern.

**Site activates but renders partially (blank page, missing CSS, broken
links).** Run the full Docker gate checks even if you think it worked — the
simplecache CSS check and the error log grep exist because these failures
are subtle. Blank CSS is almost always css-crush failing silently; missing
links are usually routes that didn't migrate.

**Mid-migration context exhaustion (session running out of room).** This
is recoverable if you act before you've forgotten state. Commit what works
(even incomplete), push to remote, update the beads issue with: the branch
name, the last gate that passed, the next gate to attempt, and any known
issues. A fresh session can resume from that state without re-deriving it.

**Fleet-wide disaster (multiple plugins broken).** Don't roll everything
back. File a beads issue per broken plugin, mark them blocked, and
continue with the unblocked work. Iron Law 4 (fail fast, fix forward) is
specifically for this case — stopping the whole fleet on one hard case is
worse than leaving that plugin behind.

**Accidental destructive git operation.** `git reflog` is your friend. Any
commit made in the last 90 days is still in the reflog even after
`reset --hard`. Recover the SHA, `git branch recovery-<name> <sha>`, and
cherry-pick what you need. Don't panic and don't overwrite anything else
until you've recovered.

---

## Container Infrastructure

All operations run inside Docker containers — nothing executes on the host machine.

| Service | Purpose | Location |
|---------|---------|----------|
| `migrate` | AST migration rules (PHP 8.1 + php-parser) | Root `docker-compose.yml` |
| `elgg` | Plugin activation, PHPUnit, Elgg bootstrap | `docker/elgg{N}/docker-compose.yml` |
| `node` | Playwright and Vitest tests | `docker/elgg{N}/docker-compose.yml` (profile: test) |
| `db` | MySQL database | `docker/elgg{N}/docker-compose.yml` |

### Quick setup

```bash
# Build the migration tool (once)
docker compose build migrate

# Start target Elgg environment
docker compose -f docker/elgg{N}/docker-compose.yml up -d

# Run migration
docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin>

# Run tests
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test"
```

### Debugging inside containers

```bash
# Elgg container logs (PHP errors, Apache errors)
docker compose -f docker/elgg{N}/docker-compose.yml logs elgg
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg tail -f /var/log/apache2/error.log

# Interactive shell in Elgg container
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg bash

# Check PHP error log
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg cat /var/log/apache2/error.log

# MySQL queries (interactive)
docker compose -f docker/elgg{N}/docker-compose.yml exec db mysql -uelgg -pelgg elgg

# Check container status
docker compose -f docker/elgg{N}/docker-compose.yml ps

# Node container — debug Playwright
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test --debug"

# Rebuild containers after Dockerfile changes
docker compose -f docker/elgg{N}/docker-compose.yml build --no-cache
```

---

## Quick Reference

| Step | Command |
|------|---------|
| Analyze | `docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --dry-run` |
| Apply + all gates | `docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --verify --security --audit` |
| LLM report | `docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --dry-run --report` |
| Verify only | `docker compose run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --dry-run --verify --security --audit` |

### CLI Flags

| Flag | Purpose |
|------|---------|
| `--dry-run` | Analyze only, don't modify files |
| `--report` | Show LLM instructions for manual rules |
| `--verify` | Run post-migration version boundary check (catches future-version API leakage) |
| `--security` | Run security sweep (SQL injection, XSS, command injection, etc.) |
| `--audit` | Run `composer audit` for dependency CVEs |
| `--no-guard` | Skip version guard validation (not recommended) |

### Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | Usage error |
| 2 | Version mismatch (plugin version doesn't match manifest "from") |
| 3 | Post-migration verification failed (future-version APIs detected) |
| 4 | Security sweep found critical issues |
| 5 | Dependency audit found critical/high CVEs |

---

## Acceptance Gates (strict — all must pass before closing)

These are safety gates, not workflow steps. Order doesn't matter; passing does.
A migration that skips a gate is incomplete regardless of how much work went
into it. Each gate exists because skipping it has burned us before — the "why"
is the load-bearing part.

| Gate | Why it exists |
|------|--------------|
| Pre-migration tests pass on CURRENT version | Without a baseline, you cannot tell whether the migration broke behavior or whether the behavior was already broken |
| Migration branch named `migrate/elgg-{TARGET}.x` | Consistent naming is how the fleet workflow finds prior migration work |
| Automated rules applied with `--verify --security` | These flags catch future-version API leakage and critical security regressions — the two most common silent failures |
| LLM-guided manual fixes applied | Rules can't handle every case; the LLM report lists what needs hand attention |
| PHP syntax check clean | A file that doesn't parse will fail in production, not in tests |
| PostMigrationVerifier passes (exit code ≠ 3) | Catches Iron Law 7 violations — version knowledge leakage |
| SecuritySweep passes (exit code ≠ 4) | Legacy code carries security debt across version boundaries; this is the cheapest point to catch it |
| Pre-migration tests adapted and passing on TARGET version | The regression safety net only works if it's *run* against the new code |
| Plugin activates in Docker | Activation is the first real integration test — catches serialization, DI, and missing-dep issues |
| Site renders (homepage AND login, >1000 bytes) | Activation-without-render means a hook crashed on page load; both pages are needed because the login flow has its own code path |
| PHP_CodeSniffer passes for target version | Style regressions accumulate and make future migrations harder |
| ARCHITECTURE.md generated | The knowledge of what the plugin *is* at this version is the second-most valuable migration output after the code itself |
| CHANGELOG.md updated | Downstream consumers need to know what changed |
| `Elgg\Upgrade\Batch` script added if data migration is needed | Schema/data changes without an upgrade script mean the plugin works on fresh installs but breaks on real sites with existing data |
| Commit message format: `migrate({TARGET}.x): <summary>` | Consistent prefixes make `git log --grep` usable across the fleet |
| Issue closed with `--reason` | Future-you reading the beads history needs the "what changed" summary |

When dispatching migration to a subagent, the prompt MUST include these gates
and the subagent MUST report PASS/FAIL/SKIP-WITH-REASON on each. A migration
commit without a gate report is incomplete — redo the work.

**Skill invocation order**:
1. `elgg-test-writer` skill — write pre-migration tests (gate 1)
2. `elgg-migrate` skill — execute migration (gates 2-15)
3. `bd close` — only after ALL gates pass

**Subagent contract**: When dispatching migration to a subagent, the prompt MUST include the full gate checklist above and the subagent MUST report on each gate explicitly (PASS/FAIL/SKIP-WITH-REASON). A migration commit without a gates report is INCOMPLETE and the work must be redone.

**Tools that enforce gates**:
- Gate 6: `php bin/migrate.php ... --verify` (PostMigrationVerifier)
- Gate 7: `php bin/migrate.php ... --security` (SecuritySweep)
- Gate 9-10: `docker compose -f docker/elgg{N}/docker-compose.yml exec elgg ...`

---

## Workflow

### Phase 1: Setup and pre-flight

The shape of this phase: figure out where the plugin actually is (not where
its manifest claims), then find out whether someone has already done the work
you're about to start. Missing prior work is the most expensive mistake in
this whole workflow — every check below is worth more than the time it costs.

**Obtain the plugin** (clone or locate in `tmp/`), then determine its current
version and the shortest valid path to the target. Remember Iron Law 1:
the path is always one major version at a time.

### Before writing code: check whether the migration already exists

Duplicate migration wastes hours and, worse, can introduce regressions over a
known-good upgrade. Check as many of these signals as needed to be confident
nothing better exists:

**Local branches.** `git -C <plugin> branch -a | grep -iE 'migrate|elgg|upgrade|[0-9]\.[0x]'`
— look for `migrate/elgg-4.x`, `4.x`, `elgg4`, `upgrade/5.x`. If a target
branch exists with migration commits, start from it. Inspect with
`git log --oneline <base>..<branch>` and check what Elgg version it targets
by looking at `composer.json` and `manifest.xml` on that branch.

**Upstream GitHub.** `gh api repos/<owner>/<plugin>/branches` lists remote
branches; `gh api repos/<owner>/<plugin>/forks` finds forks that may have
already migrated. When checking a promising fork, look at its branches the
same way.

**Packagist / Elgg plugin directory.** Inside the Elgg container:
`composer show <vendor>/<plugin> --all`. Also check https://elgg.org/plugins
and https://packagist.org/packages/<vendor>/<plugin>.

**Version-prefixed repos.** Some orgs (notably hypeJunction) publish
per-version repos like `Elgg3-hypeDropzone` or `Elgg4-hypeDropzone`:
`gh search repos --owner <org> "Elgg4-<plugin>"`.

How to decide what to do with what you find:

- Already at the target version → skip migration, mark done.
- Migration branch exists but is incomplete → continue from that branch, don't restart.
- An upstream fork has a working migration → use it instead of re-migrating.
- Nothing exists anywhere → proceed to pre-migration tests.

### Version state indicators

When manifests and code disagree (they often do), trust these indicators over
the manifest. Use as many as needed to be confident.

| Indicator | Version |
|-----------|---------|
| Has `start.php` with `elgg_register_event_handler('init', ...)` at top level | 2.x |
| Has `start.php` that returns a closure + has `elgg-plugin.php` | 3.x |
| Has `manifest.xml` but no `elgg-plugin.php` | 2.x |
| Has `manifest.xml` AND `elgg-plugin.php` | 3.x |
| Has `elgg-plugin.php` with `'hooks'` key, no start.php, no manifest.xml | 4.x |
| Has `elgg-plugin.php` with `'events'` key only (no `'hooks'`) | 5.x+ |
| Has `'plugin'` key in elgg-plugin.php (replaces manifest.xml) | 4.x+ |
| Has `'capabilities'` in entity registration | 4.x+ |
| Has `'group_tools'` key in elgg-plugin.php | 4.x+ |
| Has `'notifications'` key in elgg-plugin.php | 4.x+ |
| Uses `\Elgg\Hook` type hint in callbacks | 3.x or 4.x |
| Uses `\Elgg\Event` type hint in callbacks | 5.x+ |
| Uses `elgg_register_plugin_hook_handler()` in start.php | 2.x or 3.x |
| Uses `elgg_register_page_handler()` | 2.x (removed in 3.x) |
| Uses `elgg_register_library()` / `elgg_load_library()` | 2.x (removed in 3.x) |
| Uses `add_group_tool_option()` | 2.x (use service in 3.x, declarative in 4.x) |
| Uses `elgg_define_js()` / `elgg_require_js()` | ≤5.x, removed in 6.x |
| Uses `elgg_register_esm()` / `elgg_import_esm()` | 6.x+ |
| Uses AMD `define()/require()` in JS | ≤5.x |
| Uses ES module `import/export` in JS | 6.x+ |
| Has `'restorable'` in entity capabilities | 6.x+ |
| Uses `elgg_generate_url()` for URLs | 3.x+ |
| Uses hardcoded URL strings (e.g., `"blog/owner/$name"`) | 2.x |

### Coding style baseline

Capture the plugin's current style state before migrating so you can tell
whether the migration regressed it. Ensure `.phpcs.xml` exists for the target
version (see `docs/coding-standards.md` for the template), then run
`vendor/bin/phpcs --standard=PSR12 classes/ actions/ lib/` to generate a
baseline. Post-migration style must not regress.

### Phase 1.8: Pre-migration tests (strict gate)

Before touching any migration code, the plugin must have passing tests
against its current version. This is a strict gate — the reason is simple: if
you don't have a baseline of working behavior, you can't tell whether the
migration broke anything. Tests are the only way the skill's other gates stop
being theater.

The only exception is plugins with zero PHP logic (pure views/CSS/JS). Those
are safe to cover with a fleet-wide smoke test in Phase 4 of `elgg-plugin-fleet`
— document the exception in the commit message.

**Check for existing tests first.** If `tests/phpunit.xml` already exists, you
may not need to write anything. Read what's there and assess coverage against
the rubric below; add what's missing rather than starting over.

**When you need to write tests**, use the `elgg-test-writer` skill (or pour
the `plugin-test-scaffold` formula: `bd mol pour plugin-test-scaffold`). The
coverage target is behavior, not lines — a migration can pass a high
line-coverage suite and still break the plugin's user-visible behavior. Aim
for:

*Backend (PHPUnit):* entity class mapping resolves correctly for each
registered subtype, CRUD works per subtype, each action validates input and
enforces permissions, hook/event handlers execute without errors, key views
render, owner-vs-non-owner permissions. The principle: every behavior that
would be visibly broken by a regression should have at least one test that
fails when it breaks.

*Frontend (Playwright):* every user-facing feature has at least one test that
exercises the full flow end-to-end — form fill + submit + assert DB state,
listings + pagination, modals/widgets appearing on trigger, AJAX round-trips
that verify both UI and DB, admin pages rendering. See `elgg-test-writer` for
templates.

**Commit tests on the CURRENT branch** (not the migration branch) so they
exist as a baseline in git history: `git commit -m "test: add pre-migration
test suite (PHPUnit + Playwright)"`.

**Run against the CURRENT Elgg Docker environment** — copying into the
container with `docker cp`, then `vendor/bin/phpunit` and `npx playwright
test` via the `node` profile. Everything must pass. If tests fail against
working code, they represent real bugs in the current plugin that would be
masked or carried forward by migration — fix them first.

**Record the baseline.** After migration (Phase 2), the same test count must
still pass (adapted for the new API if needed). Save the passing counts
somewhere you'll find them later; `bd remember` is fine.

**Beads wiring** (when using fleet tracking): the pre-migration test issue
must block the first migration step, and each version step must block the
next. A 4→5 migration issue without a dependency on the 3→4 migration for the
same plugin is a broken graph.

### Phase 2: Migrate (one version step)

Run this phase once per version step on the path. Iron Law 1 forbids
skipping, so 2.x → 5.x is three Phase 2 passes, not one.

The phase is a sequence of gated transformations. The order below is the
order things depend on each other — composer metadata must be right before
AST rules run, AST rules must run before the Docker gate, the Docker gate
must pass before tests matter. But within that dependency order, there's
judgment about when to commit, how much to batch, and what to investigate
when things fail.

**Branch first.** The branch name is always the TARGET version:
`git checkout -b migrate/elgg-{TARGET}.x`. Migrating 3→4 creates
`migrate/elgg-4.x`, migrating 4→5 creates `migrate/elgg-5.x`, and so on.

#### Update composer.json

Plugin metadata lives in `composer.json` from Elgg 3.x onwards, and from 4.x
it's the *only* metadata source (`manifest.xml` is deleted). Composer changes
come first because many AST rules and the Docker activation gate read the
metadata — running rules against mismatched metadata produces confusing
failures.

The fields every plugin needs from 3.x onward:

| Field | Value | Notes |
|-------|-------|-------|
| `name` | `<vendor>/<plugin-id>` | **MUST be lowercase** from 4.x — must match the plugin directory name exactly |
| `type` | `"elgg-plugin"` | Tells composer/installers where to place the plugin |
| `description` | from `manifest.xml` `<description>` | |
| `license` | SPDX identifier (e.g. `GPL-2.0-or-later`) | |
| `authors` | from `manifest.xml` `<author>` | |
| `require.php` | TARGET version's minimum (see constraints table) | |
| `require.elgg/elgg` | TARGET version constraint | |
| `require.composer/installers` | `^2.0` (3.x: `~1.0`) | |
| `require.<vendor>/<dep>` | one entry per `<requires><type>plugin</type>` in manifest.xml | |
| `config.allow-plugins.composer/installers` | `true` | required by composer 2.2+ |
| `extra.elgg-plugin.id` | the plugin id (lowercase dir name) | useful when name differs from id |

Per-version constraints:

| Target | `php` | `elgg/elgg` | `composer/installers` |
|--------|-------|-------------|-----------------------|
| 3.x    | `>=7.0` | `^3.0` | `~1.0` |
| 4.x    | `>=7.4` | `^4.0` | `^2.0` |
| 5.x    | `>=8.2` | `^5.0` | `^2.0` |
| 6.x    | `>=8.2` | `^6.0` | `^2.0` |

PHP runtime targets (highest practical version per Elgg major):

| Elgg | PHP runtime | Rationale |
|------|-------------|-----------|
| 3.x  | 7.4 | Upper bound before 8.0 breaks Elgg 3 internals |
| 4.x  | 7.4 (bump to 8.0 during 4→5 prep) | 8.1+ breaks on nullable internal params |
| 5.x  | 8.2 | Standardized floor for 5.x and above |
| 6.x  | 8.2 | Matches 5.x; 8.3 not yet validated |

The shape of the composer change depends on which step you're on:

- **2.x → 3.x**: the plugin has no `composer.json` — generate it by reading
  `manifest.xml` and translating fields one-for-one. Each
  `<requires><type>plugin</type><name>X</name></requires>` becomes a composer
  `require` entry (resolve `<vendor>/<name>` from the known plugin map —
  hypeJunction plugins use vendor `hypejunction`, ColdTrick uses `coldtrick`,
  core plugins use `elgg`). Keep `manifest.xml` in place; 3.x still reads it.

- **3.x → 4.x**: the most invasive step. Lowercase the `name` field, rename
  the plugin directory to match (`hypeBlog` → `hypeblog`), bump constraints,
  add the `config.allow-plugins` block, verify every plugin dependency that
  was in `manifest.xml` is mirrored in `require` (missing ones cause silent
  activation failures), then `git rm manifest.xml`. After this commit,
  `composer.json` is the sole metadata source. Verify with
  `composer validate -d mod/<plugin-id> --strict` inside the elgg4 container.

- **4.x → 5.x / 5.x → 6.x**: just bump `php` and `elgg/elgg`. If the plugin
  pulls in third-party composer packages, also bump those to versions
  compatible with the new PHP minimum, then `composer update` inside the
  target container.

Commit composer changes separately — the diff is worth reviewing on its own:
`git commit -m "chore({TARGET}.x): update composer.json metadata"`.

#### Run the AST rules

Run the automated rules with `--verify --security` — these flags catch the
two most common silent failures (future-version API leakage and security
regressions) and are cheap enough to always be on:

```bash
docker compose run --rm migrate bin/migrate.php \
  rules/{from}-to-{to}/manifest.json /plugins/<plugin> --verify --security
```

Exit code 3 means version-boundary violations (Iron Law 7) — something in the
migrated code references APIs from a future version. Exit code 4 means
critical security issues. Both block the commit; fix them before moving on.

Commit the automated pass separately so it's reviewable in isolation:
`git commit -m "migrate({TARGET}.x): automated AST transformations"`.

#### Apply LLM-guided fixes

Not every breaking change is AST-automatable. `--dry-run --report` prints the
LLM instructions for the remaining cases. Work through them, commit each
logical group separately so a reviewer can follow the diff. When you hit the
same hand-fix across multiple plugins, that's a signal the rule should be
automated — note it for later (see the learning loop).

#### Syntax check

Run `php -l` on every `.php` file (excluding `vendor/`) against the TARGET
PHP version inside the Elgg container. This is cheap and catches problems
before the much-slower Docker activation:

```bash
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  find mod/<plugin-id> -name "*.php" -not -path "*/vendor/*" -exec php -l {} \; | grep -v "No syntax errors"
```

#### Install plugin dependencies

If the plugin has its own `composer.json` with third-party packages, install
them in the container before activation:

```bash
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  composer install -d mod/<plugin-id> --no-interaction
```

#### Docker activation and render (strict gate)

This is the gate that catches everything the static checks miss —
serialization issues, DI problems, hooks that crash on page load, missing
runtime dependencies. A plugin that activates in PHP but fails to render is
*not* migrated.

The full gate has several parts, all required:

```bash
# Copy into container (use lowercase dir name matching composer.json)
docker cp <plugin>/. $(docker compose -f docker/elgg{N}/docker-compose.yml ps -q elgg):/var/www/html/mod/<plugin-id>/

# Activate — must succeed without throwing
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg php -r "
  require_once '/var/www/html/vendor/autoload.php';
  \$app = \Elgg\Application::getInstance(); \$app->bootCore();
  _elgg_services()->plugins->generateEntities();
  \$p = elgg_get_plugin_from_id('<plugin-id>');
  if (!\$p) { echo 'FAIL: not found'.PHP_EOL; exit(1); }
  try { \$p->activate(); echo 'OK'.PHP_EOL; }
  catch (\Throwable \$e) { echo 'FAIL: '.\$e->getMessage().PHP_EOL; exit(1); }
"

# Homepage renders — must return a full page, not a stub
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  curl -sL http://localhost/ | wc -c

# No PHP errors in the log
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  grep -c "PHP Fatal\|PHP Error" /var/log/apache2/error.log 2>/dev/null
```

Also verify the simplecache CSS is non-empty — css-crush v2.4 silently fails
on some CSS, and the only symptom is a zero-byte CSS file that doesn't block
activation but does break the site visually:

```bash
TS=$(docker compose -f docker/elgg{N}/docker-compose.yml exec -T elgg \
  curl -sL http://localhost/ | grep -oP 'cache/\K\d+' | head -1)
SIZE=$(docker compose -f docker/elgg{N}/docker-compose.yml exec -T elgg \
  curl -sL -o /dev/null -w "%{size_download}" "http://localhost/cache/${TS}/default/elgg.css")
test "$SIZE" -gt 1000 && echo "CSS OK (${SIZE} bytes)" || echo "CSS BROKEN (${SIZE} bytes) — see REFERENCE.md §18"
```

If activation succeeds but rendering fails, the usual culprits are hooks
registered on `head`/`page`/`view_vars` that query custom tables not yet
created — see the Common Mistakes table for the recovery pattern. Add a
try/catch around custom-table queries as defense-in-depth.

#### Adapt and run tests (strict gate)

Pre-migration tests were written against the old API and need adapting for
the new one. Typical adaptations per step:

- **3→4**: `elgg_get_session()->setLoggedInUser()` becomes
  `_elgg_services()->session_manager->setLoggedInUser()`
- **4→5**: `\Elgg\Hook` becomes `\Elgg\Event`, hook registrations become event
  registrations
- **Playwright**: update any routes/URLs that changed between versions

Run both PHPUnit and Playwright against the TARGET Elgg container. The
passing count must match the baseline from Phase 1.8 (same tests, same
number passing — adapted, not removed). Commit: `git commit -m "test: adapt
tests for Elgg {TARGET}.x"`.

If pre-migration tests don't exist (legacy plugin, migrated before this
gate), stop and go back to Phase 1.8. The only exception is plugins with
zero PHP logic, which must document the reason in the commit message.

#### Compare with an upstream reference (when one exists)

If a manually-migrated version of this plugin exists upstream (from a fork,
the Elgg plugin directory, or a version-prefixed repo), diff against it.
Reference migrations often reveal judgment calls the AST rules can't make —
restructured directories, extracted helper classes, test fixtures. Don't
blindly copy, but do look for patterns the migration missed.

#### LLM security review

After the automated security sweep passes, run `/security-review
--files=<plugin-path>` for the deeper analysis the pattern matcher can't do:
data-flow from `get_input()` to outputs, authorization gaps in actions,
business-logic flaws (IDOR, race conditions, mass assignment), hook/event
handlers trusting unvalidated input, and migration-introduced issues like
Bootstrap classes doing privileged operations or custom endpoints missing
CSRF. Address HIGH and MEDIUM findings before committing. See
`docs/llm-security-review.md` for the full workflow.

#### Coding standards

Run PHP_CodeSniffer against Elgg's ruleset for the target version:

```bash
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpcs --standard=<path-to-elgg-phpcs.xml> \
  mod/<plugin-id>/classes/ mod/<plugin-id>/actions/ mod/<plugin-id>/views/

# Auto-fix what's mechanical
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpcbf --standard=<path-to-elgg-phpcs.xml> mod/<plugin-id>/classes/
```

Style evolves by version: 3.x+ uses PSR-2 with Elgg extensions, 4.x+ requires
strict types and return type hints, 5.x+ encourages union types and named
arguments, 6.x+ adds readonly properties and enums. Commit style fixes
separately: `git commit -m "style: fix coding standards for Elgg {TARGET}.x"`.

#### Document the result

After all gates pass, generate or update `ARCHITECTURE.md` in the plugin
root. This is the second-most valuable output of the migration after the
code itself — future migrations and future developers rely on it. Cover:

- **Plugin summary** — what it does, its entity types and subtypes
- **Directory structure** — current layout matching target-version conventions
- **Registered hooks/events** — all handlers declared in elgg-plugin.php
- **Routes, entities, actions, views** — what's exposed and where
- **Dependencies** — other plugins this plugin relies on
- **Migration notes** — what changed in this version step, known issues, workarounds

Update `CHANGELOG.md` with a human-readable summary of the version bump.
Commit: `git commit -m "docs: add plugin architecture summary for Elgg {TARGET}.x"`.

### Phase 3: Finalize

Review the branch history for a coherent story (each commit should stand on
its own), run `--security` one last time on the final state, and generate a
report. The acceptance gates at the top of this skill list everything that
must be true before closing the beads issue — verify each one explicitly.

---

## Version-Specific Breaking Changes

Details in `rules/{from}-to-{to}/manifest.json`. Key highlights:

**2.x → 3.x** (largest): metastrings removed, subtypes→strings, page handlers→routes, libraries→autoloading, ~50 functions removed, entity queries unified.

**3.x → 4.x** (structural): start.php→elgg-plugin.php+Bootstrap, `\DI\object()`→`\DI\create()`, `Zend\Mail`→`Laminas\Mail`, entity attribute setters changed, canWriteToContainer() requires type+subtype, `run_sql_script()` removed, `forward()` removed, JS `elgg.action/get/getJSON/post` → `elgg/Ajax` module, plugin dirs must match composer.json lowercase name, `elgg_register_entity_type()` → entities key in elgg-plugin.php.

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

---

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

---

## Docker Environments

| Version | PHP | MySQL | Port | Status |
|---------|-----|-------|------|--------|
| 3.x | 7.4 | 5.7 | 8380 | Working |
| 4.x | 7.4 | 5.7 | 8480 | Working |
| 5.x | 8.2 | 5.7 | 8580 | TODO |
| 6.x | 8.2 | 8.0 | 8680 | TODO |

## Project Structure

```
elgg-migrate/
├── docker-compose.yml               # Root: migrate service (AST tool)
├── docker/
│   ├── migrate/Dockerfile           # PHP 8.1 + php-parser for AST rules
│   ├── elgg3/                       # Elgg 3.x: elgg + db + node services
│   │   ├── docker-compose.yml
│   │   ├── docker-compose.override.yml
│   │   └── Dockerfile
│   └── elgg4/                       # Elgg 4.x: elgg + db + node services
│       ├── docker-compose.yml
│       ├── docker-compose.override.yml
│       └── Dockerfile
├── skills/
│   ├── elgg-migrate/SKILL.md        # This file
│   ├── elgg-test-writer/SKILL.md    # PHPUnit + Playwright test writing
│   ├── elgg-js-test-writer/SKILL.md # Vitest JS test writing
│   ├── elgg-site-upgrade/SKILL.md   # Full site upgrade workflow
│   └── elgg-plugin-fleet/SKILL.md   # Batch plugin migration
├── bin/migrate.php                   # CLI runner (runs in migrate container)
├── src/Rules/V2ToV3/                 # 18 automated rules
├── src/Rules/V3ToV4/                 # 12 automated rules
├── rules/2x-to-3x/                  # 28+ rules (18 auto + LLM)
├── rules/3x-to-4x/                  # 30 rules (13 auto + 17 LLM)
├── tests/                            # 217 tests, 1022 assertions
└── tmp/                              # Guinea pig plugins (gitignored)
```
