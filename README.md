# elgg-migrate

Automated migration toolkit for upgrading Elgg CMS plugins across major versions (2.x through 7.x).

## What It Does

- **AST-based automated rules** that transform PHP code for breaking API changes
- **LLM-guided migration instructions** for changes that require human/AI judgment
- **Version guard** that detects plugin version and prevents wrong-manifest application
- **Post-migration verification** that catches future-version API leakage (e.g., 5.x APIs in 4.x code)
- **Security sweep** for SQL injection, XSS, command injection, and other common issues
- **Dependency audit** via `composer audit` for CVE-rated vulnerabilities in third-party packages
- **Docker verification environments** for testing plugin activation and site rendering
- **Skills for AI agents** (Claude Code, etc.) with full migration workflows
- **Structured formulas** for tracking multi-plugin, multi-version migrations

## Repository Layout

This repo ships as a set of self-contained **skills** plus a few shared shell scripts. Each skill has its own `bin/`, `src/`, `rules/`, `tests/`, `references/`, and Docker `infra/` so it can be vendored or distributed independently.

```
elgg-migrate/
  bin/                              # Shared scripts (plugin discovery, infra generation/validation)
    discover-plugins.sh
    gen-elgg-infra.sh
    validate-elgg-infra.sh
  skills/
    elgg-migrate/                   # PHP migration engine (the workhorse)
      bin/migrate.php               # CLI entry point
      rules/{2x-to-3x,…,6x-to-7x}/manifest.json
      src/                          # RuleRunner, VersionGuard, PostMigrationVerifier, SecuritySweep, DependencyAudit
      references/                   # Breaking changes, version matrix, security checklist, etc.
      infra/elgg{2..7}/             # Docker stacks for verification
      tests/
    elgg-site-upgrade/              # Whole-site upgrade orchestration
      formulas/elgg-site-upgrade.formula.json
    elgg-test-writer/               # PHPUnit test scaffolding
      formulas/plugin-test-scaffold.formula.json
      templates/
    elgg-js-test-writer/            # JS (Vitest/Playwright) test scaffolding
  AGENTS.md / CLAUDE.md             # Agent instructions
```

## Safety Gates

Each migration step is protected by five gates:

1. **Version Guard** — `VersionGuard` detects the plugin's current version and rejects manifests that don't target it. Prevents version-skipping (e.g., applying 4x→5x rules to a 2.x plugin).
2. **Post-Migration Verifier** — `PostMigrationVerifier` scans the migrated code for APIs that belong to versions beyond the target. Catches the most common consistency failure: agents applying future-version patterns.
3. **Security Sweep** — `SecuritySweep` runs after migration to flag SQL injection, XSS, command injection, hardcoded credentials, weak crypto, and other security issues. Optionally invokes `semgrep` for taint analysis.
4. **Dependency Audit** — `DependencyAudit` runs `composer audit` against the plugin's `composer.lock` to find CVE-rated vulnerabilities in third-party dependencies. Walks up the directory tree to find a parent Elgg installation's lock file if the plugin doesn't have its own.
5. **Docker Verification** — Plugin must activate AND site must render in a real Elgg container before the migration is considered complete.

## Quick Start

```bash
# Clone and install the engine's deps
git clone <repo-url> && cd elgg-migrate
composer install --working-dir=skills/elgg-migrate

# Analyze a plugin (dry run) — version guard runs automatically
php skills/elgg-migrate/bin/migrate.php \
  skills/elgg-migrate/rules/3x-to-4x/manifest.json /path/to/my-plugin --dry-run

# Apply automated transformations with full verification + security sweep
php skills/elgg-migrate/bin/migrate.php \
  skills/elgg-migrate/rules/3x-to-4x/manifest.json /path/to/my-plugin --verify --security

# See LLM-guided fixes needed
php skills/elgg-migrate/bin/migrate.php \
  skills/elgg-migrate/rules/3x-to-4x/manifest.json /path/to/my-plugin --dry-run --report

# Verify-only mode (no file changes)
php skills/elgg-migrate/bin/migrate.php \
  skills/elgg-migrate/rules/3x-to-4x/manifest.json /path/to/my-plugin --dry-run --verify --security
```

### CLI Flags

| Flag | Purpose |
|------|---------|
| `--dry-run` | Analyze only, don't modify files |
| `--report` | Show LLM instructions for manual rules |
| `--verify` | Run post-migration version boundary check |
| `--security` | Run automated security sweep (pattern + optional semgrep) |
| `--audit` | Run `composer audit` for dependency CVEs |
| `--no-guard` | Skip version guard (not recommended) |

### Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | Usage error |
| 2 | Version mismatch (plugin doesn't match manifest "from") |
| 3 | Post-migration verification failed (future-version APIs detected) |
| 4 | Security sweep found critical issues |
| 5 | Dependency audit found critical/high severity CVEs |

## Available Migration Rules

| Version Step | Auto Rules | LLM Rules | Total | Manifest |
|-------------|:---------:|:---------:|:---:|----------|
| 2.x → 3.x | 18 | 20 | 38 | `skills/elgg-migrate/rules/2x-to-3x/manifest.json` |
| 3.x → 4.x | 27 | 7  | 34 | `skills/elgg-migrate/rules/3x-to-4x/manifest.json` |
| 4.x → 5.x | 1  | 21 | 22 | `skills/elgg-migrate/rules/4x-to-5x/manifest.json` |
| 5.x → 6.x | 1  | 13 | 14 | `skills/elgg-migrate/rules/5x-to-6x/manifest.json` |
| 6.x → 7.x | 1  | 22 | 23 | `skills/elgg-migrate/rules/6x-to-7x/manifest.json` |

**Auto rules** run AST-based PHP code transformations (rename functions, rewrite signatures, etc.).
**LLM rules** provide detailed instructions for an AI agent or developer to apply manually.

## AI Agent Skills

This project ships skill definitions for AI coding agents (designed for Claude Code but adaptable):

| Skill | Purpose |
|-------|---------|
| `skills/elgg-migrate/` | Migrate a single plugin one major version at a time |
| `skills/elgg-site-upgrade/` | Upgrade an entire Elgg installation (core + all plugins) |
| `skills/elgg-test-writer/` | Generate PHPUnit test coverage for Elgg plugins |
| `skills/elgg-js-test-writer/` | Generate Vitest / Playwright test coverage for plugin JS |

Each skill is self-contained: it bundles its own CLI, rules, references, and Docker infra, so it can be vendored into a downstream project independently.

### Using Skills with Claude Code

The skills are automatically available when working in this repository with Claude Code:

```bash
# Migrate a plugin
claude "migrate ~/plugins/my-plugin from 3.x to 4.x"

# Plan a site upgrade
claude "upgrade my Elgg site from 3.x to 7.x"
```

## Docker Verification Environments

Each skill bundles a complete Docker stack per Elgg major version under `skills/<skill>/infra/elgg{2..7}/`. The canonical templates live in `skills/elgg-migrate/infra/`; `bin/gen-elgg-infra.sh` mirrors them into the sibling skills.

```bash
# Regenerate all per-version infra bundles from the elgg-migrate templates
./bin/gen-elgg-infra.sh            # safe — skips existing dirs
./bin/gen-elgg-infra.sh --force    # overwrite

# Verify the generated stacks build and Elgg installs cleanly
./bin/validate-elgg-infra.sh
```

## Task Tracking with Beads

For structured migration of many plugins, we recommend [beads](https://github.com/beads-dev/beads) (`bd`) for issue tracking. Beads provides local-first issue management that integrates well with AI agent workflows.

### Setup

```bash
# Install beads (see https://github.com/beads-dev/beads)
bd init

# Install the migration formulas (each lives next to the skill that owns it)
cp skills/elgg-site-upgrade/formulas/*.json   .beads/formulas/
cp skills/elgg-test-writer/formulas/*.json    .beads/formulas/
```

### Available Formulas

Pre-built workflow templates:

| Formula | Location | Purpose |
|---------|----------|---------|
| `elgg-site-upgrade.formula.json`     | `skills/elgg-site-upgrade/formulas/` | Full site upgrade with assessment, migration, verification gates |
| `plugin-test-scaffold.formula.json`  | `skills/elgg-test-writer/formulas/`  | Generate test infrastructure for a migrated plugin |

```bash
# Pour a formula to create structured issues
bd mol pour elgg-site-upgrade \
  --var project=/path/to/site \
  --var from=3.x --var to=4.x --var port=8480

# Track progress
bd ready        # Find available work
bd stats        # Project health overview
```

### Without Beads

The skills and rules work perfectly without beads. Use whatever task tracking you prefer — the migration rules and skill instructions are self-contained markdown and JSON files.

## Reference Documentation

All migration reference docs live under `skills/elgg-migrate/references/`:

| Document | Content |
|----------|---------|
| `version-api-boundaries.md` | Which APIs belong to which Elgg version (enforced by `--verify`) |
| `version-matrix.md` | PHP, MySQL, PHPUnit requirements per Elgg version |
| `breaking-changes.md` + `breaking-changes/` | Breaking changes overview and per-step detail |
| `plugin-architecture-by-version.md` | Ideal directory structure and file conventions per version |
| `coding-standards.md` | Elgg coding standards by version (PSR-12 + Elgg extensions) |
| `security-review-checklist.md` | Security checks performed by `--security` |
| `llm-security-review.md` | Two-stage security workflow (automated + LLM deep review) |
| `dependency-audit.md` / `dependabot-alerts.md` | Dependency CVE workflow |
| `post-migration-documentation.md` | How to document a plugin after migration (ARCHITECTURE.md) |
| `agent-failure-modes.md` / `common-mistakes.md` | Pitfalls observed in real migrations |
| `git-hygiene.md` | Branching and commit conventions for migration work |
| `testing/` and `ci/` | E2E, unit testing, and CI integration guides |

## Contributing

### Adding a New Rule

1. Add the rule entry to the appropriate `skills/elgg-migrate/rules/{from}-to-{to}/manifest.json`
2. For automated rules: implement in `skills/elgg-migrate/src/Rules/V{From}ToV{To}/YourRule.php`
3. For LLM rules: write detailed `llm_instructions` in the manifest entry
4. Add tests in `skills/elgg-migrate/tests/`

### Documenting Learnings

When migrating plugins and encountering surprises:

- Add common mistakes to `skills/elgg-migrate/references/common-mistakes.md`
- Add new breaking changes to `skills/elgg-migrate/references/breaking-changes.md` (and the per-step files under `breaking-changes/`)
- Improve LLM instructions in the relevant `manifest.json` rule

## License

MIT
