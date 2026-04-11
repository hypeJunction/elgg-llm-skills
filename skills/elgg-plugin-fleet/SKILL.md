---
name: elgg-plugin-fleet
description: >
  Orchestrate migration of a fleet of Elgg plugins across multiple major versions.
  Scans a plugins directory, detects current versions, creates a migration matrix,
  tracks progress via beads, and feeds learnings back into migration skills.
  Triggers on "migrate all plugins", "fleet migration", "batch plugin upgrade".
---

# elgg-plugin-fleet

Orchestrate the migration of many Elgg plugins across multiple major versions,
delegating individual plugin work to the `elgg-migrate` skill and feeding
learnings back into the skill documentation.

## Iron Laws

1. **ONE MAJOR VERSION AT A TIME** — Migrate all plugins from N.x→(N+1).x before starting (N+1).x→(N+2).x.
2. **ASSESS BEFORE MIGRATING** — Always run the full pre-flight for every plugin before writing code. Existing branches, forks, and upstream versions save hours.
3. **DOCUMENT EVERY SURPRISE** — Any unexpected failure, workaround, or non-obvious fix must be added to `elgg-migrate/SKILL.md` (common mistakes table or pitfalls) and/or the relevant `rules/{from}-to-{to}/manifest.json`.
4. **TRACK IN BEADS** — Every plugin×version cell gets a beads issue. Close only after verification passes.
5. **FAIL FAST, FIX FORWARD** — If a plugin blocks the fleet, file the issue, skip it, continue with others. Come back after the rest pass.

---

## Usage

```
/elgg-plugin-fleet <plugins-dir> [--from=3.x] [--to=7.x]
```

**Example:**
```
/elgg-plugin-fleet ~/Data/hypejunction/plugins --from=3.x --to=7.x
```

---

## Workflow

### Phase 0: SCAN — Build the Migration Matrix

#### Step 0.1: Inventory all plugins

Scan the plugins directory and detect each plugin's current Elgg version:

```bash
PLUGINS_DIR=<plugins-dir>

for d in "$PLUGINS_DIR"/*/; do
  name=$(basename "$d")
  version="unknown"

  # Check local branches for migration work
  branches=$(git -C "$d" branch -a 2>/dev/null | grep -oP 'migrate/elgg-\K[0-9]+' | sort -n | tail -1)

  # Check version indicators in current branch
  if [ -n "$branches" ]; then
    version="${branches}.x"
  elif grep -q "'events'" "$d/elgg-plugin.php" 2>/dev/null && \
       ! grep -q "'hooks'" "$d/elgg-plugin.php" 2>/dev/null; then
    version="5.x+"
  elif [ -f "$d/elgg-plugin.php" ] && [ ! -f "$d/start.php" ]; then
    version="4.x"
  elif [ -f "$d/elgg-plugin.php" ] && [ -f "$d/start.php" ]; then
    version="3.x"
  elif [ -f "$d/start.php" ] && [ ! -f "$d/elgg-plugin.php" ]; then
    version="2.x"
  fi

  # Refine with manifest.xml
  if [ -f "$d/manifest.xml" ]; then
    manifest_ver=$(grep -A1 'elgg_release' "$d/manifest.xml" 2>/dev/null | grep version | grep -oP '>[^<]+<' | tr -d '><')
    if [ -n "$manifest_ver" ]; then
      version="${manifest_ver%.0}.x"
    fi
  fi

  echo "$name|$version"
done | sort | column -t -s'|'
```

#### Step 0.2: Detect the highest completed migration per plugin

For each plugin, check all `migrate/elgg-*` branches:

```bash
for d in "$PLUGINS_DIR"/*/; do
  name=$(basename "$d")
  highest=$(git -C "$d" branch 2>/dev/null | grep -oP 'migrate/elgg-\K[0-9]+' | sort -n | tail -1)
  current=$(git -C "$d" branch --show-current 2>/dev/null)
  echo "$name: current=$current highest_migrate=${highest:-none}"
done
```

#### Step 0.3: Build the matrix

Produce a matrix of plugin × version step, marking each cell:

| Status | Meaning |
|--------|---------|
| `done` | Branch exists with migration commits |
| `todo` | Needs migration |
| `skip` | Plugin doesn't exist at this version (e.g., created in 4.x) |
| `blocked` | Depends on another plugin being migrated first |
| `upstream` | Upgraded version available from upstream/fork |

Example matrix:

```
Plugin              3→4    4→5    5→6    6→7
elgg_lightbox       done   todo   todo   todo
elgg_tokeninput     todo   todo   todo   todo
Elgg-modal_info     todo   todo   todo   todo
Elgg-cropper        done   todo   todo   todo
Elgg-site_search    todo   todo   todo   todo
```

#### Step 0.4: Check upstream for existing migrations

For each `todo` cell, run the pre-flight checks from `elgg-migrate` Phase 1.5:

1. **Local branches** — `git branch -a | grep migrate`
2. **Upstream branches** — `gh api repos/<owner>/<plugin>/branches`
3. **Forks** — `gh api repos/<owner>/<plugin>/forks`
4. **Packagist** — `composer show <vendor>/<plugin> --all`
5. **Version-prefixed repos** — `gh search repos --owner <org> "Elgg{N}-<plugin>"`
6. **Elgg plugin directory** — check https://elgg.org/plugins

Any cell where an upstream migration exists → mark as `upstream` and note the source.

### Phase 1: CREATE BEADS — Issue Tracking Matrix

Create one beads issue per plugin×version cell that is `todo`:

```bash
# If using beads, install the formula first:
cp formulas/elgg-plugin-fleet.formula.json .beads/formulas/

# Then pour it:
bd mol pour elgg-plugin-fleet --var plugins_dir=~/Data/hypejunction/plugins --var from=3.x --var to=7.x

# Or create issues manually:
bd create \
  --title="Migrate <plugin> <from>→<to>" \
  --description="Migrate <plugin> from Elgg <from> to <to> using elgg-migrate skill. Plugin path: <plugins-dir>/<plugin>" \
  --type=task \
  --priority=2
```

**Naming convention:** `Migrate <plugin> <from>→<to>`

**Dependencies:** Each version step depends on the previous:
```bash
# migrate 4→5 depends on migrate 3→4
bd dep add <issue-4to5> <issue-3to4>
```

Create all issues for the current version step in parallel (use subagents).
Only create the next version step's issues after the current step is complete.

### Phase 2: MIGRATE — One Version Step at a Time

For each version step (e.g., all plugins 3.x→4.x):

#### Step 2.1: Pick a plugin from `bd ready`

```bash
bd ready  # Shows unblocked migration issues
```

#### Step 2.2: Run pre-flight (Phase 1.5 from elgg-migrate)

Check if the migration is already done (branches, forks, upstream).

#### Step 2.3: Execute migration using elgg-migrate skill

Follow the `elgg-migrate` workflow:
1. Create branch `migrate/elgg-{TARGET}.x`
2. Run automated AST rules
3. Apply LLM-guided fixes
4. Verify syntax
5. Validate in Docker (if available)
6. Commit

#### Step 2.4: Document learnings

**CRITICAL:** After each plugin migration, check for surprises:

- Did a rule fail or produce wrong output? → Update the rule's `llm_instructions` in `manifest.json`
- Did you discover a new breaking change not in the manifest? → Add a new rule entry
- Did you hit a common mistake not in the table? → Add it to `elgg-migrate/SKILL.md` common mistakes
- Did you find a pattern that could be automated? → Note it for future AST rule development

```bash
# Record learning in beads memory
bd remember "key-name" "description of what was learned"

# Add to common mistakes if applicable
# Edit skills/elgg-migrate/SKILL.md — add row to Common Mistakes table

# Add to manifest if new breaking change found
# Edit rules/{from}-to-{to}/manifest.json — add new rule entry
```

#### Step 2.5: Close the issue

```bash
bd close <issue-id>
```

#### Step 2.6: Repeat for all plugins in this version step

### Phase 3: ADVANCE — Move to Next Version Step

Once all plugins pass the current version step:

1. Verify all issues for this step are closed: `bd list --status=open | grep "<from>→<to>"`
2. Create issues for the next version step (Phase 1)
3. Repeat Phase 2

### Phase 4: REVIEW — Cross-Plugin Verification

After all plugins reach the target version:

1. Boot Docker with ALL plugins at the target version
2. Activate all plugins in dependency order
3. Verify site renders
4. Run E2E tests if available
5. Check for cross-plugin conflicts (CSS collisions, hook priority issues, etc.)

---

## Learning Feedback Loop

The most valuable output of fleet migration is **pattern recognition**. Track these:

### New rules to add

When you manually fix the same issue across 3+ plugins, it's a candidate for an automated rule:

```bash
bd create \
  --title="New rule: <description>" \
  --description="Pattern seen in <plugin1>, <plugin2>, <plugin3>. Should be automated." \
  --type=feature \
  --priority=3
```

### Skill improvements

After each version step, review and update:

| File | What to update |
|------|---------------|
| `skills/elgg-migrate/SKILL.md` | Common mistakes table, version-specific notes |
| `rules/{from}-to-{to}/manifest.json` | New rules, improved LLM instructions |
| `skills/elgg-site-upgrade/SKILL.md` | Upgrade path table, troubleshooting |
| `skills/elgg-site-upgrade/references/REFERENCE.md` | Learnings section, new issues found |
| `references/breaking-changes/overview.md` | New breaking changes discovered |
| `references/breaking-changes/removed-functions.md` | Functions not previously documented |

### Session handoff

At the end of each session, ensure:

```bash
# All completed work committed in each plugin repo
for d in "$PLUGINS_DIR"/*/; do
  git -C "$d" status --short
done

# Beads state reflects reality
bd list --status=in_progress  # Should be empty or claimed by you

# Learnings persisted
bd memories migration  # Check recent memories
```

---

## Parallel Execution Strategy

For large fleets (20+ plugins), use parallel subagents:

- **Assessment** (Phase 0): Run all pre-flight checks in parallel — one agent per plugin
- **Migration** (Phase 2): Run up to 3-5 plugins in parallel per version step
  - Each agent gets its own plugin via worktree isolation
  - Agents share the same manifest rules but work on independent codebases
  - Collect all learnings before starting the next batch

**Constraint:** Docker verification must be sequential (shared Docker environment).
Batch syntax checks and AST rules can run in parallel.

---

## Quick Reference

```bash
# Scan plugins
/elgg-plugin-fleet ~/Data/hypejunction/plugins --from=3.x --to=7.x

# Check progress
bd list --status=open | grep "Migrate"
bd stats

# Find ready work
bd ready

# After completing a migration
bd close <id>

# Record a learning
bd remember "key" "what you learned"

# Check what you've learned
bd memories migration
```
