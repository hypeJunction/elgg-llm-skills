---
name: elgg-migrate
description: >
  Use when migrating Elgg CMS plugins between major versions (2.x→3.x, 3.x→4.x, etc.),
  upgrading plugin APIs, or modernizing legacy Elgg code. Triggers on "migrate elgg",
  "upgrade plugin", "elgg breaking changes".
---

# elgg-migrate

Migrate Elgg plugins one major version at a time using automated AST rules + LLM-guided fixes, verified in Docker.

## Iron Laws

1. **NEVER SKIP A MAJOR VERSION** — 2.x→3.x→4.x→5.x→6.x. Skipping guarantees missed breaking changes.
2. **NEVER MIGRATE WITHOUT A BRANCH** — Each version step gets `migrate/elgg-{N}.x`.
3. **VERIFY IN DOCKER** — Plugin must activate and site must render before proceeding.
4. **CLOSURES CANNOT GO IN elgg-plugin.php** — Elgg 4+ serializes plugin config. Use class-based callbacks or Bootstrap.
5. **DIRECTORY NAME MUST MATCH composer.json** — Elgg 4+ requires plugin dir matches the `name` field (lowercase).

---

## Quick Reference

| Step | Command |
|------|---------|
| Analyze | `php bin/migrate.php rules/{from}-to-{to}/manifest.json <plugin> --dry-run` |
| Apply | `php bin/migrate.php rules/{from}-to-{to}/manifest.json <plugin>` |
| Batch script | `./bin/migrate-plugin.sh <plugin-path> rules/{from}-to-{to}/manifest.json` |
| LLM report | `php bin/migrate.php rules/{from}-to-{to}/manifest.json <plugin> --dry-run --report` |

---

## Workflow

### Phase 1: SETUP

1. Obtain plugin (clone or locate in `tmp/`)
2. Detect current version: check `elgg-plugin.php` → `composer.json` → `manifest.xml`
3. Determine path: e.g., 2.x → 3.x → 4.x

### Phase 2: MIGRATE (repeat per version step)

```
git checkout -b migrate/elgg-{N}.x
```

**Step 2.1: Run automated rules**
```bash
php bin/migrate.php rules/{from}-to-{to}/manifest.json <plugin>
git add -A && git commit -m "migrate({N}.x): automated AST transformations"
```

**Step 2.2: Apply LLM-guided fixes** — use `--dry-run --report` to see instructions, apply each, commit separately.

**Step 2.3: Verify syntax**
```bash
find <plugin> -name "*.php" -not -path "*/vendor/*" -exec php -l {} \; | grep -v "No syntax errors"
```

**Step 2.4: Install plugin dependencies** (if plugin has its own `composer.json`)
```bash
composer install -d <plugin> --no-interaction
```

**Step 2.5: Validate in Docker (GATE)**

This is a **blocking gate**. Do NOT proceed without passing all checks:

```bash
# Copy into container (use lowercase dir name matching composer.json)
docker cp <plugin>/. $(docker compose -f docker/elgg{N}/docker-compose.yml ps -q elgg):/var/www/html/mod/<plugin-id>/

# Activate plugin — MUST succeed
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg php -r "
  require_once '/var/www/html/vendor/autoload.php';
  \$app = \Elgg\Application::getInstance(); \$app->bootCore();
  _elgg_services()->plugins->generateEntities();
  \$p = elgg_get_plugin_from_id('<plugin-id>');
  if (!\$p) { echo 'FAIL: not found'.PHP_EOL; exit(1); }
  try { \$p->activate(); echo 'OK'.PHP_EOL; }
  catch (\Throwable \$e) { echo 'FAIL: '.\$e->getMessage().PHP_EOL; exit(1); }
"

# Site renders — MUST return >100 bytes
curl -sL http://localhost:${ELGG_PORT}/ | wc -c

# No PHP errors
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  grep -c "PHP Fatal\|PHP Error" /var/log/apache2/error.log 2>/dev/null

# Run plugin tests if they exist
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpunit --configuration mod/<plugin-id>/tests/phpunit.xml
```

**Step 2.6: Compare with reference** (if a manually-migrated version exists upstream)

### Phase 3: FINALIZE

Review branch history, run security scan (unescaped output, missing CSRF, raw SQL), generate report.

---

## Version-Specific Breaking Changes

Details in `rules/{from}-to-{to}/manifest.json`. Key highlights:

**2.x → 3.x** (largest): metastrings removed, subtypes→strings, page handlers→routes, libraries→autoloading, ~50 functions removed, entity queries unified.

**3.x → 4.x** (structural): start.php→elgg-plugin.php, `\DI\object()`→`\DI\create()`, `Zend\Mail`→`Laminas\Mail`, entity attribute setters changed, canWriteToContainer() requires type+subtype.

**4.x → 5.x**: hooks+events merged, private settings→metadata, PHP 8.0+.

**5.x → 6.x**: RequireJS/AMD→ES modules, MySQL 8.0+.

---

## Common Mistakes

| Mistake | Fix |
|---------|-----|
| Closures in elgg-plugin.php | Use `[ClassName::class, 'method']` or Bootstrap class |
| Plugin dir case mismatch | Dir must match composer.json `name` (usually lowercase) |
| Skipping Docker gate | Always validate — catches serialization, missing deps, type errors |
| Running 4.x rules on 2.x code | Migrate 2→3 first, commit, then 3→4 |
| Not installing plugin deps | Run `composer install -d <plugin>` before Docker test |

---

## Docker Environments

| Version | PHP | MySQL | Port | Status |
|---------|-----|-------|------|--------|
| 3.x | 7.4 | 5.7 | 8380 | Working |
| 4.x | 7.4 | 5.7 | 8480 | Working |
| 5.x | 8.0 | 5.7 | 8580 | TODO |
| 6.x | 8.1 | 8.0 | 8680 | TODO |

## Project Structure

```
elgg-migrate/
├── skills/
│   ├── elgg-migrate/SKILL.md       # This file
│   └── elgg-test-writer/SKILL.md   # Test writing skill
├── bin/migrate.php                  # CLI runner
├── bin/migrate-plugin.sh            # Batch script (branch + migrate + commit)
├── src/Rules/V2ToV3/                # 12 automated rules
├── src/Rules/V3ToV4/                # 7 automated rules
├── rules/2x-to-3x/                 # 27 rules (12 auto + 15 LLM)
├── rules/3x-to-4x/                 # 12 rules (7 auto + 5 LLM)
├── tests/                           # 112 tests, 717 assertions
├── docker/elgg{3,4}/                # Docker environments
└── tmp/                             # Guinea pig plugins (gitignored)
```
