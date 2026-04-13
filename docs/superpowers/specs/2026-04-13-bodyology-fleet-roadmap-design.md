# Bodyology Fleet Migration Roadmap

**Date:** 2026-04-13
**Status:** Draft for review
**Scope:** Roadmap spec for migrating the bodyology plugin fleet from Elgg 3.x through 7.x and upgrading the bodyology site in lockstep. Per-phase specs will be created just-in-time when each campaign begins.

## Goal

Migrate the bodyology-forum production site from Elgg 3.3 to Elgg 7.x one major version at a time, by first migrating its plugin fleet across each version boundary, then upgrading the site to match. The durable output is not the migrated site — it is the updated migration rules, skill references, and beads history that make the next fleet cheaper than this one.

## Constraints (from skills — not decisions)

These are load-bearing rules from `elgg-migrate` and `elgg-plugin-fleet`. They are called out here so the roadmap is interpretable without reading the skills end-to-end.

- **Iron Law — one major version at a time (fleet-wide).** Finish every plugin's N→(N+1) before any plugin starts (N+1)→(N+2). Cadence is horizontal, not vertical, not hybrid.
- **Iron Law — never skip a major.** 3→4→5→6→7 only.
- **Iron Law — pre-migration tests are a strict gate.** No migration runs without a passing test baseline on the current version.
- **Iron Law — Docker is authoritative.** A plugin is "migrated" only after it boots, activates, and the homepage + login page render in the target elgg{N} container.
- **Iron Law — plugin dir name must match composer `name` (lowercase) from 4.x onward.** This forces camelCase plugin directories to be renamed during the 3→4 campaign.
- **Iron Law — linear version knowledge only.** When migrating N→N+1, only read skill reference sections for N and N+1. Reading ahead causes version drift.
- **Iron Law — track in beads.** Every plugin × version cell gets a beads issue with the right blocker edges. No shadow TODOs.
- **Forcing function — capture before closing.** No migration issue closes without answering "did I hit anything surprising?" and "would future-me benefit?" — surprises get written into refs/manifest/`bd remember` before the close.

## Workspace prerequisite: untangle

The current plugin workspace is unsafe (bind-mount wipe risk — cost us hypeSeo on 2026-04-12) and the symlink layout prevents clean per-plugin git work. This must be resolved before Step 1 resumes.

**Target state:**

- `~/Data/hypejunction/bodyology/plugins/<canonical-name>/` is the **single canonical copy** of every plugin bodyology uses. Canonical name = lowercase composer `name` field (matches Elgg 4.x+ Iron Law 6). E.g., `Elgg3-hypeAttachments` → `hypeattachments`.
- `bodyology-forum/mod/<canonical-name>/` holds **real rsynced copies**, disposable. Docker bind-mounts `mod/`. No host directory containing git state ever lives inside a bind-mount path — this is the actual safety fix.
- `~/Data/hypejunction/plugins/` and `~/Data/hypejunction/plugins-other/` are deleted once everything above is verified.

**Operations:**

1. Resolve every symlink under `bodyology-forum/mod/` to its real target. This produces the move list.
2. Move (preserve `.git` and `migrate/elgg-*` branches) each target into `bodyology/plugins/<canonical-name>/`. `git mv` where possible; `cp -a` + verify otherwise.
3. Fresh clone `github.com/hypeJunction/hypeSeo` → `bodyology/plugins/hypeseo/`. Check out the highest existing `migrate/elgg-*` branch; otherwise start from master. This is the only fresh clone — all other plugins move, not clone, to preserve in-progress work.
4. Rsync each `bodyology/plugins/<name>/` → `bodyology-forum/mod/<name>/` (delete old symlink, replace with directory). Canonical name applies on both sides.
5. Delete the 14 orphan empty directories in the old `plugins/` tree once confirmed unreferenced by any mod/ symlink.
6. Update `docker-compose.bodyology.yml` to stop mounting `~/Data/hypejunction/plugins*`; mount only `bodyology-forum/` as before.
7. Delete `~/Data/hypejunction/plugins/` and `~/Data/hypejunction/plugins-other/`.
8. Update `ELGG_MIGRATE_PLUGINS` env / XDG config cache (`~/.config/elgg-migrate/config.json`) to point at `~/Data/hypejunction/bodyology/plugins`.

**Side effect, intentional:** Renaming to lowercase completes a small part of each plugin's 4.x work early. The rename commit lands on the plugin's `migrate/elgg-4.x` branch and is reviewable in isolation.

## Step 1 — fleet 3.x → 4.x (complete)

Bring every bodyology plugin through a fully-gated 3→4 migration. Work already exists in `migrate/elgg-4.x` branches but has not passed the 15 acceptance gates in `elgg-migrate`.

**Observed entry state:**

- ~52 plugins on `migrate/elgg-4.x` branches (with a handful of exceptions like `hypeFilestore` still on master)
- ~29 plugin pre-migration-test beads issues in_progress
- ~50 "Migrate X to Elgg 4.x" beads issues open

**Work:**

1. **Rebuild the matrix.** Run `elgg-plugin-fleet` Phase 0 scan against `bodyology/plugins/`. Trust code over branch name: a `migrate/elgg-4.x` branch without gate evidence is `todo`, not `done`.
2. **Finish pre-migration tests** per plugin via `elgg-test-writer` / `elgg-js-test-writer`. Commit on the base branch so tests exist as a baseline in history. Run them against the Elgg 3.x container to confirm they pass on the current version before touching migration code.
3. **Re-run elgg-migrate per plugin** through its full workflow: composer update → AST rules (`--verify --security --audit`) → LLM-guided fixes → syntax check → Docker activation + render → adapt and re-run tests in elgg4 → LLM security review → phpcs → ARCHITECTURE.md + CHANGELOG.md. All 15 gates reported PASS / FAIL / SKIP-WITH-REASON.
4. **Close beads** with `--reason` summarizing what changed. Capture surprises (rule fixes, new breaking changes, recurring hand-fixes, fleet insights) into `rules/3x-to-4x/manifest.json`, `references/breaking-changes.md`, `references/common-mistakes.md`, or `bd remember` **before** the close — no exceptions.
5. **Fleet-wide verification.** Boot elgg4 with every bodyology plugin activated in `mod/.plugin-order.txt` order. Verify homepage renders, login flow works, critical user flows (wall post, upload, message, invite) don't crash. Fleet-wide failures get their own beads issues.

**Exit criterion:** `bd list --status=open | grep "3→4"` is empty, and every bodyology plugin activates in a single elgg4 container that renders the site.

## Step 2 — fleet 4 → 5 → 6 → 7 (build + migrate + document)

Three version-step campaigns, strictly sequential per the fleet Iron Law. For each campaign `{N}→{N+1}`:

1. **Rule-set build-out.** `rules/{N}x-to-{N+1}x/manifest.json` already exists as a skeleton. Harden it against the breaking-changes list. Build test fixtures under `tests/fixtures/{N}x-to-{N+1}x/` (currently empty for 4→5, 5→6, 6→7). Read only the sections of `references/breaking-changes.md` covering N and N+1 — the linear knowledge rule is strict.
2. **Pilot plugin.** Pick a minimal, well-structured plugin with an upstream reference (if one exists) as the first target. Budget 3–5× the expected per-plugin time. The pilot's job is not to ship fast but to reveal which rules are wrong, which breaking changes aren't in the tables yet, and which patterns will recur. Push learnings into the manifest **before** plugin #2.
3. **Fleet roll-out.** Remaining plugins through `elgg-migrate` with the now-tuned rule set. Parallelize Phase 0 pre-flight checks (read-only, independent). Keep Phase 2 Docker verification sequential (shared environment). Reasonable batch size: 3–5 plugins per verification round.
4. **Phase 4 fleet verification** in `elgg{N+1}` container. One activation order, one environment. Fleet-wide issues become new beads issues, never reopening per-plugin ones.
5. **Learning capture.** Each campaign must end with updates to `references/breaking-changes.md`, the `{N}x-to-{N+1}x/manifest.json` LLM instructions, and/or `references/common-mistakes.md`. A campaign is not done until the references are updated.
6. **Advance.** `bd list --status=open | grep "{N}→{N+1}"` must be empty before any `{N+1}→{N+2}` issues are created. Creating them early violates the Iron Law and scrambles `bd ready`.

**Per-campaign exit criterion:** every bodyology plugin activates and renders in the target `elgg{N+1}` container; the relevant references and manifest are updated with at least one pilot-derived learning.

## Step 3 — bodyology site upgrade

Uses the existing `elgg-site-upgrade` skill, one major version at a time, gated on the matching Step 2 campaign being complete. Backup and rollback are owned by that skill and are not re-invented here.

**Sequencing:**

| Site upgrade | Gated on |
|--------------|----------|
| 3.x → 4.x | Step 1 exit criterion PASS |
| 4.x → 5.x | Step 2 campaign 4→5 PASS |
| 5.x → 6.x | Step 2 campaign 5→6 PASS |
| 6.x → 7.x | Step 2 campaign 6→7 PASS |

**Per-upgrade exit criterion:** site boots on the new Elgg version, every plugin in `mod/.plugin-order.txt` activates, homepage + login render, e2e smoke tests pass (wall post, upload, message, invite, notification delivery).

**Final exit criterion:** production bodyology boots on Elgg 7.x.

## Beads graph structure

The current 100-issue graph doesn't match this plan's shape. Rebuild as follows.

**Epics (top-level):**

- `workspace-untangle`
- `fleet-3-to-4`
- `fleet-4-to-5`
- `fleet-5-to-6`
- `fleet-6-to-7`
- `site-upgrade`

**Per-plugin chain** (the `elgg-plugin-fleet` standard shape):

```
pre-migration-tests → 3→4 → 4→5 → 5→6 → 6→7
```

Each edge is a real blocker. Pre-migration tests block the first migration step; each version step blocks the next. Plugins with zero PHP logic (pure views/CSS/JS) may skip the test issue — document the exception in the commit.

**Cross-plugin dependencies.** Discovered from `composer.json` `require`, `manifest.xml` `<requires><type>plugin</type>`, `elgg-plugin.php` `'plugin'.'requires'`, and grep-level checks for `elgg_is_active_plugin()` / cross-namespace `use` / cross-plugin view extensions. Every discovered dependency becomes an explicit beads dep edge. Example: `hypeinbox-3to4` blocks on `hypeprototyper-3to4`.

**Site upgrade issues** block on the matching fleet campaign exit.

**Existing issues.** The ~50 closed ones stay as history. The ~50 open/in_progress ones are updated (not deleted) to match the new title convention `Migrate <plugin> <from>→<to>` and attached to the right epic. Titles that refer to non-canonical plugin names (e.g., `Elgg3-hypeAttachments`) get renamed to the canonical lowercase name from Section "Workspace prerequisite."

**Formula.** `bd mol pour elgg-plugin-fleet --var plugins_dir=... --var from=3.x --var to=7.x` generates the standard-shape chains. Cross-plugin edges and exceptions are wired by hand afterward.

**Sanity checks after creation:**

```bash
bd blocked    # Every migration issue should list its blockers
bd orphans    # Should be empty
```

## Learning loop

Per the fleet skill's forcing function: every migration closure must answer "did I hit anything surprising?" and "would future-me benefit?" — and persist the answer before closing.

| Destination | What belongs there |
|-------------|-------------------|
| `rules/{N}x-to-{N+1}x/manifest.json` | New breaking changes, improved LLM instructions for existing rules |
| `skills/elgg-migrate/references/breaking-changes.md` | Previously undocumented breaking changes |
| `skills/elgg-migrate/references/common-mistakes.md` | Recurring hand-fixes (3+ plugins) |
| `skills/elgg-migrate/references/coding-standards.md` | Style/convention evolution per version |
| `skills/elgg-migrate/SKILL.md` | Workflow-level adjustments (rare) |
| `skills/elgg-site-upgrade/` | Site-upgrade-path troubleshooting |
| `bd remember <key> "<lesson>"` | Fleet-wide insights that don't yet have a home |

When a manual fix appears in 3+ plugins, file a `feature`-type beads issue proposing a new automated rule. Don't block the campaign on it.

## Out of scope

- Per-phase implementation details (those become JIT specs).
- New skills beyond the existing five (`elgg-migrate`, `elgg-plugin-fleet`, `elgg-site-upgrade`, `elgg-test-writer`, `elgg-js-test-writer`).
- Migrations of plugins **not used by bodyology**. `plugins-other/` is deleted during untangle; any hypeJunction plugin not under `bodyology-forum/mod/` today is out of scope until bodyology needs it.
- Third-party plugins with no upstream migration path and no bodyology dependency — tracked as `skip` in the matrix with a reason.
- Elgg 7.x itself if it does not yet exist as a released line at the time Step 2's 6→7 campaign begins. Defer campaign start; surface the block to the human.

## Open questions for implementation planning

These are not blockers for this roadmap, but the implementation plan (writing-plans next) must resolve them:

1. **Canonical name conflicts.** If two plugins lowercase to the same dir name (unlikely in practice but possible), which one wins? Decision needs the actual name list from the untangle scan.
2. **hypeFilestore branch state.** Currently on `master`, not `migrate/elgg-4.x`. Determine whether a 4.x branch exists elsewhere (upstream, another workspace) or whether this plugin has not yet been touched for 4.x.
3. **Plugins with no upstream at all** (private bodyology customizations in `bodyology-forum/mod/` that are not symlinks, e.g., `bodyology_theme`, `bodyology_groups`). They are outside the plugins workspace today. Decision: do they also move to `bodyology/plugins/`, or do they stay as real directories in `mod/`? They do not need the safety fix because they were never symlinks.
