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

**Automated discovery.** Use `bin/discover-plugins.sh` at the repo root to run
all of the above in one command and to wire the Docker compose environment:

```bash
# Scan, cache to XDG config, and write docker/elgg4/.env for the plugin under test
bin/discover-plugins.sh --root "$PLUGINS_SOURCE" --plugin <plugin-id> \
    --save-config --write-env docker/elgg4/.env

# Subsequent runs — just switch the plugin under test
bin/discover-plugins.sh --plugin <other-plugin-id>
```

`docker/elgg4/.env` (and the elgg3/elgg5/... equivalents) are **gitignored**.
They contain `PLUGINS_DIR` and `PLUGIN_ID`, which the per-version compose
override files consume to mount exactly one plugin at a time. Each migration
is therefore verified in isolation — no fleet-wide bind mounts, no cross-
plugin contamination. Baking any `/home/...` or `/Users/...` path into a
committed file is a bug; fix it by parameterizing via `.env`.

**2. `ELGG_MIGRATE_STATE`** — where per-job state (reports, logs, dep locks,
container names) lives. Default:
`${XDG_STATE_HOME:-$HOME/.local/state}/elgg-migrate/`. Each migration job
gets its own subdirectory: `$ELGG_MIGRATE_STATE/jobs/<plugin-id>-<short-sha>/`.

**3. Skill root** — Resolve `$SKILL` once at session start as the
absolute path of the directory containing this `SKILL.md`. The skill
bundles everything it needs under that root:

    <skill-dir>/
      SKILL.md                  # this file
      bin/migrate.php           # AST migration engine CLI
      bin/migrate-plugin.sh     # one-shot per-plugin wrapper
      bin/elgg-migrate-run      # isolated per-plugin orchestrator
      src/                      # ElggMigrate\ PHP namespace
      rules/{2..6}x-to-{3..7}x/ # per-version rule manifests
      composer.json             # nikic/php-parser dep + PSR-4 autoload
      phpunit.xml               # test runner config
      tests/                    # PHPUnit tests for src/
      references/               # required-reading docs
      formulas/                 # beads formulas (siblings only)
      infra/
        elgg{2..7}/             # per-target-version Docker stack
        migrate/                # AST engine Docker stack

`$SKILL_INFRA` is `$SKILL/infra`. Before running anything that depends
on the AST engine, run `cd $SKILL && composer install` once to create
`vendor/` — the skill ships composer.json but not vendor. Docker builds
handle this automatically inside the `migrate` container.

Every example uses `$PLUGINS_SOURCE`, `$ELGG_MIGRATE_STATE`, `$SKILL`,
and `$SKILL_INFRA` as the only roots. If you find yourself typing an
absolute host path or a CWD-relative `docker/elgg{N}/...` path, stop
and re-run Step 0.

## Required Reading

Before starting any migration, the agent MUST consult the relevant docs in `references/`:

| Doc | When to read |
|-----|--------------|
| `references/version-api-boundaries.md` | Before applying any rules — confirms which APIs are valid for the target version |
| `references/plugin-architecture-by-version.md` | During setup and when writing ARCHITECTURE.md — defines target structure |
| `references/coding-standards.md` | Before running rules (baseline) and before committing (verify) — version-specific style rules |
| `references/security-review-checklist.md` | After running `--security` — interpret findings |
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
11. **NEVER POLYFILL REMOVED APIS** — When the target version has removed a function, refactor every call site to the modern target-version API. Do NOT re-define the removed function in plugin code (no `if (!function_exists('foo')) { function foo() { ... } }` shims, no `lib/polyfills.php` files, no compatibility wrappers). Polyfills preserve the legacy call shape, drag forward patterns the next major will remove again, and hide the real surface area of the breaking change from any future audit. The migration's whole point is that the plugin is *on* the target API after the step closes — `grep <removed_func>` against the source must come up empty. Polymorphic guards on hook/event handler signatures are NOT polyfills and remain acceptable: those preserve a method's call signature so callers in version N and N+1 both work, they don't re-define removed APIs. If a refactor is non-trivial because of closure scoping or return-value handling, that effort *is* the migration — do it, don't shortcut it. If you're tempted to polyfill, that's a sign the plugin is too far from the target version and needs more migration work — surface to the human, don't paper over it.
12. **DOCUMENT EVERY SURPRISE** — Unexpected failures, hand-fixes, workarounds, and non-obvious gate failures belong in `SKILL.md`'s common-mistakes table, the relevant `rules/{from}-to-{to}/manifest.json`, or memory before the migration issue closes. The skill's durable value is the knowledge each migration generates; closing issues without capturing what you learned is how migrations get more expensive instead of cheaper. See **Capture before closing** below.

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
| `elgg` | Plugin activation, PHPUnit, Elgg bootstrap | `$SKILL_INFRA/elgg{N}/docker-compose.yml` |
| `node` | Playwright and Vitest tests | `$SKILL_INFRA/elgg{N}/docker-compose.yml` (profile: test) |
| `db` | MySQL database | `$SKILL_INFRA/elgg{N}/docker-compose.yml` |

### Quick setup

```bash
# Build the migration tool (once)
docker compose -f $SKILL_INFRA/migrate/docker-compose.yml build migrate

# Start target Elgg environment
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml up -d

# Run migration
docker compose -f $SKILL_INFRA/migrate/docker-compose.yml run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin>

# Run tests
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test"
```

### Debugging inside containers

```bash
# Elgg container logs (PHP errors, Apache errors)
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml logs elgg
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg tail -f /var/log/apache2/error.log

# Interactive shell in Elgg container
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg bash

# Check PHP error log
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg cat /var/log/apache2/error.log

# MySQL queries (interactive)
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec db mysql -uelgg -pelgg elgg

# Check container status
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml ps

# Node container — debug Playwright
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test --debug"

# Rebuild containers after Dockerfile changes
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml build --no-cache
```

---

## Quick Reference

| Step | Command |
|------|---------|
| Analyze | `docker compose -f $SKILL_INFRA/migrate/docker-compose.yml run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --dry-run` |
| Apply + all gates | `docker compose -f $SKILL_INFRA/migrate/docker-compose.yml run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --verify --security --audit` |
| LLM report | `docker compose -f $SKILL_INFRA/migrate/docker-compose.yml run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --dry-run --report` |
| Verify only | `docker compose -f $SKILL_INFRA/migrate/docker-compose.yml run --rm migrate bin/migrate.php rules/{from}-to-{to}/manifest.json /plugins/<plugin> --dry-run --verify --security --audit` |

### Shared rules that run on every migration

Every manifest (2x→3x, 3x→4x, 4x→5x, 5x→6x, 6x→7x) ends with
`999-add-docblocks`, a shared rule that walks every `.php` file in the
plugin and attaches a PHPDoc block to any function, method, or class
property that doesn't already have one. It infers types from PHP
hints (falling back to `mixed`), leaves existing docblocks alone, and
uses nikic/php-parser's format-preserving printer so nothing else in
the file is reformatted. The summary line is intentionally blank for
a human or LLM to fill in — the value is the type scaffolding, not
the prose. Constructors are rendered without `@return`, variadic
params render `...$name`, and `void` / `never` return types flow
through unchanged. It runs automatically as the last rule of every
manifest — no separate invocation needed. Re-running is idempotent.

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
- Gate 9-10: `docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg ...`

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
- An upstream fork has a working migration → use it instead of re-migrating, **but vet it first** (next subsection).
- Nothing exists anywhere → proceed to pre-migration tests.

### Trust but verify: adopting an upstream migration

Finding an upstream migration is not the same as adopting one. Upstream work
can be broken, stale, target a different fork of Elgg, or aim at a version
you're not migrating to. Before merging an upstream branch or `composer
require`-ing a new version:

- **Check what it actually targets.** Read the upstream's `composer.json`
  `require.elgg/elgg` — if it says `^5.0` and you're migrating to 4.x, it's
  not useful. If it says `^4.0` but the code already uses `\Elgg\Event`, the
  metadata is lying.
- **Look at the commit history, not just the branch name.** A branch called
  `migrate/elgg-4.x` that hasn't been touched in two years and has three
  commits is probably abandoned work, not a done migration. Check the latest
  commit date and how complete it looks.
- **Run the same Docker activation gate on it that you'd run on your own
  migration.** If it doesn't activate cleanly in your `elgg{N}` container, it
  isn't a working migration regardless of who wrote it.
- **Run your pre-migration tests against it.** If you've already written
  tests for the plugin (Phase 1.8), point them at the upstream version.
  Failing tests against an upstream migration tells you the upstream
  regressed something — sometimes fixable, sometimes a sign to walk away.
- **Check for unrelated changes.** Upstream branches often contain new
  features mixed with the migration. If the "migration" also adds a feature
  you don't want, the branch isn't a pure migration — you're adopting both.
- **Check the license and authorship.** A fork in a random org with no
  attribution is a licensing risk; a fork on the original author's account
  usually isn't.

When an upstream migration passes all these, adopt it with confidence and
note the source in your commit message ("Adopted from `<fork-url>@<sha>`").
When it fails any of them, document what failed and fall back to migrating
yourself — but keep the upstream work as a reference to diff against later
in Phase 2.

### Cross-plugin dependencies (run before Phase 2)

Before claiming the migration, find out what other plugins this one
depends on. Most plugins migrate independently; some genuinely don't.
Missing this is the single most common process miss in plugin migration —
you'll migrate the plugin, fail to activate it, and waste hours debugging
before realizing a dependency isn't ready.

There are **two kinds** of cross-plugin dependency. Both can sink the
Docker activation gate:

**1. Per-pair declared deps** — this plugin names another in its config or
guards on its presence. Detect by reading:

- **`composer.json` `require`** — the authoritative source from 3.x on.
  Any `"hypejunction/hypeX"`, `"coldtrick/..."`, or similar `vendor/plugin`
  line is a declared dependency.
- **`manifest.xml` `<requires><type>plugin</type><name>X</name></requires>`** —
  the 2.x equivalent. Translate these into composer requires during the
  3→4 step.
- **`elgg-plugin.php` `'plugin'.'dependencies'`** — the 4.x+ runtime
  declaration; should mirror the composer require list (plus core plugins
  like `members` that don't have separate composer packages).
- **Actual code** — `elgg_is_active_plugin('X')` guards, `use` statements
  referencing classes in another plugin's namespace, view extensions
  targeting another plugin's views. Quick check:
  `grep -rn "elgg_is_active_plugin\|hypejunction\\\\\|coldtrick\\\\" classes/ views/`.

Each declared dep must be present in the target Elgg container *at the
same version step* before this plugin's activation gate can pass. If a
dependency isn't migrated yet, stop — escalate, don't try to migrate
this plugin against an unmigrated neighbor.

**2. The bootCore() neighbor-services trap (Elgg 4.x+)** — `bootCore()`
triggers `plugins_load`, which `include`s **every** plugin's
`elgg-services.php` regardless of active state. If ANY one of them throws
(PHP-DI 5 syntax `\DI\object()` instead of `\DI\create()`; removed
`Elgg\Di\ServiceFacade` trait; missing 4.x class names; etc.), the
entire `bootCore()` aborts and **no plugin's `IntegrationTestCase` suite
can run** until the broken neighbor is fixed.

This is invisible to per-pair dep checks because the broken plugin
doesn't *declare* any relationship to its victims — they just share the
same `mod/` directory in the test container.

When a Phase 1.8 test run dies during Elgg bootstrap with no obvious
cause, suspect a broken neighbor's `elgg-services.php`. The fix is to
either migrate that neighbor first or stub it with `<?php return [];` —
but *never* leave a broken neighbor stubbed silently; file a separate
issue tracking the real fix.

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

The only exception is plugins with zero PHP logic (pure views/CSS/JS). For
those, document the exception in the commit message and rely on the Phase 2
Docker activation+render gate as the regression check — there's no behavior
to write a PHPUnit test against.

**Iron Law 4 waiver for 2.x starting points.** The skill ships Docker envs
for elgg3 and elgg4 only — there is no elgg2 environment. Strict
pre-migration test execution against the current version is structurally
impossible when migrating from 2.x. The accepted waiver:

1. Write the **PHPUnit unit suite** so it runs in a plain `php:8.1-cli`
   container against the 2.x source — autoload via a tiny
   `tests/bootstrap.php` and stub any global `elgg_*` functions and
   removed core interfaces (e.g. `\Elgg\Cache\Pool` in 2.x) in a
   `tests/phpunit/stubs/` file. Run it standalone:
   ```bash
   docker run --rm -v "$PWD:/plugin" -w /plugin --entrypoint sh \
     php:8.1-cli -c 'curl -sSL https://phar.phpunit.de/phpunit-10.phar \
     -o /tmp/phpunit.phar && php /tmp/phpunit.phar -c tests/phpunit.xml'
   ```
   This must pass against the unmigrated 2.x source — it's the part of
   the safety net you can actually green-light pre-migration.
2. Write the **Playwright smoke suite** ahead of time but defer first
   execution to the elgg3 container post-migration. The 2.x baseline
   for browser-level behavior is "uncovered" — accept it.
3. **Commit both suites on the source branch** (e.g. `master`) before
   creating the `migrate/elgg-3.x` branch. Note the waiver explicitly
   in the test commit message so the gate report can cite it.

This waiver is *only* for `from = 2.x`. From 3.x onward both elgg3 and
elgg4 envs exist and the strict gate applies normally.

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
| `require.composer/installers` | `^2.0` (3.x: `~1.0`) | |
| `require.<vendor>/<dep>` | one entry per `<requires><type>plugin</type>` in manifest.xml | |
| `require.<third-party>` | only NON-Elgg packages the plugin actually loads (Flintstone, Guzzle, etc.) | |
| `config.allow-plugins.composer/installers` | `true` | **required by composer 2.2+ from 3.x onwards** — without this, `composer install -d <plugin>` aborts |
| `extra.elgg-plugin.id` | the plugin id (lowercase dir name) | useful when name differs from id |

**Do NOT add `elgg/elgg` to `require`.** A plugin is always installed inside an Elgg site by being placed in `mod/`; it's never composer-installed standalone. Declaring `elgg/elgg` in the plugin's `require` causes `composer install -d mod/<plugin>` to try to resolve `elgg/elgg` transitively, which pulls in `bower-asset/fontawesome` from asset-packagist — a repo the plugin's `composer.json` doesn't (and shouldn't) declare. The Elgg version constraint already lives in `manifest.xml` (3.x) and `extra.elgg-plugin` metadata (4.x+); that's the right place for it. The `elgg/elgg` constraint table below is the constraint the *site* uses, not the plugin.

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
docker compose -f $SKILL_INFRA/migrate/docker-compose.yml run --rm migrate bin/migrate.php \
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
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg \
  find mod/<plugin-id> -name "*.php" -not -path "*/vendor/*" -exec php -l {} \; | grep -v "No syntax errors"
```

#### Install plugin dependencies

If the plugin has its own `composer.json` with third-party packages, install
them in the container before activation:

```bash
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg \
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
docker cp <plugin>/. $(docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml ps -q elgg):/var/www/html/mod/<plugin-id>/

# Activate — must succeed without throwing
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg php -r "
  require_once '/var/www/html/vendor/autoload.php';
  \$app = \Elgg\Application::getInstance(); \$app->bootCore();
  _elgg_services()->plugins->generateEntities();
  \$p = elgg_get_plugin_from_id('<plugin-id>');
  if (!\$p) { echo 'FAIL: not found'.PHP_EOL; exit(1); }
  try { \$p->activate(); echo 'OK'.PHP_EOL; }
  catch (\Throwable \$e) { echo 'FAIL: '.\$e->getMessage().PHP_EOL; exit(1); }
"

# Homepage renders — must return a full page, not a stub
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg \
  curl -sL http://localhost/ | wc -c

# No PHP errors in the log
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg \
  grep -c "PHP Fatal\|PHP Error" /var/log/apache2/error.log 2>/dev/null
```

Also verify the simplecache CSS is non-empty — css-crush v2.4 silently fails
on some CSS, and the only symptom is a zero-byte CSS file that doesn't block
activation but does break the site visually:

```bash
TS=$(docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec -T elgg \
  curl -sL http://localhost/ | grep -oP 'cache/\K\d+' | head -1)
SIZE=$(docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec -T elgg \
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

Run PHP_CodeSniffer against Elgg's ruleset for the target version:

```bash
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpcs --standard=<path-to-elgg-phpcs.xml> \
  mod/<plugin-id>/classes/ mod/<plugin-id>/actions/ mod/<plugin-id>/views/

# Auto-fix what's mechanical
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg \
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

### Capture before closing (mandatory, Iron Law 12)

The skill's durable value is the knowledge each migration generates, and
that only survives if the agent forces itself to capture it *before*
`bd close`. The biggest failure mode here is not "the agent wrote the
lesson in the wrong place" — it's "the agent closed the issue and moved
on, and the lesson evaporated."

Before closing **any** migration issue, ask two questions out loud:

1. **"Did I hit anything surprising?"** — count a hand-fix, an unexpected
   error, a workaround, or a gate that failed the first time as
   surprising. If yes, it belongs in one of the destinations below
   *before* the issue closes.
2. **"Would a future session migrating the next similar plugin benefit
   from what I learned?"** — if yes, that's the signal to capture it,
   even if it's just a memory note. The cost of capture is seconds; the
   cost of forgetting is hours, because the next session re-derives
   everything.

If you can't honestly answer "nothing surprising, nothing worth passing
on" — and you usually can't, because every non-trivial migration has
surprises — then capture is mandatory, not optional.

| Destination | What belongs there |
|-------------|-------------------|
| `rules/{from}-to-{to}/manifest.json` `llm_instructions` | An existing rule's prompt was wrong or incomplete |
| New rule entry in the same `manifest.json` | A breaking change the manifest was missing |
| `references/common-mistakes.md` | A recurring hand-fix or non-obvious gate failure |
| `references/breaking-changes/<version>.md` | A previously undocumented breaking change |
| `bd remember "<key>" "<lesson>"` (or auto-memory feedback file) | Plugin-migration insight that doesn't yet have a home |

When the same hand-fix appears in three or more separate plugin
migrations, file a P2 bead to promote it from documentation to an
automated AST rule. Don't try to write the rule mid-migration — finish
the current plugin first, then the new-rule bead is its own
single-plugin-scoped task.

### The first migration of a version step is different from the tenth

When this is the *first* plugin you're migrating across a particular
version boundary in the current session (or the first since the manifest
was last updated), treat it as a learning exercise rather than a
production run:

- **Invest in understanding, not speed.** Read the AST rule output
  fully, even for rules you've seen before. Watch for surprises.
- **Pick an easy first target if you have a choice.** A small,
  well-structured plugin with an upstream reference is the ideal first
  pick — it gives you a clean signal for what the rules should produce.
  Save the weird ones for later.
- **Push learnings into the manifest immediately.** If you hit a
  hand-fix on the first plugin of a step, update the rule's
  `llm_instructions` (or file a P2 bead for a new rule) before closing
  the issue. The next plugin should benefit from what you learned.
- **Expect the first migration of a step to take 3–5× longer than the
  average.** That's not a bug — that's where the manifest and skill
  documentation get refined. By the third or fourth plugin of the same
  step the rules should be in their final shape and the work nearly
  mechanical. If you're on plugin ten of a step and still finding new
  breaking changes, surface that to the human — either the rules are
  undercooked or the plugins are unusually diverse.

---


## Reference material

Pulled out of this file to keep it scannable. Load when you need it:

| File | When to read |
|------|--------------|
| `references/breaking-changes.md` | Before starting a version step — version-specific breaking changes, plugin architecture evolution (2.x through 6.x), per-step migration checklists |
| `references/common-mistakes.md` | When activation fails or a gate regresses — lookup table of 60+ observed mistakes and their fixes |
| `references/elgg-plugin-php-generation.md` | During 3→4 migrations — how the `GenerateElggPluginPhp` rule works, what it extracts automatically vs what needs a Bootstrap class, correct handler signatures |
| `references/agent-failure-modes.md` | At the start of every session — cost of failure, escalation criteria, agent failure modes, recovery playbook |
| `references/git-hygiene.md` | Before every commit — ready-to-paste `.gitignore` for plugins and Elgg sites, plus migration-specific pitfalls that put junk in history |
