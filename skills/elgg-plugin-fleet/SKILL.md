---
name: elgg-plugin-fleet
description: >
  Orchestrate migration of a fleet of Elgg plugins across multiple major versions.
  Scans a plugins directory, detects current versions, creates a migration matrix,
  tracks progress via beads, and feeds learnings back into migration skills.
  Triggers on "migrate all plugins", "fleet migration", "batch plugin upgrade".
---

# elgg-plugin-fleet

Orchestrate migration of many Elgg plugins across major versions, delegating
individual plugin work to `elgg-migrate` and feeding learnings back into the
skill documentation.

This skill is a **thinking framework**, not a checklist. Fleet migration is
mostly judgment: which plugin to touch next, how much to trust existing work,
when to skip and come back. The parts that *are* strict — the Iron Laws — are
strict because getting them wrong corrupts the fleet.

## Iron Laws (strict — do not improvise)

1. **ONE MAJOR VERSION AT A TIME** — Finish every plugin's N→(N+1) before any plugin starts (N+1)→(N+2). Mixing steps makes cross-plugin verification impossible.
2. **DOCKER IS AUTHORITATIVE** — A plugin is "migrated" only after it boots and activates in the target Elgg container. AST passes and syntax checks don't count.
3. **TRACK IN BEADS** — Every plugin×version cell gets a beads issue. No shadow TODOs, no mental queues. If it's not in beads, it doesn't exist.
4. **FAIL FAST, FIX FORWARD** — When a plugin blocks, file the issue, skip it, continue. Don't stall the fleet on one hard case.
5. **DOCUMENT EVERY SURPRISE** — Unexpected failures, workarounds, and non-obvious fixes belong in `elgg-migrate/SKILL.md` or the relevant `rules/{from}-to-{to}/manifest.json`. The fleet's real product is the knowledge it generates.

Everything else in this skill is guidance — apply judgment.

## Cross-cutting guidance (read once, applies to everything below)

Three sections in `elgg-migrate` apply to fleet work too and won't be
repeated here:

- **Cost of failure** — which gates are cheap to cut and which are
  catastrophic. The fleet is where you'll be tempted to cut the cheap ones
  to keep moving; knowing the asymmetry means cutting the right ones.
- **When to stop and escalate** — signals that a plugin isn't a "keep
  trying" case but a "flag and move on" case. Iron Law 4 (fail fast, fix
  forward) is the fleet expression of this.
- **Agent failure modes** — hallucinated APIs, fabricated gate results,
  cross-version knowledge leakage, shortcutting under context pressure.
  These compound in fleet work because each mistake gets repeated across
  plugins before anyone notices.
- **Recovery playbook** — what to do when a migration goes sideways
  mid-plugin, including the mid-session-handoff pattern that's essential
  for long fleet runs.
- **Git hygiene** — fleet runs produce a lot of local state (vendor dirs,
  caches, job artifacts). See `elgg-migrate/references/git-hygiene.md`
  for the ready-to-paste `.gitignore` for plugin repos and the list of
  things that must never land in a plugin's history during a migration.
  Run the pre-commit check from that doc before every plugin commit.

Read those sections in `elgg-migrate` before starting a fleet run.

---

## Skill layout (self-contained)

This skill ships the full Docker infra, the orchestrator CLI, AND the
entire AST migration engine. After `npx skills add`, the installed
directory looks like:

    <skill-dir>/
      SKILL.md                 # this file
      bin/elgg-migrate-run     # per-plugin isolated orchestrator CLI
      bin/migrate.php          # AST migration engine CLI
      bin/migrate-plugin.sh    # one-shot per-plugin wrapper
      src/                     # ElggMigrate\ PHP namespace
      rules/{2..6}x-to-{3..7}x/ # per-version rule manifests
      composer.json            # nikic/php-parser dep + PSR-4 autoload
      phpunit.xml              # test runner config
      tests/                   # PHPUnit tests for src/
      formulas/                # fleet beads formula
      infra/elgg{N}/           # per-target Elgg stack (N = 2..7)
      infra/migrate/           # AST engine Docker stack

Resolve `$SKILL` once at session start as the absolute path of the
directory containing this SKILL.md, and `$SKILL_INFRA` as `$SKILL/infra`.
Every `$SKILL_INFRA/elgg{N}/...` and `$SKILL_INFRA/migrate/...` path
below is literal. Prefer the bundled `bin/elgg-migrate-run` CLI for
spawning isolated per-plugin environments; it already knows how to
locate `$SKILL_INFRA` and writes job state under
`$XDG_STATE_HOME/elgg-migrate/`. The engine files (src/, rules/, bin/,
composer.json, infra/migrate/) are kept in sync with the canonical
copy in the elgg-migrate skill by `bin/gen-elgg-infra.sh`.

## Container Infrastructure

All build/test/migration operations run inside Docker containers. Nothing
executes on the host. See `elgg-migrate` for setup details.

| Operation | Container | Command pattern |
|-----------|-----------|----------------|
| AST migration | `migrate` | `docker compose -f $SKILL_INFRA/migrate/docker-compose.yml run --rm migrate bin/migrate.php ...` |
| Plugin activation | `elgg` | `docker compose -f $SKILL_INFRA/elgg{N}/... exec elgg php -r "..."` |
| PHPUnit tests | `elgg` | `docker compose -f $SKILL_INFRA/elgg{N}/... exec elgg vendor/bin/phpunit ...` |
| Playwright tests | `node` | `docker compose -f $SKILL_INFRA/elgg{N}/... --profile test run --rm node ...` |
| Composer | `elgg` | `docker compose -f $SKILL_INFRA/elgg{N}/... exec elgg composer ...` |

### Debugging

```bash
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml logs elgg
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg tail -f /var/log/apache2/error.log
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec db mysql -uelgg -pelgg elgg
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg bash
```

---

## Usage

```
/elgg-plugin-fleet <plugins-dir> [--from=3.x] [--to=7.x]
```

Example:

```
/elgg-plugin-fleet "$PLUGINS_SOURCE" --from=3.x --to=7.x
```

`$PLUGINS_SOURCE` is resolved by Step 0 of `elgg-migrate` (env var, XDG
config cache, cwd inference, or prompt — in that order). The skill never
hard-codes a host path. If you invoke this skill without a plugins dir
argument and no cache exists, it will ask.

Automated discovery: run `bin/discover-plugins.sh` at the repo root. It
scans a workspace for Elgg plugins, persists the root to the XDG config
cache, and writes the gitignored `docker/elgg{N}/.env` consumed by the
per-plugin compose override. Each plugin is migrated and verified in
isolation — the compose override mounts exactly one plugin at a time, so
no fleet-wide bind mounts bleed plugin state across runs.

```bash
bin/discover-plugins.sh --root "$PLUGINS_SOURCE" --save-config --list
bin/discover-plugins.sh --plugin hypeembed --write-env docker/elgg4/.env
```

---

## How to think about fleet migration

Fleet work has three hard questions and everything else follows from them:

1. **Where is each plugin actually starting from?** Not what the manifest claims — what the code, branches, and upstream forks reveal.
2. **What's already been done for me?** Existing migration branches, forks, or upstream releases can save hours per plugin. Missing this is the most expensive mistake you can make.
3. **What order minimizes blocking?** Plugins with dependents should go first. Plugins that keep failing should be deferred, not retried in place.

The phases below are a natural shape for the work, not a procedure to execute line-by-line.

---

## Phase 0: Scan — build a picture of the fleet

The goal is a matrix: plugin × version step, each cell labeled `done`, `todo`,
`skip`, `blocked`, or `upstream`. How you build it matters less than getting it
right.

### Signals that reveal a plugin's current version

Look at as many of these as needed to be confident. They contradict each other
sometimes — when they do, trust the code over the manifest.

- **Existing `migrate/elgg-*` branches** — the highest-numbered one usually reflects the real state of migration work.
- **File presence** — `elgg-plugin.php` without `start.php` → ≥4.x; both present → 3.x transitional; only `start.php` → 2.x.
- **API usage in `elgg-plugin.php`** — `'events'` but no `'hooks'` suggests 5.x+ (hooks were merged into events).
- **`manifest.xml`** — declared `elgg_release` is a hint, but often stale.
- **composer.json / `require`** — an `elgg/elgg` constraint is usually honest about the target.

A reference bash one-liner for bulk scanning lives at the bottom of this
section. Use it when you want a fast first pass; don't mistake it for ground
truth. Any plugin the script marks "unknown" needs a human look.

### Checking for existing migration work (don't skip this)

For every cell that looks like `todo`, before doing any work, check whether
someone has already done it:

- Local branches: `git -C <plugin> branch -a | grep migrate`
- Upstream branches on the source repo: `gh api repos/<owner>/<plugin>/branches`
- Forks: `gh api repos/<owner>/<plugin>/forks`
- Packagist (inside the Elgg container): `composer show <vendor>/<plugin> --all`
- Version-prefixed org repos: `gh search repos --owner <org> "Elgg{N}-<plugin>"`
- The Elgg plugin directory: https://elgg.org/plugins

If any of these turn up a usable migration, mark the cell `upstream` and note
the source. This is the highest-leverage step in the entire fleet workflow —
one hit saves an entire migration.

### Trust but verify: adopting an upstream migration

Finding an upstream migration is not the same as adopting one. Upstream
work can be broken, stale, targeting a different fork of Elgg, or aimed at
a version you're not migrating to. Before merging an upstream branch or
`composer require`-ing a new version:

- **Check what it actually targets.** Read the upstream's `composer.json`
  `require.elgg/elgg` — if it says `^5.0` and you're migrating to 4.x,
  it's not useful. If it says `^4.0` but the code already uses `\Elgg\Event`,
  the metadata is lying.
- **Look at the commit history, not just the branch name.** A branch
  called `migrate/elgg-4.x` that hasn't been touched in two years and
  has three commits is probably abandoned work, not a done migration.
  Check the latest commit date, and how complete it looks.
- **Run the same Docker activation gate on it that you'd run on your own
  migration.** If it doesn't activate cleanly in your elgg{N} container,
  it isn't a working migration regardless of who wrote it.
- **Run your pre-migration tests against it.** If you've already written
  tests for the plugin, point them at the upstream version. Failing tests
  against an upstream migration tells you the upstream regressed
  something — sometimes fixable, sometimes a sign to walk away.
- **Check for unrelated changes.** Upstream branches often contain new
  features mixed with the migration. If the upstream "migration" also adds
  a feature you don't want, the branch isn't a pure migration — you're
  adopting both.
- **Check the license and authorship.** A fork in a random org with no
  attribution is a licensing risk; a fork on the original author's account
  usually isn't.

When an upstream migration passes all these, adopt it with confidence and
note the source in your commit message ("Adopted from
<fork-url>@<sha>"). When it fails any of them, document what failed and
fall back to migrating yourself — but keep the upstream work as a
reference to diff against.

### Matrix status labels

| Status | Meaning |
|--------|---------|
| `done` | Branch exists with migration commits, verified in Docker |
| `todo` | Needs migration |
| `skip` | Plugin doesn't exist at this version (e.g., created in 4.x) |
| `blocked` | Waits on another plugin (e.g., a dependency) |
| `upstream` | Upgraded version available from upstream/fork |

Example:

```
Plugin              3→4    4→5    5→6    6→7
elgg_lightbox       done   todo   todo   todo
elgg_tokeninput     todo   todo   todo   todo
Elgg-cropper        done   todo   todo   todo
```

### Reference: bulk-scan one-liner

Useful for a fast first pass across a large directory. Refine any "unknown"
results by hand.

```bash
PLUGINS_DIR=<plugins-dir>
for d in "$PLUGINS_DIR"/*/; do
  name=$(basename "$d")
  version="unknown"
  branches=$(git -C "$d" branch -a 2>/dev/null | grep -oP 'migrate/elgg-\K[0-9]+' | sort -n | tail -1)
  if [ -n "$branches" ]; then
    version="${branches}.x"
  elif grep -q "'events'" "$d/elgg-plugin.php" 2>/dev/null && ! grep -q "'hooks'" "$d/elgg-plugin.php" 2>/dev/null; then
    version="5.x+"
  elif [ -f "$d/elgg-plugin.php" ] && [ ! -f "$d/start.php" ]; then
    version="4.x"
  elif [ -f "$d/elgg-plugin.php" ] && [ -f "$d/start.php" ]; then
    version="3.x"
  elif [ -f "$d/start.php" ] && [ ! -f "$d/elgg-plugin.php" ]; then
    version="2.x"
  fi
  echo "$name|$version"
done | sort | column -t -s'|'
```

---

## Phase 1: Track — shape of the beads graph

The point of this phase is not to create a specific number of issues — it's to
make the dependency graph reflect the real ordering constraints so `bd ready`
surfaces genuinely unblocked work.

### The tracking graph, shaped by constraints

For each plugin, the issues form a chain where each link represents a real
blocker:

```
pre-migration tests  →  N→N+1  →  N+1→N+2  →  ...
```

Why this shape:

- **Tests come first** because they're the regression safety net. Without them
  you can't tell whether a migration broke behavior.
- **Each version step blocks the next** because Iron Law 1 forbids skipping.
- **No cross-plugin edges by default** — plugins migrate independently unless
  one genuinely imports another.

### When the default shape doesn't fit

- **Plugin already has tests** (existing PHPUnit/Playwright coverage from the original authors) → don't create a test issue; link the first migration step directly.
- **Plugin is pure JS/CSS/templates** with no PHP behavior → a test issue may be unnecessary; a smoke test in the fleet-wide verification phase is enough. Judgment call.
- **Plugin starts at 4.x** (no 3→4 step exists) → tests block 4→5 directly.
- **Plugin depends on another plugin** (e.g., hypeInbox needs hypePrototyper migrated first) → add the cross-plugin dependency explicitly.

### Creating the graph

The boilerplate for a standard chain:

```bash
# Pre-migration tests (if needed)
bd create --title="Add pre-migration tests: <plugin>" \
          --description="Regression safety net before migration." \
          --type=task --priority=0

# One issue per todo cell
bd create --title="Migrate <plugin> <from>→<to>" \
          --description="Plugin path: <plugins-dir>/<plugin>" \
          --type=task --priority=2

# Wire dependencies
bd dep add <issue-3to4> <issue-tests>
bd dep add <issue-4to5> <issue-3to4>
```

A beads formula exists for the common shape:

```bash
cp formulas/elgg-plugin-fleet.formula.json .beads/formulas/
bd mol pour elgg-plugin-fleet --var plugins_dir="$PLUGINS_SOURCE" --var from=3.x --var to=7.x
```

Use the formula when the default shape fits. When it doesn't, wire the issues
by hand — the formula is a convenience, not a requirement.

After creating issues, sanity-check the graph:

```bash
bd blocked    # Each migration issue should list its blockers
bd orphans    # Should be empty
```

### Cross-plugin dependencies (MANDATORY pre-flight, not optional)

**Run the dependency check BEFORE claiming any per-plugin migration bead.** Skipping it is the most common process miss in fleet work and the one that wastes the most hours. Most plugins migrate independently, but some genuinely depend on others — plugin A requires plugin B to be installed, or plugin A extends plugin B's views or hooks. Miss these and you'll migrate A, fail to activate it, and waste hours debugging before realizing B isn't ready yet.

There are **two kinds** of cross-plugin dependency in Elgg fleet migration. Both must be wired into beads before per-plugin work starts:

**1. Per-pair declared deps** — plugin A names plugin B in its config or guards on its presence. Detect by reading:

- **`composer.json` `require`** — the authoritative source from 3.x on.
  Any `"hypejunction/hypeX": "..."` line is a declared dependency.
- **`manifest.xml` `<requires><type>plugin</type>`** — the 2.x equivalent;
  same meaning. 3→4 translates these into composer requires.
- **`elgg-plugin.php` `'plugin'` key's `requires` array** — occasionally
  used in 4.x+ for runtime checks.
- **Actual code** — `elgg_is_active_plugin('X')` guards, `use` statements
  referencing classes in another plugin's namespace, view extensions
  targeting another plugin's views. The grep-level check:
  `grep -rn "elgg_is_active_plugin\|hypejunction\\\\\|coldtrick\\\\" <plugin>`.

**2. Fleet-wide boot deps (the trap)** — Elgg 4.x+ triggers `plugins_load` during `bootCore()` which `include`s **every** plugin's `elgg-services.php`, regardless of `active_plugin` state. If ANY one of them throws (PHP-DI 5 syntax `\DI\object()` instead of `\DI\create()`; removed `Elgg\Di\ServiceFacade` trait; missing 4.x class names; etc.), the entire bootCore aborts and **no plugin's `IntegrationTestCase` suite can run** until the broken neighbor is fixed. This is invisible to per-pair dep checks because the broken plugin doesn't *declare* any relationship to its victims — they just share the same `mod/` directory.

This means there is a **fleet-wide precondition** that every per-plugin test gate depends on: every plugin in `mod/` must have a `plugins_load`-safe `elgg-services.php` (it just has to parse and return — even `<?php return [];` is enough as a stub). File this as a single beads issue at the start of every fleet boundary:

```bash
PRECOND=$(bd create --title="Fleet precondition: plugins_load-safe baseline for elgg{N+1} mod/" \
                    --type=task --priority=0 \
                    --description="Every plugin's elgg-services.php must at minimum parse before any per-plugin test gate can run in IntegrationTestCase. Stub broken neighbors with <?php return []; if you can't migrate them yet — but track each as a real follow-up.")
```

Then wire **every** per-plugin migration bead to depend on it, and resolve it as the first step of any new fleet boundary by sweeping all plugins for `\DI\object`, `Elgg\Di\ServiceFacade`, and other patterns that stop loading.

Encode all dependencies as beads edges:

```bash
# Per-pair: hypeInbox's 3→4 migration blocks on hypePrototyper's 3→4 being done first
bd dep add <hypeinbox-3to4> <hypeprototyper-3to4>

# Fleet-wide: every per-plugin migration blocks on the precondition
bd dep add <hypeinbox-3to4> <precond-issue>
```

**Circular dependencies are a red flag.** If A requires B and B requires A,
that's almost always a bug in one of them — surface it to the human rather
than trying to break the cycle yourself. In practice circularity usually
means one plugin should be merged into the other, or a shared library
extracted.

**Activation order matters for verification.** Record the order from the
running site (or from the plugin manager's priority) into
`mod/.plugin-order.txt` and reproduce it in Docker. A plugin that activates
fine in isolation can fail when activated before its dependency.

---

## Phase 2: Migrate — the inner loop

For each version step (e.g., all plugins 3→4), repeat until the step is clear:

1. **Pick the next plugin.** `bd ready` lists unblocked work, but the order
   matters. Prefer plugins with dependents (they unblock others), plugins
   you're confident about (to build momentum), or plugins whose pre-flight
   already found an upstream migration (nearly free wins). Leave known-hard
   plugins for later in the batch — fail-fast doesn't mean fail-first.

2. **Pre-flight.** Even if Phase 0 already checked for upstream work, re-check
   before starting — things move fast in active orgs. The `elgg-migrate` Phase
   1.5 pre-flight is the authoritative check.

3. **Run the migration via `elgg-migrate`.** Follow that skill's workflow.
   Don't duplicate its gates here — they live in one place for a reason.

4. **Capture surprises as they happen, not at the end.** If you just hit
   something non-obvious, write it down before moving on. Options in order of
   preference:
   - A rule's `llm_instructions` in `rules/{from}-to-{to}/manifest.json`
     (when the surprise is an AST rule being wrong)
   - A new rule entry in the same manifest (when the surprise is a missing
     breaking change)
   - A row in `elgg-migrate/SKILL.md`'s common mistakes table (when the
     surprise is a recurring hand-fix)
   - `bd remember "<key>" "<lesson>"` (when it's fleet-wide knowledge)

   The rule of thumb: if you expect to hit this again, persist it somewhere
   future-you will find it. If you're not sure where it belongs, `bd remember`
   is cheap — you can upgrade it to a rule later.

5. **Close the issue.** `bd close <id>`. Not before Docker verification.

When the same fix appears in three or more plugins, stop and consider whether
it should become an automated rule rather than a documented workaround.

---

## Phase 3: Advance to the next version step

Don't advance until the current step is *really* done. A fast check:

```bash
bd list --status=open | grep "<from>→<to>"
```

Should be empty. If it isn't, the step isn't done — either finish or
explicitly mark the remaining cells `blocked` with a reason. Creating
(N+1)→(N+2) issues while (N)→(N+1) is still open violates Iron Law 1.

## Phase 4: Fleet-wide verification

After all plugins reach the target version, the per-plugin gates aren't
sufficient — cross-plugin problems (CSS collisions, hook priority conflicts,
activation order issues) only surface when everything runs together.

Boot Docker with every plugin at the target version, activate them in
dependency order, and verify:

- Site renders without fatal errors
- E2E tests pass (if any exist at the fleet level)
- No obvious visual regressions on shared views

Any fleet-wide issue found here is its own beads issue, not a reopening of the
per-plugin ones.

---

## The first plugin is different from the twentieth

The first plugin of a version step is a learning exercise: you're
discovering which manifest rules are wrong, which breaking changes aren't
yet in the tables, which patterns are going to recur. By the twentieth
plugin in the same step, most of that work should be done — the remaining
plugins should be nearly mechanical.

This asymmetry is worth front-loading deliberately. On the first plugin:

- **Invest in understanding, not speed.** Read the AST rule output fully,
  even for rules you've seen before. Watch for surprises.
- **Pick an easy first target.** A small, well-structured plugin with an
  upstream reference is the ideal first pick — it gives you a clean signal
  for what the rules should produce. Save the weird ones for later.
- **Push learnings into the manifest immediately.** If you hit a hand-fix
  on plugin #1, update the rule's `llm_instructions` before moving on.
  Plugin #2 should benefit from what you learned on plugin #1, not
  rediscover it.
- **Expect the first to take 3–5× longer than the average.** That's not
  a bug — it's where the investment happens.

By the time you've done three or four plugins of a given version step, the
rules and skill documentation should be in their final shape for that step.
If you're on plugin ten and still finding new breaking changes, something
is wrong — either the rules are undercooked or the plugins are unusually
diverse. Surface that to the human.

## Mid-session handoff

Fleet runs are long. Sessions hit context limits. The agent that started
isn't always the agent that finishes. Handoff done badly is worse than
starting fresh — the next session inherits half-finished work with no
clear picture of what state it's in.

The minimum state a handoff must preserve:

- **Which plugin × version cell is currently open** — recorded in beads as
  `in_progress` with the assignee set, not held in session memory.
- **The last gate that passed** — in the beads issue's notes. "Docker
  activation PASS, tests not yet adapted" is enough; the next session
  reads this and knows where to start.
- **The branch name and the last commit** — also in the notes. A plugin
  repo with uncommitted changes in the workspace is a landmine for the
  next session.
- **Known blockers or surprises hit but not yet addressed** — `bd remember`
  any insight that would save the next session from re-deriving it.

Before letting a session end (voluntarily or not):

```bash
# Commit and push every plugin worktree
for d in "$PLUGINS_DIR"/*/; do
  if [ -n "$(git -C "$d" status --porcelain)" ]; then
    echo "UNCOMMITTED: $(basename "$d")"
  fi
done

# Update in-progress beads issues with current state
bd list --status=in_progress
bd update <id> --notes="<what gate just passed, what's next, what blocked>"

# Capture learnings before they evaporate
bd remember "<key>" "<insight>"
```

If you're running out of context mid-migration, commit even broken
intermediate state — a partial commit with a clear message ("WIP:
activation fails with X, needs investigation") is recoverable. An
uncommitted buffer you planned to "clean up at the end" is not.

---

## The real product: the learning loop

The migrated plugins are the visible output. The durable output is the
knowledge captured along the way — future fleets should be cheaper than this
one.

After each version step, ask: what did I learn that isn't yet written down?
Candidates for each destination:

| Destination | What belongs there |
|-------------|-------------------|
| `rules/{from}-to-{to}/manifest.json` | New breaking changes, improved LLM instructions for existing rules |
| `skills/elgg-migrate/SKILL.md` | Recurring hand-fixes, common mistakes |
| `skills/elgg-site-upgrade/SKILL.md` | Upgrade-path troubleshooting |
| `references/breaking-changes/*.md` | Previously undocumented breaking changes |
| `bd remember` | Fleet-wide insights that don't yet have a home |

If you can't decide where something belongs, `bd remember` it — uncategorized
knowledge beats lost knowledge.

### Forcing function: capture before closing

The learning loop only works if it *runs*. In practice the biggest failure
mode is not "the agent wrote the lesson in the wrong place" — it's "the
agent closed the issue and moved on, and the lesson evaporated." Guard
against this with a hard rule: **before closing any migration issue, ask
two questions out loud**:

1. **"Did I hit anything surprising?"** Count a hand-fix, an unexpected
   error, a workaround, or a gate that failed the first time as
   surprising. If yes, it belongs somewhere in the table above *before*
   the issue closes.

2. **"Would a future session migrating the next similar plugin benefit
   from what I learned?"** If yes, that's the signal to capture it —
   even if it's "just" a note in `bd remember`. The cost of capture is
   five seconds; the cost of forgetting is hours, because the next
   session re-derives everything.

If you can't honestly answer "nothing surprising, nothing worth passing
on" — and you usually can't, because every non-trivial migration has
surprises — then the capture step is mandatory, not optional. Close the
issue *after* the capture, not before.

The fleet's real product is knowledge. Closing issues without capturing
what you learned is how fleets get more expensive instead of cheaper.

### New-rule candidates

When a manual fix appears in 3+ plugins, it's worth considering as an
automated rule. File it for later:

```bash
bd create --title="New rule: <description>" \
          --description="Pattern seen in <plugin1>, <plugin2>, <plugin3>." \
          --type=feature --priority=3
```

### Session handoff

At the end of a session, these are the things that go wrong if you forget
them, in order of cost:

- Uncommitted work in a plugin repo — lost on next checkout
- `in_progress` beads issues you've actually finished — confuses the next session
- Learnings not yet written down — forgotten within a day

A quick sweep:

```bash
for d in "$PLUGINS_DIR"/*/; do git -C "$d" status --short; done
bd list --status=in_progress
bd memories migration
```

---

## Parallel execution

Fleet work parallelizes well in some phases and not at all in others.

- **Phase 0 pre-flight checks** — highly parallel. Fan out one subagent per
  plugin; the checks are read-only and independent.
- **Phase 2 migrations** — partially parallel. AST rules and syntax checks can
  run for multiple plugins in parallel, but **Docker verification is
  sequential** (shared environment). A reasonable batch size is 3–5 plugins
  per round, verified one at a time at the end.
- **Phase 4 fleet verification** — inherently serial. One Docker environment,
  one activation order.

Use worktree isolation when running parallel agents so they don't step on each
other's working directories.

---

## Quick reference

```bash
# Scan and start (uses $PLUGINS_SOURCE from Step 0; prompts if unset)
/elgg-plugin-fleet "$PLUGINS_SOURCE" --from=3.x --to=7.x

# Find work
bd ready
bd list --status=open | grep "Migrate"

# Close work
bd close <id>

# Capture and recall knowledge
bd remember "<key>" "<lesson>"
bd memories migration
```
