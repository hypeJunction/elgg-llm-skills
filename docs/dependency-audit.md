# Dependency Audit (CVE Scanning)

The `--audit` flag runs `composer audit` against the plugin's `composer.lock` to find CVE-rated vulnerabilities in third-party dependencies. This is the dependency-CVE leg of the security review, complementing the pattern-based `--security` sweep and the optional LLM-based deep review.

## What It Does

`DependencyAudit` (`src/DependencyAudit.php`) wraps `composer audit --format=json` and parses the output into structured `Advisory` objects.

For each advisory, it captures:
- Package name
- CVE identifier (e.g., `CVE-2023-12345`)
- Severity (`critical`, `high`, `medium`, `low`, `unknown`)
- Title and description
- Affected version range
- Link to the advisory
- Date reported

It also reports abandoned packages (packages marked as no-longer-maintained on Packagist).

## When It Runs

The audit runs automatically when `--audit` is passed. It's read-only and works in `--dry-run` mode.

```bash
# All four gates: version, verify, security, audit
docker compose run --rm migrate bin/migrate.php \
  rules/3x-to-4x/manifest.json /plugins/myplugin \
  --verify --security --audit

# Audit-only
docker compose run --rm migrate bin/migrate.php \
  rules/3x-to-4x/manifest.json /plugins/myplugin \
  --dry-run --audit
```

## Lock File Resolution

`DependencyAudit::findLockFile()` looks for `composer.lock` in this order:

1. **Plugin's own `composer.lock`** — if the plugin has its own composer dependencies
2. **Parent directory walk** — walks up to 4 parent directories looking for a parent installation's `composer.lock` (e.g., the Elgg root that contains the plugin under `mod/`)
3. **No lock file** — returns a clean result with a note that the audit was skipped

This means the audit works for both standalone plugins and plugins running inside an Elgg installation (where the dependencies live in the parent's lock file).

## Severity Mapping

| Composer severity | Gate result | Exit code |
|-------------------|-------------|-----------|
| critical | **fails the gate** | 5 |
| high | **fails the gate** | 5 |
| medium | warning, doesn't fail | 0 |
| low | warning, doesn't fail | 0 |
| unknown | warning, doesn't fail | 0 |

Critical and high CVEs block the migration. Medium and low advisories are reported but don't fail — they need human triage to decide if they're applicable to the plugin's usage.

## Failure Modes

The audit handles several failure modes gracefully without blocking migration:

| Scenario | Behavior |
|----------|----------|
| No `composer.lock` found | Skipped with informational message, gate passes |
| `composer.lock` exists but no packages | "No packages locked — nothing to audit", gate passes |
| `composer audit` not installed | Reports failure in summary, gate passes |
| Network failure (Packagist unreachable) | Reports failure, gate passes |
| Composer config error (e.g., blocked plugin) | Reports raw error, gate passes |

The principle: dependency auditing is **additive**. A failed audit shouldn't block a migration that would otherwise succeed. The user gets clear messaging about what couldn't be checked, and can address the audit issue separately.

## Comparison with Other Tools

| Tool | Approach | Use Case |
|------|----------|----------|
| **`composer audit`** (this gate) | Local scan against Packagist advisories DB | Runs in CI, no auth needed, fast |
| **roave/security-advisories** | Composer metapackage, prevents installing vulnerable versions | Defensive, runs at install time |
| **GitHub Dependabot** | CVE-based PR generation | Continuous, requires GitHub |
| **Snyk** | Commercial, broader DB | More noise, more coverage |
| **OWASP Dependency-Check** | NVD CVE feed | Cross-language, heavier |

We chose `composer audit` because:
1. It ships with Composer 2.4+ (no extra install)
2. It uses the same Packagist advisories DB that `roave/security-advisories` uses
3. It runs offline-friendly (only hits Packagist for the advisories DB)
4. It returns structured JSON we can parse reliably
5. No auth tokens or accounts needed

## Pattern + Dependencies Coverage

The two security gates cover complementary attack surfaces:

| Surface | Tool | Catches |
|---------|------|---------|
| Plugin source code | `--security` (`SecuritySweep`) | SQL injection, XSS, eval, unserialize, hardcoded secrets, command injection in YOUR code |
| Plugin source code (deeper) | `SemgrepRunner` (auto-invoked when semgrep is on PATH) | Taint analysis: data flow from source to sink |
| Plugin source code (deepest) | `/security-review` skill | LLM-based business logic, IDOR, missing authz |
| Third-party dependencies | `--audit` (`DependencyAudit`) | CVEs in composer packages |

A plugin can pass the `--security` sweep but still ship a critical vulnerability through a vulnerable Symfony/Doctrine/Guzzle dependency. The audit catches that.

## Example Output

```
--- DEPENDENCY AUDIT (composer audit) ---

Source: /plugins/myplugin/composer.lock

✗ critical  [symfony/http-foundation] CVE-2023-46734
  CSRF token fixation in HttpFoundation
  Affected: <5.4.30
  Link: https://github.com/symfony/symfony/security/advisories/GHSA-...

⚠ medium  [doctrine/dbal] CVE-2024-12345
  SQL injection via crafted column name
  Affected: <3.7.2
  Link: https://...

Abandoned packages:
  ⚠ hypejunction/elgg_tokeninput

2 advisory(ies) (1 critical, 1 medium), 1 abandoned package(s)
```

Exit code: `5` (critical/high CVE found)

## Real-World Validation

During the initial validation against 14 high-priority hypeJunction plugins:
- **3 plugins** flagged abandoned `hypejunction/elgg_tokeninput` dependency (filed as `elgg-migrate-rhwp`)
- **1 plugin** (hypeGeo) had a composer config issue blocking the audit (filed as `elgg-migrate-3t4t`)
- **No critical/high CVEs** found in the audited plugins
- Several plugins had no `composer.lock` (skipped cleanly)

The abandoned package detection is the most actionable finding from this batch — it identifies dependencies that need replacement before the migration is complete.
