---
name: elgg-migrate
description: >
  Use when migrating Elgg CMS plugins between major versions (2.x→3.x, 3.x→4.x, etc.),
  upgrading plugin APIs, or modernizing legacy Elgg code. Triggers on "migrate elgg",
  "upgrade plugin", "elgg breaking changes".
---

# elgg-migrate

Migrate Elgg plugins one major version at a time using automated AST rules + LLM-guided fixes, verified in Docker.

## Step 0 — Plugin discovery (always run first)

The skill is self-contained and path-agnostic. It never assumes a particular
host layout. Before any migration work, resolve two things and cache them:

**1. `PLUGINS_SOURCE`** — the directory containing the plugin(s) to migrate.

Detection order:

1. `$ELGG_MIGRATE_PLUGINS` environment variable, if set.
2. Cached value in `${XDG_CONFIG_HOME:-$HOME/.config}/elgg-migrate/config.json`
   under key `plugins_source`. Confirm with the user that it's still the
   intended source before reusing.
3. Infer from the current working directory:
   - If cwd contains `elgg-plugin.php` or `start.php` → **single-plugin mode**,
     `PLUGINS_SOURCE` = parent of cwd, plugin id = basename of cwd.
   - If cwd contains one or more subdirectories holding those files →
     **fleet mode**, `PLUGINS_SOURCE` = cwd.
4. Ask the user for an absolute path.

Persist the resolved value back to `config.json`. Never write into the plugin
dir and never into the skill dir.

**2. `ELGG_MIGRATE_STATE`** — where per-job state (reports, logs, dep locks,
container names) lives. Default:
`${XDG_STATE_HOME:-$HOME/.local/state}/elgg-migrate/`. Each migration job
gets its own subdirectory: `$ELGG_MIGRATE_STATE/jobs/<plugin-id>-<short-sha>/`.

**3. Skill infra root** — Docker templates, install scripts, and other
runtime assets live at `<skill-root>/infra/`, resolved relative to this
`SKILL.md` at runtime. Do not hard-code repo paths; the skill must run
identically whether it's installed globally, vendored into a project, or
loaded from a worktree.

Every example in this skill uses `$PLUGINS_SOURCE`, `$ELGG_MIGRATE_STATE`,
and `$SKILL_INFRA` as the only roots. If you find yourself typing an
absolute host path, stop and re-run Step 0.

## Required Reading

Before starting any migration, the agent MUST consult the relevant docs in `references/`:

| Doc | When to read |
|-----|--------------|
| `references/version-api-boundaries.md` | Before applying any rules — confirms which APIs are valid for the target version |
| `references/plugin-architecture-by-version.md` | During setup and when writing ARCHITECTURE.md — defines target structure |
| `references/coding-standards.md` | Before running rules (baseline) and before committing (verify) — version-specific style rules |
| `references/security-review-checklist.md` | After running `--security` — interpret findings |
| `references/dependabot-alerts.md` | During Phase 1 pre-flight when the plugin is hosted on GitHub — query, triage, and record the alert baseline before migrating |
| `references/llm-security-review.md` | During the LLM security review step — second-stage workflow |
| `references/post-migration-documentation.md` | When writing ARCHITECTURE.md — template |
| `references/git-hygiene.md` | Before every commit — what belongs (and doesn't) in plugin and site repos |

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
11. **COMPOSER CONSTRAINTS ARE NON-NEGOTIABLE** — Set `elgg/elgg` and `php` per the version table in "Composer Requirements Per Migration Branch" below. Wrong constraints are silent bugs that only surface when someone tries to install the plugin.
12. **EVERY MIGRATE BRANCH NEEDS DOCKER INFRA** — Copy the template from `<skill-infra>/infra/elgg{N}/` to `docker/` on every migrate branch. Without Docker infra, the branch cannot be tested.
13. **EACH BRANCH MUST BE BASED ON THE PREVIOUS** — `migrate/elgg-N.x` must be based on `migrate/elgg-(N-1).x`. Create it with `git checkout migrate/elgg-(N-1).x && git checkout -b migrate/elgg-N.x` or merge it in before starting migration work.

---

## Working safely as an agent

Before starting a migration, read `references/agent-failure-modes.md`. It
covers the cross-cutting guidance that applies to every workflow in this
skill:

- **Cost of failure** — which Iron Laws are unrecoverable vs cosmetic,
  so under pressure you cut the right corners.
- **When to stop and escalate** — signals that a case isn't "keep trying"
  but "surface the block to the human."
- **Agent failure modes** — hallucinated APIs, fabricated gate results,
  cross-version knowledge leakage, and other failures invisible to the
  gates.
- **Recovery playbook** — what to do when AST rules produce broken code,
  activation fails opaquely, or a session runs out of context mid-migration.

These are the failure modes that silently ruin migrations. The gates
below won't catch them — you have to.

---

## Container Infrastructure

All operations run inside Docker containers — nothing executes on the host machine.

### Per-plugin isolation invariant (MANDATORY)

Every bind mount from the host plugin workspace into a container MUST be
scoped to the single plugin under test:

```yaml
# CORRECT — isolated
- ${PLUGINS_DIR}/${PLUGIN_ID}:/plugins/${PLUGIN_ID}

# BANNED — exposes every sibling plugin to destructive commands
- ${PLUGINS_DIR}:/plugins
```

This applies to `migrate`, `elgg`, and `node` services uniformly. The only
exception is `docker-compose.bodyology.yml`, which legitimately mounts a
whole-site runtime checkout and is NOT used for migrations.

**Why this rule exists** — 2026-04-13 fleet wipe: the `node` service mounted
`${PLUGINS_DIR}:/plugins` read-write, and a destructive command inside the
container propagated to 44 of 47 plugins on the host. Per-plugin isolation
guarantees blast radius = one plugin, even under the worst in-container
command. See bead `elgg-migrate-c0ou`.


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

## Composer Requirements Per Migration Branch

When generating or updating `composer.json` on a migrate branch, these are the
REQUIRED constraints. Do not deviate.

| Branch | `elgg/elgg` | `php` | Docker PHP |
|--------|-------------|-------|-----------|
| `migrate/elgg-3.x` | `^3.0` | `>=7.2` | 7.4 |
| `migrate/elgg-4.x` | `^4.0` | `>=7.4` | 7.4 |
| `migrate/elgg-5.x` | `~5.1.0` | `>=8.1` | 8.2 |
| `migrate/elgg-6.x` | `~6.1.0` | `>=8.2` | 8.2 |
| `migrate/elgg-7.x` | `~7.0.0` | `>=8.3` | 8.3 |

Also required: `"composer/installers": "^2.0"`.

After setting these, run `verify-plugin-branches.py` to confirm.

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
| `Seed` subclass present (or absence documented in ARCHITECTURE.md) | Plugins that own entity types/subtypes/relationships must seed them; tests reuse the same helpers, and a fleet without seeders has empty listings in dev/QA |
| Commit message format: `migrate({TARGET}.x): <summary>` | Consistent prefixes make `git log --grep` usable across the fleet |
| Issue closed with `--reason` | Future-you reading the beads history needs the "what changed" summary |
| `verify-plugin-branches.py` passes on the branch | Catches composer/PHP version drift, missing Docker infra, missing tests, README version mismatch, and branch linearity in one command. Run: `python3 <skill-root>/../../bin/verify-plugin-branches.py <plugin-dir>` |

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
- Gates 5, 9, 10, 8 (tests): `$SKILL_INFRA/../bin/elgg-migrate-verify <plugin-dir> [--phpunit]`

The `elgg-migrate-verify` script runs all Docker-based checks in one command
and outputs a structured PASS/FAIL report:

```bash
# After `docker compose -f docker/docker-compose.yml up -d` in the plugin dir:
~/.claude/skills/elgg-migrate/bin/elgg-migrate-verify /path/to/plugin
~/.claude/skills/elgg-migrate/bin/elgg-migrate-verify /path/to/plugin --phpunit
```

Gates covered: PHP syntax (excl. vendor/tests), homepage render (>1000 bytes),
login render (>1000 bytes), PHP Fatal/Error count in Apache log, PHPUnit suite.

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

### Dependabot alert baseline (when plugin is on GitHub)

If the plugin lives on GitHub, capture the open Dependabot alert list before
migrating. Most alerts get fixed naturally by bumping deps for the new Elgg
major — this baseline lets you verify that, rather than assume it.

```bash
OWNER_REPO=$(git -C "$PLUGIN_DIR" remote get-url origin \
  | sed -E 's#(git@github.com:|https://github.com/)##; s#\.git$##')

gh api "repos/${OWNER_REPO}/dependabot/alerts" --paginate \
  -q '.[] | select(.state=="open") |
       [.security_advisory.severity, .dependency.package.name,
        .security_advisory.ghsa_id, .dependency.manifest_path] | @tsv'
```

Skip cleanly when the check doesn't apply: plugin not on GitHub (no remote, or
remote is not `github.com`), `gh` not authenticated, repo returns `403` for
the alerts endpoint, or Dependabot is disabled (`404`). Log a one-line note
and move on — this is informational, not an acceptance gate.

When the check does apply, every open critical/high alert must be classified
before Phase 2: addressed by the migration (Elgg major bump, plugin dep bump,
abandoned-dep removal), or carried forward with a documented reason. Read
`references/dependabot-alerts.md` for triage rules and the post-migration diff
workflow.

### Branch linearity check

Before creating `migrate/elgg-N.x`, verify that `migrate/elgg-(N-1).x` exists
and is an ancestor of the new branch. Non-linear branch history makes
cross-branch diffs unreadable and can silently drop prior migration commits.

```bash
# Verify the previous branch exists and is an ancestor of the current HEAD
git log --oneline migrate/elgg-5.x..migrate/elgg-6.x  # commits on 6.x not in 5.x
git merge-base --is-ancestor migrate/elgg-5.x migrate/elgg-6.x  # true = linear
```

If the previous branch exists but is NOT an ancestor (non-linear), merge it
before continuing:

```bash
git merge migrate/elgg-5.x
```

If the previous branch does not exist at all, stop and complete that version
step first — Iron Law 1 forbids skipping.

### Coding style baseline

Capture the plugin's current style state before migrating so you can tell
whether the migration regressed it. Run `elgg-migrate-verify` (which includes
the PHPCS gate) against the running Elgg Docker stack to record the baseline
error count. Post-migration style must not regress.

The Elgg standard (`elgg/sniffs`) is installed at image build time via
`elgg-composer.json` (`require-dev`) and pre-configured in the Docker image —
`vendor/bin/phpcs --standard=Elgg` is ready to use inside the container
without any extra setup.

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
created — see `references/common-mistakes.md` for the recovery pattern. Add a
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

PHP_CodeSniffer runs **inside the Elgg Docker container** using the `Elgg`
standard from `elgg/sniffs`, which is installed at image build time and
pre-configured in every infra template (`elgg3`–`elgg7`). Use the plugin's
own Docker stack — not the host machine:

```bash
# Check (via elgg-migrate-verify — preferred)
elgg-migrate-verify /path/to/plugin

# Or run directly inside the container
docker compose -f docker/docker-compose.yml exec elgg \
  vendor/bin/phpcs --standard=Elgg \
  mod/<plugin-id>/ --ignore='*/vendor/*,*/tests/*,*/node_modules/*'

# Auto-fix mechanical violations
docker compose -f docker/docker-compose.yml exec elgg \
  vendor/bin/phpcbf --standard=Elgg \
  mod/<plugin-id>/ --ignore='*/vendor/*,*/tests/*,*/node_modules/*'
```

If `vendor/bin/phpcs` is missing, the stack was built from an old infra
template — rebuild with `docker compose build --no-cache elgg` after updating
`docker/elgg-composer.json` to include `squizlabs/php_codesniffer: ^3.9` and
`elgg/sniffs: dev-master` in `require-dev`.

Style evolves by version: 3.x+ uses PSR-2 with Elgg extensions, 4.x+ requires
strict types and return type hints, 5.x+ encourages union types and named
arguments, 6.x+ adds readonly properties and enums. Commit style fixes
separately: `git commit -m "style: fix coding standards for Elgg {TARGET}.x"`.

#### Introduce a Seeder subclass (where appropriate)

Every plugin that owns entity types, subtypes, custom metadata, or
relationships **MUST** ship a `Seeder` class that extends
`\Elgg\Database\Seeds\Seed` and is hooked into Elgg's `seeds, database`
event. This is a hard requirement from 3.x onward (when the `Seed` base
class became available) and applies equally to fresh migrations and to
plugins migrated in earlier sessions that lack one.

Why it's mandatory:

- Elgg's built-in `bin/elgg-cli database:seed` populates a site with
  realistic fake data; a plugin without a seeder leaves visible gaps in
  any seeded fleet (empty listings, missing relationships, broken UI
  flows in dev/QA).
- Integration and Playwright tests reuse the same `Seed` helpers
  (`createUser`, `createObject`, `createGroup`) for fixture setup —
  tagging entities with `__faker` metadata so cleanup is automatic. A
  plugin-owned seeder centralises fixture shapes so tests stay in sync
  with how the plugin actually creates entities.
- The contract surfaces a useful migration smoke test: if the seeder
  can't construct a valid entity using the target version's API, the
  plugin's CRUD path is broken regardless of what the activation gate says.

When it's NOT appropriate (document the reason and skip):

- Pure UI/widget plugins with no persisted state of their own (e.g.
  `ui_grid`, `ui_tabs`, `menus_dropdown`).
- Pure utility/library plugins that only register hooks/views/services
  but introduce no subtypes, no relationships, no plugin-owned metadata
  (e.g. `actions_feature`, `forms_api`, `hypeajax`).
- Admin-only tooling that operates on existing entities rather than
  creating new ones (e.g. `hypedbexplorer`).

Required shape (4.x+; adapt namespaces to the plugin's vendor):

```php
namespace <Vendor>\<Plugin>;

use Elgg\Database\Seeds\Seed;
use Elgg\Event;

class Seeder extends Seed {

    public static function getType(): string {
        return '<plugin-id>';
    }

    protected function getCountOptions(): array {
        return [
            'types' => 'object',
            'subtypes' => '<plugin-subtype>',
            'metadata_names' => '__faker',
        ];
    }

    public function seed() {
        while ($this->getCount() < $this->limit) {
            $owner = $this->getRandomUser() ?: $this->createUser();
            $this->createObject([
                'subtype' => '<plugin-subtype>',
                'owner_guid' => $owner->guid,
                'container_guid' => $owner->guid,
                'access_id' => ACCESS_PUBLIC,
                'title' => $this->faker()->sentence(6),
                'description' => $this->faker()->text(500),
            ]);
            $this->advance();
        }
    }

    public function unseed() {
        $entities = elgg_get_entities([
            'types' => 'object',
            'subtypes' => '<plugin-subtype>',
            'metadata_names' => '__faker',
            'limit' => 0,
            'batch' => true,
        ]);
        /* @var $entities \ElggBatch */
        $entities->setIncrementOffset(false);
        foreach ($entities as $e) {
            $e->delete();
            $this->advance();
        }
    }

    public static function addSeed(Event $event) {
        $value = $event->getValue();
        $value[] = __CLASS__;
        return $value;
    }
}
```

Wire the seeder up via the plugin's Bootstrap class (NOT in
`elgg-plugin.php` — Iron Law 5 forbids closures there):

```php
// classes/<Vendor>/<Plugin>/Bootstrap.php — inside init() or boot()
elgg_register_event_handler('seeds', 'database', [Seeder::class, 'addSeed']);
```

For 5.x+ migrations, the event payload uses `\Elgg\Event` (already
matches the example above). For 3.x, the older array-based hook signature
is acceptable but the class still extends `\Elgg\Database\Seeds\Seed`.

Verify the seeder runs end-to-end inside the target Docker container
before committing:

```bash
docker compose -f docker/docker-compose.yml exec elgg \
    php elgg-cli database:seed --type=<plugin-id> --limit=5
docker compose -f docker/docker-compose.yml exec elgg \
    php elgg-cli database:unseed --type=<plugin-id>
```

Both must complete without errors. If `--type` filtering doesn't yet
exist for the target version, run an unfiltered `database:seed --limit=5`
and confirm the plugin's entities appear (and are removed by `unseed`).

Commit separately: `git commit -m "feat: add Seeder for {TARGET}.x dev/test seeding"`.

If you skip this step because the plugin has no entity surface, record
that decision in `ARCHITECTURE.md` under a "Seeding" heading with one
sentence of rationale. A plugin without a seeder AND without a
documented reason fails the migration acceptance gate.

#### Document the result

After all gates pass, generate or update `ARCHITECTURE.md` in the plugin
root. This is the second-most valuable output of the migration after the
code itself — future migrations and future developers rely on it. Cover:

- **Plugin summary** — what it does, its entity types and subtypes
- **Directory structure** — current layout matching target-version conventions
- **Registered hooks/events** — all handlers declared in elgg-plugin.php
- **Routes, entities, actions, views** — what's exposed and where
- **Dependencies** — other plugins this plugin relies on
- **Seeding** — name of the `Seed` subclass and what it seeds; if the plugin ships no seeder, one sentence explaining why (no entity surface)
- **Migration notes** — what changed in this version step, known issues, workarounds

Update `CHANGELOG.md` with a human-readable summary of the version bump.
Commit: `git commit -m "docs: add plugin architecture summary for Elgg {TARGET}.x"`.

#### Update README and plugin docs

After documenting the architecture, update the public-facing docs to the hypeJunction standard:

1. **Audit:** `bin/audit-plugin-docs.sh <plugin-path>` — review all flagged issues (badge version, donation CTAs, hypejunction.com refs, missing description).
2. **Auto-fix:** `bin/fix-plugin-docs.sh <plugin-path> --apply` — rewrites badge, strips CTAs, replaces hypejunction.com URLs.
3. **Rewrite README.md** from `templates/README.md.tpl` — fill in NAME, ELGG_VERSION (from `elgg/elgg` constraint), REPO_SLUG, a fresh 1-2 sentence tagline, FEATURES, LICENSE. Drop all legacy stacked badges and donation/sponsor blocks.
3b. **Update compatibility table** — after rewriting README.md, update the `## Compatibility` section:
    - Read the `elgg/elgg` constraint from `composer.json` to derive the target Elgg major version (e.g. `~7.0.0` → `7.x`).
    - If the README has no `## Compatibility` section, append one with a starter row: `| current | 7.x |`.
    - If the README already has `## Compatibility`, prepend a new row for the current target version (`| current | <derived-version> |`) above existing rows.
    - Table header must always be: `| Plugin version | Elgg version |`
4. **Sync metadata:** update `composer.json` `"description"` and (if present) `manifest.xml` `<description>` / `elgg-plugin.php` `'description'` to the same tagline.
5. **GitHub:** `gh repo edit hypeJunction/<repo> --description "<tagline>"` — uses the actual remote URL from `git remote get-url origin`.
6. Commit: `git commit -m "docs: standardize README and plugin docs"`.

The audit script exits non-zero on any remaining issue — run it last as a gate before closing the beads sub-issue.

### Phase 3: Finalize

Review the branch history for a coherent story (each commit should stand on
its own), run `--security` one last time on the final state, and generate a
report. The acceptance gates at the top of this skill list everything that
must be true before closing the beads issue — verify each one explicitly.

---


## Plugin docs standard

Every plugin should have a clean, up-to-date `README.md` with a single correct Elgg version badge,
a one-line tagline, features list, and installation instructions.

### Template

`templates/README.md.tpl` — canonical README template. Placeholders:
`{{NAME}}`, `{{ELGG_VERSION}}`, `{{REPO_SLUG}}`, `{{TAGLINE}}`, `{{FEATURES}}`, `{{COMPATIBILITY_TABLE}}`, `{{LICENSE}}`.

The `## Compatibility` section lists which plugin version targets which Elgg major version. On each migration step, prepend a new row with `current` and the derived Elgg version (e.g. `7.x`). Older rows remain for historical reference. Example:

```markdown
## Compatibility

| Plugin version | Elgg version |
|---|---|
| current | 7.x |
| 3.x | 6.x |
| 2.x | 5.x |
```

### Scripts

Both scripts are path-agnostic and take a plugin directory as the only required argument.

**`bin/audit-plugin-docs.sh <plugin-path>`** — read-only reporter. Checks:
- README.md present (case-insensitive)
- Elgg badge present, exactly one, correct version (derived from `elgg/elgg` constraint in composer.json)
- `## Compatibility` section present in README.md
- `composer.json` description non-empty, `elgg/elgg` in `require`
- `manifest.xml` `<description>` non-empty (if manifest exists)
- `hypejunction.com` references (excluding vendor/, node_modules/, .git/)
- Donation/sponsor CTAs (`paypal.me`, `patreon.com`, `ko-fi.com`, `buymeacoffee`, "Support the development", "Buy me a")

Exits non-zero when any issue is found — suitable for gating per-plugin PRs in CI.

```bash
bin/audit-plugin-docs.sh "$PLUGINS_SOURCE/hypemaps"
```

**`bin/fix-plugin-docs.sh <plugin-path> [--apply]`** — semi-auto fixer. Dry-run by default.
- Derives correct badge from `elgg/elgg` constraint
- Replaces `hypejunction.com` URLs → `https://github.com/hypeJunction/<repo-slug>`
- Strips donation/sponsor CTAs
- Collapses duplicate/stale Elgg badges to the single correct one
- Appends a starter `## Compatibility` section if one is missing (uses derived Elgg version)

Does **not** generate the tagline, features, or full compatibility history — those require human input.

```bash
bin/fix-plugin-docs.sh "$PLUGINS_SOURCE/hypemaps"          # preview
bin/fix-plugin-docs.sh "$PLUGINS_SOURCE/hypemaps" --apply  # write
```

## Reference material

Pulled out of this file to keep it scannable. Load when you need it:

| File | When to read |
|------|--------------|
| `references/breaking-changes.md` | Before starting a version step — version-specific breaking changes, plugin architecture evolution (2.x through 6.x), per-step migration checklists |
| `references/common-mistakes.md` | When activation fails or a gate regresses — lookup table of 60+ observed mistakes and their fixes |
| `references/elgg-plugin-php-generation.md` | During 3→4 migrations — how the `GenerateElggPluginPhp` rule works, what it extracts automatically vs what needs a Bootstrap class, correct handler signatures |
| `references/agent-failure-modes.md` | At the start of every session — cost of failure, escalation criteria, agent failure modes, recovery playbook |
| `references/git-hygiene.md` | Before every commit — ready-to-paste `.gitignore` for plugins and Elgg sites, plus migration-specific pitfalls that put junk in history |
