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
4. **TAG MIGRATION BRANCHES — NO FLOATING DEV REFS IN composer.json.** After
   migrating a plugin and verifying it activates, tag the migration branch with
   a semantic version (`git tag N.0.0 && git push origin N.0.0`). Use `^N.0`
   in the root `composer.json`, not `dev-migrate/elgg-N.x`. Floating branch refs
   make deployments non-reproducible: any push to the branch changes what
   `composer install` installs. Tags are immutable. Check existing tags with
   `git tag | sort -V` before creating a new one — don't duplicate tags that
   already exist. Read tags from the local plugin workspace; never query Packagist
   to determine available versions for VCS repos.

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
  trust-but-verify (see `elgg-migrate/SKILL.md` "Trust but verify:
  adopting an upstream migration" for the checks — abandoned branches,
  wrong Elgg target, unrelated feature work mixed in, licensing,
  Docker activation, pre-migration tests against the upstream)
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

**Deployed version ≠ migrated version (release-lag).** A plugin's `migrate/*` branch
can be fully fixed while the site's `composer.lock` still pins a *pre-fix* tag — so
the built site ships broken plugin code even though the branch is green. When the
site loads from tags (the deploy contract), audit the **locked** version against the
latest released tag, not the branch tip:

```bash
# for each hypejunction plugin: locked vs latest remote tag
composer show 'hypejunction/*' --locked --format=json | …    # locked version
git ls-remote --tags <repo> | sort -V | tail -1              # latest released tag
```

Whole-fleet release-lag is one root cause, not N bugs. Tag the fixes, then a single
`composer update "<vendor>/*" --ignore-platform-reqs` + rebuild clears most of it.

---

## Staging preview before cutover (real data, isolated)

Stand up the migrated site beside the live one **before** DNS cutover, populated with
real prod data, to eyeball it. Rehearse on ANONYMIZED prod data, not raw PII:

- **Build an anonymized seed** with `bin/build-anon-seed.sh --dump <prod-2x.sql.gz>`.
  It restores the dump in a throwaway mysql:5.7, applies
  `references/anonymize-elgg2x.sql` (scrub PII, drop session/auth tables), and — the
  load-bearing part — encodes three footguns that each silently broke a real
  migration: (A) a blanket metastring scrub clobbers login flags (`validated`/`admin`/
  `banned`) → uservalidationbyemail blocks EVERY user, so those names are excluded;
  (B) `password_hash` is reset to a known `dev` bcrypt so users can actually log in;
  (C) `__site_secret__` is re-seeded because Elgg 3.x+ BootService HARD-THROWS on an
  empty secret, killing every chain tier past 2.x. The script fails if (A)/(B) trip.
  Log in on the preview as any `user_<guid>` / password `dev`. Copy the SQL and edit
  it for site-specific tables/plugins — but keep the login + site-secret sections.

- **The migration chain migrates the DB only.** The Elgg dataroot (avatars/uploads)
  is GUID-bucketed and version-stable across 2.x→7.x, so deploy the **prod dataroot
  directly** (`tar --strip-components=1` to drop the `elgg-data/` wrapper) — don't
  expect the chain to carry it. After loading, rewrite the `__site_secret_wwwroot`/
  `path` datalists to the preview URL/dataroot path.
- **EOL host with no native PHP 8?** Ship a single Docker container (code baked into
  the image, dataroot bind-mounted), bound to `127.0.0.1:<port>`, fronted by the
  existing nginx. Never `rm -rf` a bind-mounted path from inside the container.
- **Serve at a subpath** (`/forum-7.x/`) via an nginx **strip-prefix reverse proxy**:
  `location ^~ /forum-7.x/ { proxy_pass http://127.0.0.1:<port>/; }` (trailing slash
  strips the prefix), forward the real `Host` so Elgg's host-check passes and it
  emits `/forum-7.x/`-prefixed URLs from `wwwroot`. Basic-auth the location for PII.
- **Smoke it in a real browser, not just curl.** HTTP 200 hides JS/importmap failures
  (see `elgg-migrate/references/migration-lessons.md`). Assert `console.error` +
  `pageerror` empty per page type. Walled-garden sites need a login to reach content.

---

## Set up the workspace

### Plugin sourcing: composer VCS repos (preferred)

The cleanest and most reproducible way to manage third-party plugins in a
site upgrade is **composer VCS repositories** — not symlinks, rsync copies,
git worktrees, or submodules. Each plugin is declared as a VCS repo in
`composer.json`; `composer install` fetches the right branch and
`composer/installers` drops it into `mod/` automatically.

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/<owner>/<plugin>" }
],
"require": {
  "<vendor>/<plugin>": "dev-migrate/elgg-3.x as 3.x-dev"
}
```

**Branch naming convention.** Every plugin that spans multiple Elgg major
versions must have `migrate/elgg-N.x` branches, one per supported version:

| Branch | Code state |
|--------|-----------|
| `migrate/elgg-3.x` | Elgg 3.x-compatible (PHP-DI 5.x, `start.php` allowed) |
| `migrate/elgg-4.x` | Elgg 4.x-compatible (PHP-DI 6.x, Bootstrap class) |
| `migrate/elgg-5.x` | Elgg 5.x-compatible (Event API, Bootstrap) |
| ... | ... |

**`master` branch contamination warning.** When the 3→4 migration is done
on `master` (no feature branch), `master` ends up with 4.x-only API calls
even while the site is still on 3.x. Don't branch `migrate/elgg-3.x` from
`master` HEAD — find the last commit before the first `fix(4.x):` /
`migrate(4.x):` commit message and branch from there:

```bash
# Find the last 3.x-compatible commit
git log --oneline master | grep -B1 "4\.x" | head -1

# Create the branch from that commit
git branch migrate/elgg-3.x <commit-hash>
git push origin migrate/elgg-3.x
```

For a fleet of plugins, automate the check:
```bash
for plugin in plugins/*/; do
  cd "$plugin"
  first4x=$(git log --oneline master | grep "4\.x" | tail -1 | awk '{print $1}')
  if [ -n "$first4x" ]; then
    parent=$(git log --format="%H" "${first4x}^" -1)
    echo "$(basename $plugin): branch from $parent"
  fi
  cd -
done
```

### Symlink workspace (alternative)

For local-only workflows where composer VCS repos are not practical, use
symlinks from `mod/` into a separate plugins workspace:

```bash
mkdir -p ~/plugins-workspace
git clone https://github.com/<owner>/<plugin>.git ~/plugins-workspace/<plugin>

cd <project>/mod
rm -rf <plugin>
ln -s ~/plugins-workspace/<plugin> <plugin>
```

### Create migration branches

Create a migration branch in the project repo for each version step
(name is the TARGET version, matching `elgg-migrate`'s convention):

```bash
git -C <project> checkout -b migrate/elgg-{N}.x
```

For each plugin in the workspace, also ensure the target branch exists:

```bash
git -C ~/plugins-workspace/<plugin> checkout -b migrate/elgg-{N}.x
# or for composer VCS approach: branch already exists on GitHub
```

### Docker bind-mount override for the site app container

When the site's `Dockerfile` uses `COPY . .` (baking `mod/` into the
image) and `docker-compose.yml` also bind-mounts `./mod` to `/opt/plugins`,
the baked-in copy wins by default — the entrypoint's `symlink_plugins` only
creates symlinks for missing plugins. Any code fix you make on the host
is silently ignored at runtime.

Fix the `symlink_plugins` function so bind-mounted plugins always win:

```bash
# In docker-entrypoint.sh — replace "skip if exists" with "force symlink"
for entry in /opt/plugins/*; do
    plugin_name=$(basename "$entry")
    [[ "$plugin_name" == .* ]] && continue
    if [ -d "$entry" ]; then
        target="/var/www/html/mod/$plugin_name"
        rm -rf "$target" 2>/dev/null || true   # remove baked-in copy
        ln -sf "$entry" "$target"
        count=$((count + 1))
    fi
done
```

After changing this, rebuild the image (`docker compose build --no-cache app`)
so the updated entrypoint is baked in. The bind mount then wins at every
subsequent `docker compose up`.

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

## Verify composer consistency on each migration branch

Before running Docker or declaring a step ready, verify that `composer install`
succeeds from a clean state on the migration branch. This is the gate that
catches the most common silent failure mode: a lock file that was committed
when it was still in sync, but later diverged as upstream packages changed.

```bash
# Run in a clean directory to avoid inheriting any existing vendor/
cd /tmp
git clone <project-repo> test-composer-check
cd test-composer-check
git checkout migrate/elgg-${N}.x

# Primary check: does composer install from the lock file?
composer install --no-interaction 2>&1

# If it fails with platform errors (missing PHP extension, wrong PHP version):
composer install --no-interaction --ignore-platform-reqs 2>&1

# If it fails with lock-file inconsistency ("lock file does not contain compatible packages"):
#   → The lock file is out of sync with composer.json.
#   → Fix by running: composer update --no-interaction && git add composer.lock && git commit
```

**Common root causes of lock file staleness:**

- Packages renamed their default branch from `master` to `main`. The lock
  file records the resolved reference (`dev-master`) which no longer exists.
  Fix: `composer require vendor/package:dev-main` or bump to a tagged version.

- `composer.json` was updated manually without re-running `composer update`.
  Fix: run `composer update` and commit the new `composer.lock`.

- A lock file was copied from a different version branch.
  Fix: delete `composer.lock` and run `composer install` (or `update`).

**The gate:**

```bash
# Must pass (with or without --ignore-platform-reqs) before the branch is
# declared ready for Part B. If it only passes with composer update (not install),
# commit the updated composer.lock before proceeding.
composer install --no-interaction --ignore-platform-reqs 2>&1 && echo PASS || echo FAIL
```

`upgrade-linear.sh` includes this three-tier fallback automatically (install →
install --ignore-platform-reqs → update --ignore-platform-reqs), but it cannot
fix a broken `composer.json` that has unresolvable constraints. Those must be
fixed in the migration branch before Part B runs.

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

**Seeding from a production dump? Restore the prod active set with
`bin/restore-active-plugins.sh`.** When the stage DB is overlaid from an
(anonymized) production dump rather than freshly installed, the `.plugin-order.txt`
loop above is not enough: Elgg <4.x keeps camelCase plugin titles
(`hypePrototyper`), but the captured prod active list is lowercase, so a naive
case-sensitive match silently skips every camelCase plugin — leaving active
lowercase dependents calling into a *disabled* dependency (fatal "Call to
undefined function hypePrototyper()"). The script matches on `LOWER(title)` and
activates the **intersection** of (prod-active TSV) ∩ (plugins on disk this
stage), so intentionally-dropped plugins with a stale `enabled='yes'` entity but
no directory are left inactive. It also runs `generateEntities()` and enables
`enabled='no'` entities first, and is idempotent.

```bash
skills/elgg-site-upgrade/bin/restore-active-plugins.sh \
  --tsv snapshots/prod-active-plugins.tsv \
  --db-container <site>${N}x-db-1 \
  --app-container <site>${N}x-app-1 \
  [--mod-path /var/www/html/mod] [--db elgg] [--dry-run]
```

Capture the TSV at "Record the current plugin activation order" above
(`<plugin_id>\t<priority>` per line). Use `--dry-run` first to preview the
would-activate / dropped sets, then flush caches and restart the app container.

**Site renders.** `curl -sL http://localhost/ | grep -oP '<title>[^<]*</title>'`
must return a real title, not "Fatal Error". This is a necessary FIRST check —
but it is NOT the definition of done. An anonymous homepage-title check passes
while every authenticated page 500s, which on a walled-garden community is
almost the whole site. The real bar is render parity, below.

**Render parity (the executable definition of "done").** A site upgrade is
finished only when the migrated site renders what the old one did. Prove it
with a route-render golden master — every registered GET route, crawled
anonymously AND authenticated, HTTP status per route — captured before the
step and diffed forward. Any route that was 2xx/3xx and is now 5xx/unreachable
fails the gate. This is `bin/verify-parity.sh` (wrapping the shared
`baseline-golden-master.sh` engine); baselines live in the SITE repo
(`GM_BASELINE_DIR`) so they are versioned with the code.

```bash
# BEFORE upgrading, on the current-version stack — snapshot the oracle.
# --user/--pass are REQUIRED for a walled-garden site or the auth crawl is blind.
skills/elgg-site-upgrade/bin/verify-parity.sh capture {from} \
  --baseline-dir snapshots/parity --user <admin> --pass <pass>

# AFTER upgrading + booting the target stack — capture {to} and gate vs {from}.
skills/elgg-site-upgrade/bin/verify-parity.sh check {from} {to} \
  --baseline-dir snapshots/parity --user <admin> --pass <pass>
# exit 0 = parity; exit 1 = a route regressed with no whitelist entry.
```

**Write paths (the other half of "done").** Parity is GET-only and never runs
action/CRUD/data-layer code, where the nastiest 6→7 breaks hide (insert/delete
data + relationship signature drift, DBAL `:`-prefixed param keys, an entity
`save()` contract mismatch, `canWriteToContainer(null)`). `bin/verify-write-paths.sh`
logs in and actually POSTs core journeys (create group, edit user settings) with
DB-delta verification, then GET-sweeps every create/edit form for 5xx (form-build
code == submit code). Point it at your plugins' own add/edit routes:

```bash
skills/elgg-site-upgrade/bin/verify-write-paths.sh \
  --base http://localhost:{{port}} --user <admin> --pass <pw> \
  --db-container <db-container> --forms-file snapshots/plugin-forms.txt
# forms file: one route per line, {guid} expands to the logged-in user, e.g.
#   /myplugin/add/{guid}
```

Intended, reviewed changes (a route deliberately removed/redirected) go one
key per line in `snapshots/parity/parity-whitelist.tsv` (format matches the
golden file's column 1: `<anon|auth> <METHOD> <path>`) — so the gate stays
meaningful instead of being switched off. Re-evaluate after editing the
whitelist without re-crawling: `verify-parity.sh diff {from} {to}`. Capture one
golden master per version step and diff each step forward; a clean forward-diff
at every tier is what "pixel-perfect across 2.x→7.x" actually means here.
Status parity is the floor — still open the site in a browser and diff against
the pre-migration render for visual/CSS drift the status code can't see.

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

---

## Known compatibility gotchas by version transition

These recur across codebases. Check for them early — they cause site-wide
500s that block all other diagnostic work.

### 3.x site running plugins with 4.x code

If `mod/` plugins were updated from a 4.x-migrated branch (or a `master`
branch that has 4.x commits merged in), the site will 500 immediately on
boot even though `composer.json` still requires Elgg 3.x.

**Root cause:** PHP-DI version mismatch. Elgg 3.x ships PHP-DI 5.x
(`DI\object()`); Elgg 4.x ships PHP-DI 6.x (`DI\create()`). Calling
`DI\create()` on a 3.x install throws `Call to undefined function`.

**Fix:** In each plugin's `elgg-services.php`, replace:
```php
// 4.x (PHP-DI 6.x)
'key' => \DI\create(SomeClass::class)->constructor(\DI\get('dep')),

// 3.x (PHP-DI 5.x)
'key' => \DI\object(SomeClass::class)->constructor(\DI\get('dep')),
```

**How to detect all affected files quickly:**
```bash
grep -rl "DI\\\\create" <project>/mod/
```

### Global function calls inside PHP namespaces

Any Elgg helper function called from within a `namespace Foo\Bar { ... }`
block requires the global-namespace `\` prefix, or PHP resolves it as
`Foo\Bar\function_name()` and throws `Call to undefined function`.

```php
// Wrong inside namespace Acme\DBExplorer
$locale = get_current_language();        // → Acme\DBExplorer\get_current_language() — FATAL

// Correct
$locale = \get_current_language();
```

### Elgg 3.x language API

| Function | Correct version |
|----------|----------------|
| `get_current_language()` | Elgg 2.x and 3.x (deprecated in 3.x, removed in 4.x) |
| `elgg_get_language()` | Elgg 4.x+ only — does NOT exist in 3.x |

When migrating a plugin that calls `get_current_language()` inside a
namespace, fix to `\get_current_language()` for 3.x. When later migrating
to 4.x, replace with `elgg_get_language()`.

### CSS that breaks css-crush v2.4

Certain CSS constructs (complex selectors, vendor prefixes on variables)
cause css-crush v2.4 to silently emit an empty stylesheet when CSS views are
extended via `elgg_extend_view('css/elgg', ...)`. The site loads but has no
styles.

**Fix:** Serve those stylesheets as external files, not through the
simplecache pipeline:
```php
// Instead of:
elgg_extend_view('css/elgg', 'css/theme/custom.css', 999);

// Use:
elgg_register_css('theme.custom', '/mod/my_theme/views/default/css/theme/custom.css');
elgg_load_css('theme.custom');
```

Verify the fix: check that `elgg.css` from simplecache is > 1 KB after a
cache flush.

### Composer VCS repos: dependency versioning

**Always use tagged version constraints.** After migrating a plugin and
verifying it works, tag the migration branch and reference the tag in the
root `composer.json`. Look up the latest available tag from the local plugin
workspace:

```bash
# Find the latest tag for each major version in each plugin repo
PLUGINS_DIR=~/plugins-workspace
for plugin in "$PLUGINS_DIR"/*/; do
  name=$(basename "$plugin")
  tags=$(git -C "$plugin" tag | sort -V)
  for major in 3 4 5 6; do
    latest=$(echo "$tags" | grep "^${major}\." | tail -1)
    echo "$name: $major.x → ${latest:-NONE}"
  done
done

# Then update composer.json to use ^N.0 constraints
"require": {
  "acme/wall":     "^6.0",
  "acme/interactions": "^6.0",
  "acme/lists":    "^6.0"
}
```

The `^N.0` constraint, pinned by `composer.lock`, is the correct approach:
reproducible, standard, and forward-compatible within the major. Do NOT use
`dev-migrate/elgg-N.x` — floating branch refs break reproducibility.

**Dev-branch aliases (legacy workaround — avoid).** Before all plugin
migration branches were tagged, a workaround was to add inline aliases:

```json
"acme/forms_api": "dev-migrate/elgg-3.x as 1.2.1"
```

This told Composer to treat the branch as version 1.2.1 so inter-plugin
constraints resolved. This is unnecessary once the branch is tagged and the
constraint is `^N.0`. If you encounter aliases in an existing `composer.json`,
replace them with the proper tagged constraint.

To find which inter-plugin constraints need satisfying (useful when adding a
new plugin to a migration branch), scan every plugin's `composer.json`:

```bash
for p in plugins/*/; do
  git -C "$p" show migrate/elgg-N.x:composer.json 2>/dev/null
done | python3 -c "
import sys, json
for line in sys.stdin:
    try:
        d = json.loads(line)
    except Exception:
        continue
    for pkg, ver in d.get('require', {}).items():
        if pkg.startswith('acme/'):  # your project's vendor prefix
            print(d['name'], '->', pkg, ver)
"
```

**Plugin `fzaninotto/faker` version mismatch.** Elgg 3.x requires `fzaninotto/faker ^1.9`.
If a plugin's `migrate/elgg-3.x` branch still has `fzaninotto/faker 1.3.*` (a leftover from
the pre-3.x era), `composer update` fails with an unsolvable conflict. Fix the plugin's
branch directly:

```bash
# In the plugin repo on migrate/elgg-3.x
sed -i 's/"fzaninotto\/faker": "1\.3\.\*"/"fzaninotto\/faker": "^1.9"/' composer.json
git add composer.json && git commit -m "fix(deps): bump fzaninotto/faker to ^1.9 for Elgg 3.x"
git push origin migrate/elgg-3.x
```

**`composer/installers` v1 vs Composer 2.3+ (Plugin API mismatch).** Elgg 3.x
has a hard dependency chain: `elgg/elgg 3.3` → `elgg/login_as ~2.1` →
`composer/installers ^1.0.8`. There is no newer version of `elgg/login_as` with
an updated constraint. `composer/installers` v1 requires Plugin API `^1.0`, but
Composer 2.3+ ships Plugin API 2.x — causing the plugin to be silently skipped.
When skipped, all `elgg-plugin` type packages land in `vendor/` instead of `mod/`.

Composer does NOT have an npm-style `overrides` key that would force a specific
version of a transitive dependency, and the `replace` key would prevent any
`composer/installers` from being installed at all.

**Fix: pin Composer 2.2 in the Dockerfile.** Composer 2.2.x is an actively
maintained LTS branch that supports both PHP 7.x and Plugin API 1.x. Switch the
image copy from `composer:2` (which resolves to latest 2.x) to `composer:2.2`:

```dockerfile
# Before
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# After (Elgg 3.x projects — Plugin API 1.x required for composer/installers v1)
COPY --from=composer:2.2 /usr/bin/composer /usr/bin/composer
```

**For local development with system Composer 2.3+:** Composer will still resolve
packages correctly but `composer/installers` will be skipped. Two options:
1. Use `docker exec <app> composer update` to run composer inside the container
   (which uses Composer 2.2 baked into the image)
2. Run `composer self-update --2.2` locally while working on Elgg 3.x projects,
   then `composer self-update --rollback` when done

**Update `composer/installers` constraint in plugin branches.** Plugins whose
`migrate/elgg-3.x` branch has `"composer/installers": "~1.0"` will block v2
from being used when the site eventually upgrades to a Composer 2.3+ compatible
Elgg version. Proactively update all migration branches:

```bash
for p in plugins/*/; do
  git -C "$p" checkout migrate/elgg-3.x 2>/dev/null || continue
  cj="$p/composer.json"
  if grep -q '"composer/installers": "~1\.0"' "$cj"; then
    sed -i 's/"composer/installers": "~1\.0"/"composer\/installers": "^1.0 || ^2.0"/' "$cj"
    git -C "$p" add composer.json
    git -C "$p" commit -m "fix(deps): support composer/installers ^2.0"
    git -C "$p" push origin migrate/elgg-3.x
  fi
done
```

**`elgg/login_as` and `composer/installers` — use `replace` to break the chain.**
`elgg/elgg 3.3` depends on `elgg/login_as ~2.1.0`. That package (last released 2020,
no newer version) requires `composer/installers ^1.0.8`. There is no upstream fix.

Do NOT use Composer 2.2 as a workaround — it's unnecessary. Instead, use the
`replace` key in the root `composer.json` to declare that the root project already
provides `login_as` at the required version. Composer will satisfy `elgg/elgg`'s
constraint without installing the package (and without bringing in the `^1.0.8`
constraint). The `login_as` plugin won't be in `mod/`; if the site doesn't use it,
that's fine — activation-order scripts skip plugins not found in `mod/`.

Also `replace` the three abandoned sort packages that `site_search` depends on
(they also block installer v2):

```json
"replace": {
    "elgg/login_as":              "2.1.0",
    "acme/user_sort":     "1.1.1",
    "acme/group_sort":    "1.1.2",
    "acme/object_sort":   "1.1.3"
}
```

After these four replaces, the root can safely require `"composer/installers": "^2.0"`,
which resolves to v2.3+ and works with Composer 2.9+.

To find other transitive packages blocking installer v2, inspect the lockfile:
```bash
php -r "
\$lock = json_decode(file_get_contents('composer.lock'), true);
foreach (\$lock['packages'] as \$p) {
    \$v = (\$p['require'] ?? [])['composer/installers'] ?? null;
    if (\$v && strpos(\$v, '||') === false && strpos(\$v, '^2') === false)
        echo \$p['name'].' '.\$p['version'].': '.\$v.PHP_EOL;
}"
```

**HTTPS vs SSH for VCS repos.** Use HTTPS URLs (`https://github.com/...`) for
VCS repos, not SSH (`git@github.com:`). Docker build containers don't have SSH
keys. HTTPS works for public repos without a token; add a `GITHUB_TOKEN` build
arg for rate-limit headroom:

```dockerfile
ARG GITHUB_TOKEN
RUN if [ -n "${GITHUB_TOKEN}" ]; then \
    composer config -g github-oauth.github.com "${GITHUB_TOKEN}"; fi
```

```bash
docker compose build --build-arg GITHUB_TOKEN=$(gh auth token)
```

**Installer-name casing mismatches.** Some plugins have a lowercase `installer-name`
in their `extra` section (e.g., `mymaps` → `installer-name: "mymaps"`) but
the site's `.plugin-order.txt` and database use the original camelCase ID
(e.g., `MyMaps`). Fix with `installer-paths` in the ROOT `composer.json`:

```json
"extra": {
  "installer-paths": {
    "mod/MyMaps": ["acme/mymaps"]
  }
}
```

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
- `composer install` succeeds on each migration branch (see "Verify composer consistency" above)
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
written. For the blue-green model end-to-end — go/no-go gates, backup, chain
migrate, smoke, DNS flip, seconds-fast rollback, backup/restore round-trip
proof, and the guarded stale-plugin-entity prune — copy
`references/cutover-runbook.md` into the site repo and fill its placeholders.

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
