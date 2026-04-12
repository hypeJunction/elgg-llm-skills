# elgg-migrate Documentation

Canonical documentation for the Elgg migration toolkit. The agent and developers should consult these docs at the appropriate phase of the migration workflow.

## Document Index

### Migration Safety

- **[version-api-boundaries.md](version-api-boundaries.md)** — Which APIs belong to which Elgg version. Enforced automatically by `PostMigrationVerifier` via the `--verify` flag.

- **[plugin-architecture-by-version.md](plugin-architecture-by-version.md)** — Canonical directory structure and file conventions for each Elgg version (2.x through 6.x). The migrated plugin MUST conform to the structure for its target version.

### Code Quality

- **[coding-standards.md](coding-standards.md)** — Elgg coding standards by version. PSR-12 base with Elgg-specific extensions. Includes PHPCS configuration, naming conventions, and version-specific syntax requirements.

### Security

- **[security-review-checklist.md](security-review-checklist.md)** — Pattern-based checks performed by `SecuritySweep` (the `--security` flag). Lists critical patterns, contextual patterns, and Elgg-specific checks.

- **[llm-security-review.md](llm-security-review.md)** — Two-stage security workflow combining automated pattern matching with LLM deep analysis via the `/security-review` skill.

- **[dependency-audit.md](dependency-audit.md)** — `composer audit` integration for CVE-rated vulnerabilities in third-party dependencies (the `--audit` flag).

### Documentation

- **[post-migration-documentation.md](post-migration-documentation.md)** — How to generate `ARCHITECTURE.md` for a plugin after migration. Required after every version step.

## When to Consult Each Document

| Migration Phase | Document |
|-----------------|----------|
| Phase 1 (SETUP) | `plugin-architecture-by-version.md` — confirm current version structure |
| Phase 2.1 (Apply rules) | `version-api-boundaries.md` — understand what APIs to use/avoid |
| Phase 2.5 (Validate) | `security-review-checklist.md` — review automated findings |
| Phase 2.8 (Document) | `post-migration-documentation.md` — generate ARCHITECTURE.md |
| Phase 2.9 (Style) | `coding-standards.md` — apply PSR-12 + Elgg conventions |
| Phase 2.5 (Validate) | `dependency-audit.md` — review `--audit` findings (CVEs, abandoned packages) |
| Phase 2.10 (Deep security) | `llm-security-review.md` — run `/security-review` skill |

## How These Docs Relate to the Code

| Doc | Implementation |
|-----|---------------|
| `version-api-boundaries.md` | `src/PostMigrationVerifier.php` (`VERSION_BOUNDARIES` const) |
| `plugin-architecture-by-version.md` | `src/VersionGuard.php` (version detection logic) |
| `security-review-checklist.md` | `src/SecuritySweep.php` (`CRITICAL_PATTERNS`, `CONTEXTUAL_PATTERNS`, `ELGG_PATTERNS` consts) |
| `dependency-audit.md` | `src/DependencyAudit.php` (lock file resolution + composer audit JSON parsing) |

When updating a doc, ensure the corresponding code is updated (and vice versa).
