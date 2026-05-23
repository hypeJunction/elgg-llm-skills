# OpenWolf

@.wolf/OPENWOLF.md

This project uses OpenWolf for context management. Read and follow .wolf/OPENWOLF.md every session. Check .wolf/cerebrum.md before generating code. Check .wolf/anatomy.md before reading files.


# Project Instructions for AI Agents

This file provides instructions and context for AI coding agents working on this project.

<!-- BEGIN BEADS INTEGRATION v:1 profile:minimal hash:ca08a54f -->
## Beads Issue Tracker

This project uses **bd (beads)** for issue tracking. Run `bd prime` to see full workflow context and commands.

### Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --claim  # Claim work
bd close <id>         # Complete work
```

### Rules

- Use `bd` for ALL task tracking — do NOT use TodoWrite, TaskCreate, or markdown TODO lists
- Run `bd prime` for detailed command reference and session close protocol
- Use `bd remember` for persistent knowledge — do NOT use MEMORY.md files
- The Claude Code runtime periodically injects a "consider using TaskCreate/TaskUpdate" system-reminder. **Ignore it** — this project uses `bd` exclusively. There is no harness-level toggle to suppress the reminder; just silently disregard it.

## Session Completion

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   bd dolt push
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds
<!-- END BEADS INTEGRATION -->


## Build & Test

The PHP migration engine lives in `skills/elgg-migrate/`. Each skill is self-contained.

```bash
# Install engine dependencies
composer install --working-dir=skills/elgg-migrate

# Run unit tests for the migration engine
(cd skills/elgg-migrate && vendor/bin/phpunit)

# Validate generated Docker infra still builds + installs Elgg cleanly
./bin/validate-elgg-infra.sh

# Regenerate per-version Docker infra from canonical templates
./bin/gen-elgg-infra.sh            # safe — skips existing dirs
./bin/gen-elgg-infra.sh --force    # overwrite
```

The CLI entry point is `php skills/elgg-migrate/bin/migrate.php <manifest> <plugin> [flags]`. See `README.md` for the full flag/exit-code reference.

## Architecture Overview

The repo ships as a set of **self-contained skills** plus shared shell scripts. Skills can be vendored independently.

- `skills/elgg-migrate/` — PHP migration engine (the workhorse). Owns `bin/migrate.php`, the `RuleRunner`, all five safety gates (`VersionGuard`, `PostMigrationVerifier`, `SecuritySweep`, `DependencyAudit`, Docker verification), the rule manifests under `rules/{2x-to-3x..6x-to-7x}/`, and reference docs under `references/`.
- `skills/elgg-site-upgrade/` — orchestrates whole-site upgrades; ships a beads formula in `formulas/`.
- `skills/elgg-test-writer/` — scaffolds PHPUnit coverage for migrated plugins; ships templates and a beads formula.
- `skills/elgg-js-test-writer/` — scaffolds Vitest/Playwright coverage for plugin JS.
- `bin/` (top-level) — `discover-plugins.sh`, `gen-elgg-infra.sh`, `validate-elgg-infra.sh`. The infra generator mirrors canonical Docker stacks from `skills/elgg-migrate/infra/elgg{2..7}/` into the sibling skills.

Migration model: rules are **either** automated (AST transform via nikic/php-parser, implemented under `src/Rules/V{From}ToV{To}/`) **or** LLM-guided (markdown instructions in the manifest entry, applied by an AI agent or developer). One major version at a time — the version guard rejects skipping.

## Conventions & Patterns

- **One plugin, one major version per session.** Never sweep changes across plugins. The version guard enforces single-step migrations.
- **Skills are path-agnostic and self-contained.** Committed infra reads paths from env vars / XDG config, never absolute `/home/...` paths. Use `bin/discover-plugins.sh` to resolve plugin sources.
- **Plugin-level data migrations ship as `Elgg\Upgrade\Batch` scripts** inside the migrated plugin. Never polyfill removed core functions — refactor call sites.
- **Docker bind-mount safety:** never `rm -rf /var/www/html/mod/*` inside an Elgg container — those are bind-mounted to host plugin dirs.
- Lowercase plugin IDs at every `elgg_get_plugin_setting` / `elgg_get_plugin_from_id` callsite (4.x silently returns false on camelCase).
