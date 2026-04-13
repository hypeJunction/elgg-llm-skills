---
name: elgg-site-upgrade
description: >
  Use when upgrading an entire Elgg installation (core + all plugins) between
  major versions, or running a production upgrade with backup and rollback.
---

# elgg-site-upgrade

> **Purpose:** Upgrade an entire Elgg installation from one major version to the next.
> **Two workflows:** PREPARE (dev) and EXECUTE (production)
> **Usage:** `/elgg-site-upgrade <project-path> [--from=2.x] [--to=7.x] [--mode=prepare|execute]`

## Iron Laws (strict — do not improvise)

These are the three rules that make site upgrades recoverable. Violate any
one and a bad upgrade stops being a rollback and becomes a restoration.

1. **ONE MAJOR VERSION AT A TIME.** Upgrade 2.x→3.x, then 3.x→4.x, and so on.
   Skipping versions means skipping the upgrade scripts that migrate schema
   and data — the site will appear to work and then fail weeks later when
   something touches an un-migrated table.
2. **LATEST MINOR BEFORE JUMPING MAJOR.** From 2.3.x you can start on 3.x;
   from 2.0.x you cannot. Minor releases contain the compatibility shims
   that make the major jump safe.
3. **PREPARE COMPLETELY BEFORE EXECUTING.** Part A (prepare) must produce a
   fully tested migration branch before Part B (execute) touches production.
   Production is not where you discover problems — it's where you apply
   solutions you already verified work.

Everything else in Part A is judgment and should be adapted to your project.
Part B is a safety-critical checklist and stays strict.

Four sections in `elgg-migrate` apply to site upgrades too — read them
before starting Part A: **Cost of failure**, **When to stop and escalate**,
**Agent failure modes**, and **Recovery playbook**. They cover the
cross-cutting guidance about which gates to never cut, when to surface a
block to the human instead of forging ahead, the invisible mistakes the
acceptance gates can't catch, and how to recover from the common failure
shapes.

---

## Skill layout (self-contained)

This skill ships the full Docker infra, the orchestrator CLI, AND the
entire AST migration engine. After `npx skills add`, the installed
directory looks like:

    <skill-dir>/
      SKILL.md                 # this file
      bin/elgg-migrate-run     # per-plugin isolated orchestrator CLI
      bin/migrate.php          # AST migration engine CLI
      bin/migrate-plugin.sh    # one-shot per-plugin wrapper
      src/                     # ElggMigrate\ PHP namespace
      rules/{2..6}x-to-{3..7}x/ # per-version rule manifests
      composer.json            # nikic/php-parser dep + PSR-4 autoload
      phpunit.xml              # test runner config
      tests/                   # PHPUnit tests for src/
      formulas/                # site-upgrade beads formula
      infra/elgg{N}/           # per-target Elgg stack (N = 2..7)
      infra/migrate/           # AST engine Docker stack

Resolve `$SKILL` once at session start as the absolute path of the
directory containing this SKILL.md, and `$SKILL_INFRA` as `$SKILL/infra`.
Every `$SKILL_INFRA/elgg{N}/...` and `$SKILL_INFRA/migrate/...` path
below is literal. Prefer the bundled `bin/elgg-migrate-run` CLI for
spawning isolated per-plugin environments; it already knows how to
locate `$SKILL_INFRA` and writes job state under
`$XDG_STATE_HOME/elgg-migrate/`. The engine files (src/, rules/, bin/,
composer.json, infra/migrate/) are kept in sync with the canonical
copy in the elgg-migrate skill by `bin/gen-elgg-infra.sh`.

## Container Infrastructure

All operations run inside Docker containers — nothing executes on the host machine.

| Service | Purpose | Location |
|---------|---------|----------|
| `migrate` | AST migration rules (PHP 8.1 + php-parser) | Root `docker-compose.yml` |
| `elgg` | Plugin activation, PHPUnit, Elgg bootstrap, composer | `$SKILL_INFRA/elgg{N}/docker-compose.yml` |
| `node` | Playwright and Vitest tests | `$SKILL_INFRA/elgg{N}/docker-compose.yml` (profile: test) |
| `db` | MySQL database | `$SKILL_INFRA/elgg{N}/docker-compose.yml` |

### Debugging inside containers

```bash
# PHP/Apache error log
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg tail -f /var/log/apache2/error.log

# Elgg container logs (startup, plugin activation)
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml logs elgg

# Interactive shell in Elgg container
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec elgg bash

# MySQL interactive query
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec db mysql -uelgg -pelgg elgg

# Container status and health
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml ps

# Rebuild after changes
docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml build --no-cache
```

---

# PART A: PREPARE (development workflow)

Part A is iterative and safe to break. It runs in a development environment
with Docker and a fresh database. The goal is to produce a tested migration
branch for each version step that can be applied to production with
confidence.

Think of Part A as three nested loops: an outer loop over version steps, a
middle loop over plugins within a step, and an inner loop of fix-and-retry
until the gates pass. The phases below describe the shape of that work, but
the order is a guide — a plugin you've already tested on a newer fork may
skip most of the inner loop, while a custom plugin with no upstream will
need the full treatment.

## Assess: know what you're upgrading before you touch anything

Three things must be clear before preparation work starts:

**Where the site is now.** `grep "elgg/elgg" <project>/composer.json` for the
installed core version. Don't trust `manifest.xml` unless composer is absent.

**Where the site is going.** The upgrade path follows Iron Laws 1 and 2:

| From | Target | Steps |
|------|--------|-------|
| 2.x | 7.x | 2.3→3.3→4.3→5.1→6.1→7.0 |
| 3.x | 7.x | 3.3→4.3→5.1→6.1→7.0 |
| 4.x | 7.x | 4.3→5.1→6.1→7.0 |
| 5.x | 7.x | 5.1→6.1→7.0 |
| 6.x | 7.x | 6.1→7.0 |

**What plugins need attention.** Inventory the `mod/` directory and put each
plugin into one of three buckets, because the strategy differs per bucket:

| Bucket | How to recognize | Strategy |
|--------|------------------|----------|
| Core (ships with Elgg) | Lives under `vendor/elgg/elgg/mod` or equivalent | Upgrades with core; nothing to do |
| Composer-managed with upstream | Listed in `composer.json` `require`, has a GitHub repo | Find an upgraded version or migrate via `elgg-migrate` |
| Custom/private | Only in `mod/`, no upstream repo | Migrate in-place in the project repo |

A reference bash loop for the inventory:

```bash
for d in <project>/mod/*/; do
  name=$(basename "$d")
  if [ -f "$d/manifest.xml" ]; then
    ver=$(grep -A1 'elgg_release' "$d/manifest.xml" | grep version | grep -oP '>[^<]+<' | tr -d '><')
    echo "$name: Elgg $ver"
  elif [ -f "$d/elgg-plugin.php" ]; then
    echo "$name: elgg-plugin.php (3.x+)"
  fi
done | sort
```

### Check for upgraded plugin versions (highest-leverage step)

Before migrating any plugin, check whether someone has already done it.
Duplicate migration wastes time and can regress over a known-good upgrade.
The checks mirror those in `elgg-migrate` Phase 1 — local branches
(`git branch -a`), upstream branches and forks (`gh api
repos/<owner>/<plugin>/branches`, `forks`), Packagist (`composer show ...
--all` inside the Elgg container), the Elgg plugin directory at
https://elgg.org/plugins, and version-prefixed org repos (`gh search repos
--owner <org> "Elgg4-<plugin>"`).

When any of these turn up a usable migration:

- **Already at target version** → skip migration for this plugin entirely
- **Upgraded on Packagist** → `composer require` the new version
- **Upstream fork has a working migration** → adopt it only after
  trust-but-verify (see `elgg-plugin-fleet`'s "Trust but verify" section
  for the checks — abandoned branches, wrong Elgg target, unrelated
  feature work mixed in, licensing, Docker activation)
- **Migration branch exists but incomplete** → continue from it
- **Nothing exists anywhere** → migrate via `elgg-migrate`

Reading the code is often faster than running checks. Version indicators
that are reliable:

| Indicator | Likely Version |
|-----------|---------------|
| `start.php` with init handler, no `elgg-plugin.php` | 2.x |
| Both `start.php` and `elgg-plugin.php` | 3.x (transitional) |
| `elgg-plugin.php` with `'hooks'` key, no `start.php` | 4.x |
| `elgg-plugin.php` with `'events'` key only | 5.x+ |
| `\Elgg\Hook` type hints | 4.x |
| `\Elgg\Event` type hints | 5.x+ |
| `elgg_define_js()` / `elgg_require_js()` | ≤5.x |
| `elgg_register_esm()` / `elgg_import_esm()` | 6.x+ |
| AMD `define()/require()` in JS | ≤5.x |
| ES module `import/export` in JS | 6.x+ |

---

## Set up the workspace

The workflow assumes symlinks from the project's `mod/` directory into a
separate plugins workspace — changes in the workspace are instantly
reflected in the project and you can work on each plugin as its own git
repo. See the "symlink workflow" memory for the full rationale.

```bash
mkdir -p ~/plugins-workspace
git clone https://github.com/<owner>/<plugin>.git ~/plugins-workspace/<plugin>

cd <project>/mod
rm -rf <plugin>
ln -s ~/plugins-workspace/<plugin> <plugin>
```

Create a migration branch in both the project and each plugin workspace
(name is the TARGET version, matching `elgg-migrate`'s convention):

```bash
git -C <project> checkout -b migrate/elgg-{N}.x
git -C ~/plugins-workspace/<plugin> checkout -b migrate/elgg-{N}.x
```

**Record the current plugin activation order.** Save it to
`mod/.plugin-order.txt` (one plugin id per line, in priority order). The
verification step reads this to reproduce production's activation sequence.

**Before you commit anything, read
`elgg-migrate/references/git-hygiene.md`.** Site upgrades generate a lot
of local state (`vendor/`, composer-installed plugins under `mod/`,
`dataroot/`, docker override files with host paths, simplecache, logs)
and every one of them has landed in git histories before. That reference
ships a paste-ready `.gitignore` for Elgg sites — including the
blocklist-then-allowlist `mod/*` pattern that keeps composer-installed
plugins out of history — and a pre-commit grep you should run before
every commit during the upgrade.

## Establish the test baseline

Boot Docker for the CURRENT version, then make sure there's a green test
suite before touching any migration code. This is the same gate as
the `elgg-migrate` pre-migration test gate, applied at project scope — without a baseline
you cannot tell whether the upgrade broke anything.

For each plugin without existing tests, use `/elgg-test-writer` to write
entity CRUD, registration (actions, routes, hooks, widgets), permission,
and view-rendering coverage. Run the full suite against the current version;
everything must pass before proceeding. Record the passing count — you'll
compare against it after the upgrade.

## Migrate plugins for one version step

For each version step in the upgrade path, run a middle loop over plugins.
The per-plugin work follows `elgg-migrate` — the fleet context just means
you're doing it many times rather than once.

For plugins with an upstream repo, migrate in the workspace and commit there.
For custom plugins, migrate in-place in the project repo. Run the automated
AST rules, apply LLM-guided fixes from `--report`, and commit each logical
group separately. When a manually-migrated reference version exists
upstream, `diff --stat` against it to catch judgment calls the rules missed.

Don't batch commits across plugins — keep each plugin's work in its own
commits so a reviewer (and your future self) can follow what changed.

## Verify in Docker for the target version

Boot the target version's Docker environment and run the gates. These are
safety gates — they must pass before advancing to the next version step.

**All plugins activate without fatal errors.** Reproduce production's
activation order using the `.plugin-order.txt` file:

```bash
docker compose exec elgg php -r "
require 'vendor/autoload.php';
\$app = \Elgg\Application::getInstance();
\$app->bootCore();
_elgg_services()->plugins->generateEntities();
\$order = file('/var/www/html/mod/.plugin-order.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
\$failed = [];
foreach (\$order as \$id) {
    \$id = trim(\$id);
    \$plugin = elgg_get_plugin_from_id(\$id);
    if (!\$plugin || \$plugin->isActive()) continue;
    try { \$plugin->activate(); }
    catch (\Throwable \$e) { \$failed[] = \$id . ': ' . \$e->getMessage(); }
}
if (empty(\$failed)) { echo 'All plugins activated.' . PHP_EOL; }
else { foreach (\$failed as \$f) echo 'FAIL: ' . \$f . PHP_EOL; }
"
```

**Site renders.** `curl -sL http://localhost/ | grep -oP '<title>[^<]*</title>'`
must return a real title, not "Fatal Error".

**Simplecache CSS is non-empty.** css-crush v2.4 silently fails on certain
CSS patterns, leaving the stylesheet empty. The site "works" but looks
broken. Always verify:

```bash
TS=$(docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec -T elgg \
  curl -sL http://localhost/ | grep -oP 'cache/\K\d+' | head -1)
SIZE=$(docker compose -f $SKILL_INFRA/elgg{N}/docker-compose.yml exec -T elgg \
  curl -sL -o /dev/null -w "%{size_download}" "http://localhost/cache/${TS}/default/elgg.css")
echo "elgg.css: ${SIZE} bytes"
# If <1000, a plugin CSS view is breaking css-crush. See references/REFERENCE.md §18.
```

**Tests pass against the target version.** Run PHPUnit and Playwright; the
passing count must match the baseline from Part A's test phase. See
`references/testing/elgg-e2e-testing.md` for setup details and known
pitfalls (hypeWall interception, foreach-by-reference crashes, OPcache
stale code).

When any gate fails, fix it in the workspace, commit the fix, and re-run
the failing gate. Don't mask failures by commenting tests out.

When everything for this step is green, loop back and run the next version
step. Iron Law 1 forbids advancing to N+2 until N+1 is fully done.

## Harden PHP dependencies

Once the site is on the latest Elgg version, upgrade PHP dependencies one
at a time. Doing this per-package (rather than `composer update`) is the
only way to isolate which bump broke what — and it will break things.

The principle: risk-order the upgrades and test after every single one.

A reasonable risk order, lowest to highest:

1. Dev dependencies (phpunit, code sniffers, faker)
2. Utility libraries (monolog, symfony/var-dumper)
3. Mail/HTTP (laminas/laminas-mail, guzzlehttp/guzzle)
4. Image processing (imagine/imagine)
5. Template/view (michelf/php-markdown, css-crush)
6. Database (doctrine/dbal) — **high risk**, test thoroughly
7. Framework (symfony/*, php-di/php-di) — **high risk**
8. Elgg patch updates (elgg/elgg within same major)

The per-package loop: check what will change with `composer update <pkg>
--dry-run`, read the package changelog, apply the update, run the full test
suite (PHPUnit + Playwright), commit if green. If tests break, read the
changelog, fix the calling code, and commit the fix together with the
dependency bump so the diff tells one story. When the fix is too complex
for the scope, pin the package and file an issue for later.

Tests must pass after each individual upgrade. That's the gate — resist the
urge to batch "just two small ones."

## Harden JS/CSS dependencies

Same principle as PHP deps, applied to the npm-asset and bower-asset
packages that Elgg manages via composer. List with `composer show | grep
"npm-asset\|bower-asset"` inside the Elgg container. Upgrade one at a time,
run PHPUnit, run JS unit tests (if the plugin has them), run the Playwright
E2E suite (which is the most likely to catch browser-side regressions),
check `error.log` for JS console noise, then commit.

A reasonable risk order for the common Elgg JS stack:

1. normalize.css (pure reset, very safe)
2. sprintf-js (utility)
3. cropperjs / jquery-cropper
4. jquery-colorbox (check for API changes)
5. jquery-ui (**medium risk** — widgets may use deprecated methods)
6. jQuery (**high risk** — major versions have breaking changes)
7. tagify (**high risk** — custom component, API churn)

Plugins with their own `package.json` go through the same loop run from the
`node` profile container, not the host.

For CSS dependencies (css-crush), the only reliable check is flushing
simplecache (`elgg_invalidate_caches()` via `docker compose exec elgg php
-r`) and verifying the rendered stylesheet is non-empty again.

Before starting this phase, make sure there's JS test coverage in place —
`/elgg-js-test-writer` is the right tool. Without it, JS regressions are
only caught by the Playwright suite, which is slow and doesn't pinpoint the
broken module.

## Final verification

Before declaring Part A complete, confirm all of the following. Missing any
of them means production isn't ready:

- All Elgg version steps complete (every step in the upgrade path)
- All PHP dependencies at latest stable
- All JS/CSS dependencies at latest stable
- Full test suite green (PHPUnit + Vitest + Playwright)
- Docker boots with all plugins active using the recorded order
- No PHP errors in `error.log`
- No JS console errors in the browser

When all of these hold, the migration branches are ready for Part B.

---

# PART B: EXECUTE (production checklist)

Part B is deterministic on purpose. Production upgrades are high-stakes and
hard to reverse, so improvisation is the failure mode, not the feature. The
checklist below is strict: every step has a verification check, and if any
check fails you STOP and decide between fix-forward and rollback.

The only legitimate variation is the **deployment model**. A single-server
site runs the checklist top-to-bottom as written. A blue-green or rolling
deployment may move steps 2 (maintenance mode) and 3–5 (code + core +
upgrade) off the live nodes and into the idle ones, then swap. The *gates*
don't change — you still need backup, verified restore, code update, core
update, upgrade script, verification, cache flush, and post-upgrade
monitoring in that dependency order. What changes is where each step runs.

If you're not sure what deployment model applies, run the checklist as
written.

## Pre-Flight

- [ ] All migration branches tested in Docker (Part A complete)
- [ ] Maintenance window scheduled
- [ ] Team notified
- [ ] Rollback plan documented

## Execution Checklist

### 1. BACKUP

- [ ] Database dump: `mysqldump -u root -p elgg > elgg_backup_$(date +%Y%m%d).sql`
- [ ] Data directory: `tar czf elgg_data_$(date +%Y%m%d).tar.gz /path/to/data/`
- [ ] Code snapshot: `git tag pre-upgrade-$(date +%Y%m%d)`
- [ ] **Verify backup is restorable** (test restore on a separate server)

### 2. ENABLE MAINTENANCE MODE

- [ ] Add to `settings.php`: `$CONFIG->elgg_maintenance_mode = true;`
- [ ] Or via admin UI: Admin → Settings → Advanced → Maintenance Mode

### 3. UPDATE PLUGIN CODE

- [ ] Merge migration branches for all plugins:
  ```bash
  # For each plugin with upstream repo
  cd ~/plugins-workspace/<plugin>
  git checkout migrate/elgg-{N}.x
  # Symlinks in mod/ already point here

  # For the project repo (custom plugins)
  cd <project>
  git merge migrate/elgg-{N}.x
  ```

### 4. UPDATE ELGG CORE

- [ ] `composer require elgg/elgg:~{N}.0`
- [ ] `composer update`
- [ ] Verify: `php vendor/bin/elgg-cli --version`

### 5. RUN UPGRADE

- [ ] Synchronous: `php vendor/bin/elgg-cli upgrade -v`
- [ ] Async: `php vendor/bin/elgg-cli upgrade async -v`
- [ ] Or web: visit `upgrade.php`, then `admin/upgrades`
- [ ] If stuck: `php vendor/bin/elgg-cli upgrade async -v --force`

### 6. VERIFY

- [ ] Site loads: `curl -sL https://your-site.com/ | grep '<title>'`
- [ ] Admin panel works: log in as admin, check plugins page
- [ ] Key features work: test critical user journeys
- [ ] No PHP errors in logs: `tail /var/log/elgg/error.log`
- [ ] Cron runs: `php vendor/bin/elgg-cli cron -v`

### 7. FLUSH CACHES

- [ ] `php vendor/bin/elgg-cli cache:clear`
- [ ] Or: Admin → Settings → Advanced → Flush Caches

### 8. DISABLE MAINTENANCE MODE

- [ ] Remove `$CONFIG->elgg_maintenance_mode` from settings.php
- [ ] Or via admin UI

### 9. POST-UPGRADE

- [ ] Monitor error logs for 24 hours
- [ ] Verify email notifications still work
- [ ] Verify cron jobs run successfully
- [ ] Update documentation

## Rollback Procedure

If the upgrade fails and cannot be fixed:

```bash
# 1. Restore database
mysql -u root -p elgg < elgg_backup_YYYYMMDD.sql

# 2. Restore code
git checkout pre-upgrade-YYYYMMDD
composer install

# 3. Restore data directory (if changed)
tar xzf elgg_data_YYYYMMDD.tar.gz -C /

# 4. Flush caches
rm -rf data/system_cache/* data/views_simplecache/*

# 5. Verify site works
curl -sL https://your-site.com/ | grep '<title>'
```

---


## Beads Formula

This workflow is available as a beads formula for structured task tracking:

```bash
# If using beads for issue tracking:
bd mol pour elgg-site-upgrade --var project=/path/to/project --var from=2.x --var to=3.x --var port=8380

# Or install the formula first:
cp formulas/elgg-site-upgrade.formula.json .beads/formulas/
```

The formula creates a hierarchy of issues with dependencies, ensuring each gate is verified before proceeding. The formula definition lives in `formulas/elgg-site-upgrade.formula.json`.

---

See [references/REFERENCE.md](references/REFERENCE.md) for version tables, troubleshooting, and migration learnings.
