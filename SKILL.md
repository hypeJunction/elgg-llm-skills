---
name: elgg-migrate
description: >
  Use when migrating Elgg CMS plugins from legacy versions (1.x-5.x) to modern
  Elgg (up to 6.x). Handles one major version at a time with automated scripts,
  security auditing, Docker-based verification, and test coverage. Triggers on
  Elgg plugin migration, upgrade, or modernization tasks.
category: process
triggers:
  - elgg migration
  - elgg upgrade
  - elgg plugin migration
  - migrate elgg
  - upgrade elgg plugin
user-invocable: true
---

# elgg-migrate

> **Purpose:** Migrate Elgg plugins from legacy versions to modern Elgg, one major version at a time.
> **Phases:** Scan → Test Baseline → Security Audit → Migrate (per version) → Finalize
> **Usage:** `/elgg-migrate <plugin-repo-url-or-path> [--target=6.x] [--dry-run]`

## Iron Laws

1. **NEVER SKIP A MAJOR VERSION** — Migrate one major version at a time (2.x→3.x, then 3.x→4.x, etc.). Skipping versions guarantees missed breaking changes.
2. **NEVER MIGRATE WITHOUT PASSING TESTS** — If tests don't pass on the current version, fix them first.
3. **NEVER APPLY CHANGES WITHOUT VERIFICATION** — Every migration step must be verified (tests + Docker when available).
4. **EVERY VERSION STEP GETS ITS OWN BRANCH** — Branch per step: `migrate/elgg-3.x`, `migrate/elgg-4.x`, etc.
5. **SECURITY ISSUES BLOCK MIGRATION** — Critical vulnerabilities must be fixed before proceeding.

## Scope Flags

| Flag | Description |
|------|-------------|
| `--target=N.x` | Target Elgg version (default: 6.x) |
| `--dry-run` | Analyze only, don't apply changes |
| `--skip-docker` | Skip Docker verification (faster, less safe) |
| `--skip-security` | Skip security audit phase |

---

## Phase 1: SCAN AND DOCUMENT

**Mode:** Read-only

### Step 1.1: Obtain Plugin

If given a URL, clone it. If given a path, use it directly.

```bash
git clone <plugin-repo-url> tmp/<plugin-name>
cd tmp/<plugin-name>
git checkout -b migrate/baseline
```

### Step 1.2: Detect Current Elgg Version

Check in order:
1. `elgg-plugin.php` — look for `requires.elgg` version constraint
2. `composer.json` — look for `elgg/elgg` in `require`
3. `manifest.xml` — look for `<requires>` with `elgg_release`

### Step 1.3: Run Automated Analysis

Load the manifest for the first version step from `rules/{from}-to-{to}/manifest.json`.
Run all automated rules' `analyze()` methods:

```bash
php -r '
require "vendor/autoload.php";
$runner = new \ElggMigrate\RuleRunner();
$analyses = $runner->analyzeAll("rules/2x-to-3x/manifest.json", "tmp/<plugin>");
foreach ($analyses as $a) {
    echo "{$a->ruleId}: {$a->summary}\n";
    foreach ($a->findings as $f) echo "  {$f->file}:{$f->line} — {$f->description}\n";
}
'
```

### Step 1.4: Collect LLM Instructions for Non-Automated Rules

```bash
php -r '
require "vendor/autoload.php";
$runner = new \ElggMigrate\RuleRunner();
$instructions = $runner->getLlmInstructions("rules/2x-to-3x/manifest.json");
foreach ($instructions as $i) echo "## {$i["name"]}\n{$i["instructions"]}\n\n";
'
```

### Step 1.5: Feature Inventory

Scan all PHP/JS/CSS files and document:
- Every hook/event registration
- Every action registration
- Every page handler / route
- Every entity type and subtype
- Every view file
- Every widget
- Every menu registration
- Every library registration
- Every JavaScript module
- Every language file

Output: Write `MIGRATION_INVENTORY.md` in the plugin root.

---

**GATE: Present analysis report and inventory to user. Wait for approval.**

---

## Phase 2: TEST BASELINE

**Mode:** Write

### Step 2.1: Set Up Docker (if not --skip-docker)

Use the appropriate template from `docker/elgg{N}/` matching the current version.
See `references/version-matrix.md` for PHP/MySQL requirements.

### Step 2.2: Write Tests

Create `tests/` following Elgg PHPUnit conventions:
- **Unit tests** for business logic in classes
- **Integration tests** for hooks, actions, entity CRUD
- **Functional tests** for routes and views

Every item in the feature inventory should have at least one test.

### Step 2.3: Verify Tests Pass

```bash
# Local (if possible):
vendor/bin/phpunit --configuration tmp/<plugin>/phpunit.xml

# Or in Docker:
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin>/phpunit.xml
```

---

**GATE: ALL tests must pass on the current Elgg version.**

---

## Phase 3: SECURITY AUDIT

**Mode:** Read-only analysis, write to fix

Check for (see `references/security-checklist.md` when available):
- **SQL Injection**: Raw SQL with string concatenation, user input in queries
- **XSS**: Unescaped output in views (`echo $vars['x']` without escaping)
- **CSRF**: Actions without token validation
- **File upload**: Unrestricted types, path traversal
- **Auth**: Missing `gatekeeper()`, missing `canEdit()` checks
- **Access control**: Hard-coded access levels

Rate findings: CRITICAL / HIGH / MEDIUM / LOW.
Fix CRITICAL and HIGH before proceeding.

---

**GATE: No CRITICAL issues remain. HIGH issues fixed or explicitly accepted.**

---

## Phase 4: MIGRATE (repeat per version step)

**Mode:** Full write access

### Step 4.1: Create Branch

```bash
git checkout -b migrate/elgg-{N}.x
```

### Step 4.2: Run Automated Rules

```bash
php -r '
require "vendor/autoload.php";
$runner = new \ElggMigrate\RuleRunner();
$results = $runner->applyAll("rules/{prev}x-to-{N}x/manifest.json", "tmp/<plugin>");
foreach ($results as $r) {
    echo $r->ruleId . ": " . ($r->success ? "OK" : "FAILED") . "\n";
    foreach ($r->changes as $c) echo "  [{$c->type}] {$c->file}: {$c->description}\n";
    foreach ($r->warnings as $w) echo "  [WARN] $w\n";
}
'
```

### Step 4.3: Apply LLM-Guided Refactoring

For each non-automated rule in the manifest, follow the `llm_instructions` field.
Work through them in priority order. For each:
1. Search the codebase for the pattern described
2. Apply the transformation
3. Verify PHP syntax: `php -l <file>`
4. Commit: `git commit -m "migrate({N}.x): <rule description>"`

### Step 4.4: Update Docker (if not --skip-docker)

Switch to the new version's Docker config and verify the plugin loads.

### Step 4.5: Run Tests and Fix Failures

Run the test suite. For each failure:
1. Determine if it's a migration issue or a test issue
2. Fix accordingly
3. Re-run until green

### Step 4.6: Security Re-check

Quick scan for regressions introduced during migration.

---

**GATE: All tests pass, no critical security issues. User approves before next version step.**

---

## Phase 5: VERIFY AND FINALIZE

**Mode:** Read-only verification, then write

### Step 5.1: Full Test Suite on Final Version

Run complete tests on the target Elgg version.

### Step 5.2: Generate Migration Report

Produce `MIGRATION_REPORT.md` with:
- Version steps completed
- Files changed per step
- Deprecated APIs replaced
- Security issues found and resolved
- Test coverage summary
- Remaining manual steps (if any)

### Step 5.3: Update Plugin Metadata

- Update `composer.json` Elgg version constraint
- Ensure `elgg-plugin.php` has correct metadata (if migrated to 4.x+)
- Update README with new compatibility info

---

**GATE: User approves final state.**

---

## Quick Reference

| Phase | Mode | Gate |
|-------|------|------|
| 1. Scan | Read-only | User reviews analysis |
| 2. Test Baseline | Write | Tests pass on current version |
| 3. Security Audit | Read → Write | No critical issues |
| 4. Migrate (×N) | Write | Tests pass on new version |
| 5. Finalize | Write | User approves |

## Project Structure

```
elgg-migrate/
├── SKILL.md              # This file
├── src/                  # Migration rule implementations
├── rules/                # Version manifests (JSON) + rule classes
│   ├── 2x-to-3x/
│   ├── 3x-to-4x/
│   ├── 4x-to-5x/
│   └── 5x-to-6x/
├── tests/                # Tests for migration rules
├── references/           # Breaking change docs, version matrix
├── docker/               # Docker environments per Elgg version
└── tmp/                  # Guinea pig plugins (gitignored)
```

## References

- [Version Matrix](references/version-matrix.md)
- [Breaking Changes Overview](references/breaking-changes/overview.md)
- [Removed Functions](references/breaking-changes/removed-functions.md)
