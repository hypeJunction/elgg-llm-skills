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

---

## Container Infrastructure

All build/test/migration operations run inside Docker containers. Nothing
executes on the host. See `elgg-migrate` for setup details.

| Operation | Container | Command pattern |
|-----------|-----------|----------------|
| AST migration | `migrate` | `docker compose run --rm migrate bin/migrate.php ...` |
| Plugin activation | `elgg` | `docker compose -f docker/elgg{N}/... exec elgg php -r "..."` |
| PHPUnit tests | `elgg` | `docker compose -f docker/elgg{N}/... exec elgg vendor/bin/phpunit ...` |
| Playwright tests | `node` | `docker compose -f docker/elgg{N}/... --profile test run --rm node ...` |
| Composer | `elgg` | `docker compose -f docker/elgg{N}/... exec elgg composer ...` |

### Debugging

```bash
docker compose -f docker/elgg{N}/docker-compose.yml logs elgg
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg tail -f /var/log/apache2/error.log
docker compose -f docker/elgg{N}/docker-compose.yml exec db mysql -uelgg -pelgg elgg
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg bash
```

---

## Usage

```
/elgg-plugin-fleet <plugins-dir> [--from=3.x] [--to=7.x]
```

Example:

```
/elgg-plugin-fleet ~/Data/hypejunction/plugins --from=3.x --to=7.x
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
bd mol pour elgg-plugin-fleet --var plugins_dir=~/Data/hypejunction/plugins --var from=3.x --var to=7.x
```

Use the formula when the default shape fits. When it doesn't, wire the issues
by hand — the formula is a convenience, not a requirement.

After creating issues, sanity-check the graph:

```bash
bd blocked    # Each migration issue should list its blockers
bd orphans    # Should be empty
```

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
# Scan and start
/elgg-plugin-fleet ~/Data/hypejunction/plugins --from=3.x --to=7.x

# Find work
bd ready
bd list --status=open | grep "Migrate"

# Close work
bd close <id>

# Capture and recall knowledge
bd remember "<key>" "<lesson>"
bd memories migration
```
