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

## Safety Gates

Each migration step is protected by five gates:

1. **Version Guard** — `VersionGuard` detects the plugin's current version and rejects manifests that don't target it. Prevents version-skipping (e.g., applying 4x→5x rules to a 2.x plugin).
2. **Post-Migration Verifier** — `PostMigrationVerifier` scans the migrated code for APIs that belong to versions beyond the target. Catches the most common consistency failure: agents applying future-version patterns.
3. **Security Sweep** — `SecuritySweep` runs after migration to flag SQL injection, XSS, command injection, hardcoded credentials, weak crypto, and other security issues. Optionally invokes `semgrep` for taint analysis.
4. **Dependency Audit** — `DependencyAudit` runs `composer audit` against the plugin's `composer.lock` to find CVE-rated vulnerabilities in third-party dependencies. Walks up the directory tree to find a parent Elgg installation's lock file if the plugin doesn't have its own.
5. **Docker Verification** — Plugin must activate AND site must render in a real Elgg container before the migration is considered complete.

## Quick Start

```bash
# Clone
git clone <repo-url> && cd elgg-migrate
composer install

# Analyze a plugin (dry run) — version guard runs automatically
php bin/migrate.php rules/3x-to-4x/manifest.json /path/to/my-plugin --dry-run

# Apply automated transformations with full verification + security sweep
php bin/migrate.php rules/3x-to-4x/manifest.json /path/to/my-plugin --verify --security

# See LLM-guided fixes needed
php bin/migrate.php rules/3x-to-4x/manifest.json /path/to/my-plugin --dry-run --report

# Verify-only mode (no file changes)
php bin/migrate.php rules/3x-to-4x/manifest.json /path/to/my-plugin --dry-run --verify --security
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

| Version Step | Auto Rules | LLM Rules | Manifest |
|-------------|:---------:|:---------:|----------|
| 2.x → 3.x | 13 | 17 | `rules/2x-to-3x/manifest.json` |
| 3.x → 4.x | 6 | 22 | `rules/3x-to-4x/manifest.json` |
| 4.x → 5.x | 0 | 21 | `rules/4x-to-5x/manifest.json` |
| 5.x → 6.x | 0 | 13 | `rules/5x-to-6x/manifest.json` |
| 6.x → 7.x | 0 | 22 | `rules/6x-to-7x/manifest.json` |

**Auto rules** run AST-based PHP code transformations (rename functions, rewrite signatures, etc.).
**LLM rules** provide detailed instructions for an AI agent or developer to apply manually.

## AI Agent Skills

This project includes skill definitions for AI coding agents (designed for Claude Code but adaptable):

| Skill | Purpose |
|-------|---------|
| `skills/elgg-migrate/` | Migrate a single plugin one major version at a time |
| `skills/elgg-site-upgrade/` | Upgrade an entire Elgg installation (core + all plugins) |
| `skills/elgg-test-writer/` | Generate PHPUnit test coverage for Elgg plugins |

### Using Skills with Claude Code

The skills are automatically available when working in this repository with Claude Code:

```bash
# Migrate a plugin
claude "migrate ~/plugins/my-plugin from 3.x to 4.x"

# Plan a site upgrade
claude "upgrade my Elgg site from 3.x to 7.x"
```

## Task Tracking with Beads

For structured migration of many plugins, we recommend [beads](https://github.com/beads-dev/beads) (`bd`) for issue tracking. Beads provides local-first issue management that integrates well with AI agent workflows.

### Setup

```bash
# Install beads (see https://github.com/beads-dev/beads)
bd init

# Install the migration formulas
cp formulas/*.json .beads/formulas/
```

### Available Formulas

Pre-built workflow templates in `formulas/`:

| Formula | Purpose |
|---------|---------|
| `elgg-site-upgrade.formula.json` | Full site upgrade with assessment, migration, verification gates |
| `plugin-test-scaffold.formula.json` | Generate test infrastructure for a migrated plugin |

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

The skills and rules work perfectly without beads. Use whatever task tracking you prefer -- the migration rules and skill instructions are self-contained markdown and JSON files.

## Reference Documentation

All migration reference docs live under `skills/elgg-migrate/references/`:

| Document | Content |
|----------|---------|
| `version-api-boundaries.md` | Which APIs belong to which Elgg version (enforced by `--verify`) |
| `plugin-architecture-by-version.md` | Ideal directory structure and file conventions per version |
| `coding-standards.md` | Elgg coding standards by version (PSR-12 + Elgg extensions) |
| `security-review-checklist.md` | Security checks performed by `--security` |
| `llm-security-review.md` | Two-stage security workflow (automated + LLM deep review) |
| `post-migration-documentation.md` | How to document a plugin after migration (ARCHITECTURE.md) |

## Additional References

| Document | Content |
|----------|---------|
| `references/breaking-changes/overview.md` | All breaking changes from 1.x through 7.x |
| `references/breaking-changes/removed-functions.md` | Exhaustive removed function/class/method lists |
| `references/version-matrix.md` | PHP, MySQL, PHPUnit requirements per Elgg version |
| `references/testing/` | E2E and unit testing guides for Elgg |

## Project Structure

```
elgg-migrate/
  rules/
    2x-to-3x/manifest.json    # 30 migration rules
    3x-to-4x/manifest.json    # 28 migration rules
    4x-to-5x/manifest.json    # 21 migration rules
    5x-to-6x/manifest.json    # 13 migration rules
    6x-to-7x/manifest.json    # 22 migration rules
  src/Rules/                   # Automated rule implementations (PHP)
  skills/                      # AI agent skill definitions
  formulas/                    # Beads workflow templates (portable)
  references/                  # Breaking changes, version matrix, testing guides
  docker/                      # Docker environments per Elgg version
  bin/                         # CLI tools
  tests/                       # Rule unit tests
```

## Contributing

### Adding a New Rule

1. Add the rule entry to the appropriate `rules/{from}-to-{to}/manifest.json`
2. For automated rules: implement in `src/Rules/V{From}ToV{To}/YourRule.php`
3. For LLM rules: write detailed `llm_instructions` in the manifest entry
4. Add tests in `tests/`

### Documenting Learnings

When migrating plugins and encountering surprises:

- Add common mistakes to `skills/elgg-migrate/SKILL.md` (Common Mistakes table)
- Add new breaking changes to `references/breaking-changes/overview.md`
- Add removed functions to `references/breaking-changes/removed-functions.md`
- Improve LLM instructions in the relevant `manifest.json` rule

## License

MIT
