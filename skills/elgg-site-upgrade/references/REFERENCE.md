# Reference

## Version Upgrade Paths (from Elgg docs)

| From | To | Requirement |
|------|----|-------------|
| < 2.0 | 2.x | Upgrade one minor at a time |
| 2.x | 3.x | Must be on 2.3.x first |
| 2.3+ | Any future | Can jump directly (but one-at-a-time is safer) |
| 6.x | 7.x | PHP 8.3+, remove CSS Crush deps, switch to Symfony Mailer |

## PHP/MySQL Requirements

| Elgg | PHP | MySQL | MariaDB |
|------|-----|-------|---------|
| 2.3 | >=5.6 | >=5.5 | >=5.5 |
| 3.3 | >=7.2 | >=5.6 | >=10.1 |
| 4.3 | >=7.4 | >=5.7 | >=10.3 |
| 5.1 | >=8.0 | >=5.7 | >=10.3 |
| 6.1 | >=8.1 | >=8.0 | >=10.6 |
| 7.0 | >=8.3 | >=8.0 | >=10.6 |

## Composer Commands Per Step

```bash
# 2.x → 3.x
composer require elgg/elgg:~3.3.0 && composer update && vendor/bin/elgg-cli upgrade async -v

# 3.x → 4.x
composer require elgg/elgg:~4.3.0 && composer update && vendor/bin/elgg-cli upgrade async -v

# 4.x → 5.x
composer require elgg/elgg:~5.1.0 && composer update && vendor/bin/elgg-cli upgrade async -v

# 5.x → 6.x
composer require elgg/elgg:~6.1.0 && composer update && vendor/bin/elgg-cli upgrade async -v

# 6.x → 7.x
composer require elgg/elgg:~7.0.0 && composer update && vendor/bin/elgg-cli upgrade async -v
```

## Troubleshooting

**All plugins crash on upgrade:**
Create empty file `<project>/mod/disabled` to disable all plugins. Run upgrade. Remove file. Re-activate plugins individually.

**`upgrade.php` returns error:**
Add `$CONFIG->security_protect_upgrade = false;` to settings.php. Remove after upgrade.

**Upgrade hangs / mutex lock:**
`php vendor/bin/elgg-cli upgrade async -v --force`

**Plugin depends on removed function:**
Check if the function was removed pre-2.x (our rules only cover 2.x→3.x+). Common pre-2.x removals:
- `register_notification_object()` → `elgg_register_notification_event()`
- `register_notification_handler()` → notification event system
- `elgg_register_entity_url_handler()` → `entity:url` hook

**Entity subtable queries crash (3.x):**
`groups_entity`, `users_entity`, `objects_entity`, `sites_entity` tables removed in 3.x. Rewrite JOINs to use metadata queries or QueryBuilder.

## Learnings From production-site Migration

### What Went Right
1. **92 plugins migrated** — automated rules applied to all, 47 had code changes
2. **Batch migration script** (`bin/migrate-plugin.sh`) processed 45 upstream plugins
3. **Docker verification** caught real issues that syntax checks missed
4. **Plugin activation ordering** via `.plugin-order.txt` worked reliably (90/92 activated)

### What We Got Wrong
5. **Skipped Phase 2 (test baseline)** — no tests existed before migration, so we had no safety net to detect regressions
6. **Skipped static analysis** — PHPStan would have caught method signature mismatches (`delete()` vs `delete($follow_symlinks)`), removed interfaces (`Elgg\Cache\Pool`), and undefined functions before Docker
7. **Batch-migrated everything at once** instead of verifying each plugin individually — cascading errors made debugging harder
8. **Fixed issues reactively** in Docker instead of catching them with `php -l` + PHPStan + tests

### Common Issues Encountered
9. **Method signature mismatches** — Elgg 3.x added parameters to methods like `ElggFile::delete($follow_symlinks)`. Overrides without the new parameter cause fatal errors in PHP 7.4+. **PHPStan level 0 catches these.**
10. **Removed interfaces** — `Elgg\Cache\Pool` and similar interfaces removed in 3.x. Classes implementing them cause fatal errors. **PHPStan catches these.**
11. **Removed functions in non-statement context** — `is_memcache_available()` inside `if()` conditions or ternaries aren't removed by our AST rules (only standalone statement calls are removed). **PHPStan catches these.**
12. **Hardcoded vendor paths** — `require_once dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php'` breaks with symlinks. Use `file_exists()` guard or remove (Elgg autoloader already loaded).
13. **Symlinks don't resolve in Docker** — absolute host-path symlinks need the target mounted in the container too.
14. **Pre-2.x function removals** — `register_notification_object()`, `elgg_register_entity_url_handler()` removed before 2.x, not covered by our 2.x→3.x rules.
15. **PHP 7.4 syntax changes** — `isset()` on function results is a parse error.
16. **Entity subtable removal** — `groups_entity`, `users_entity`, etc. removed in 3.x. Raw SQL JOINs to these tables cause fatal errors.
17. **Plugin-local vendor directories** — some plugins have their own `vendors/` or `vendor/` directory. Guard with `file_exists()` before `require_once`.

### Correct Per-Plugin Migration Flow (what we should have done)

```
For EACH plugin:
  1. php -l *.php                    # Syntax check
  1.5. composer install (if plugin has own deps)  # Install plugin-specific packages
  2. phpstan analyse --level 0       # Static analysis (catches signature/interface issues)
  3. Run automated migration rules   # AST transforms
  4. php -l *.php                    # Re-check syntax
  5. phpstan analyse --level 0       # Re-check static analysis
  6. Run tests (if they exist)       # Verify behavior
  7. Commit
  8. Verify in Docker                # Integration check
```

Steps 2 and 5 would have caught ALL the issues we found reactively in Docker.

### Recommended New Rules to Add
- **MethodSignatureCheck** — detect overridden methods with incompatible signatures
- **RemovedInterfaceCheck** — detect classes implementing removed interfaces
- **RemoveVendorAutoload** — detect and remove redundant Elgg root autoloader requires (added)
- **SubtableQueryCheck** — detect JOINs to removed entity subtables
- **ComposerInstallCheck** — detect plugins with composer.json but no vendor/ dir

### CSS / Simplecache Issues

18. **css-crush v2.4 silently fails on certain CSS** — The `_elgg_views_preprocess_css` hook runs css-crush on the entire `elgg.css` simplecache bundle. If any extended CSS view contains patterns that cause css-crush to fail, the **entire site stylesheet becomes empty** (0 bytes) — a catastrophic silent failure with no error log.

**Root cause:** css-crush v2.4.0 (2015) silently returns empty string instead of erroring when it encounters certain CSS patterns in large bundles. The failure is context-dependent — the same CSS works in isolation but fails when combined with other rules.

**Known trigger:** Custom CSS files with complex selectors (like `.all.topics .elgg-layout-content > .elgg-content`) can break css-crush when bundled into `elgg.css` via `elgg_extend_view('css/elgg', 'css/theme/custom.css')`.

**Diagnosis:**
```php
// Check if simplecache CSS is empty
$size = strlen(file_get_contents("http://localhost:PORT/cache/TIMESTAMP/default/elgg.css"));
// If 0 or 1, css-crush is failing

// Debug which view breaks it:
elgg_unextend_view('css/elgg', 'css/theme/suspect.css');
$content = elgg_view('elgg.css');
$compiled = _elgg_services()->cssCompiler->compile($content);
// If compiled is now >0 bytes, that view was the culprit
```

**Fix:** Load the problematic CSS as an external stylesheet instead of extending it into the bundle:
```php
// Instead of:
elgg_extend_view('css/elgg', 'css/theme/custom.css', 999);

// Use:
elgg_register_css('theme.custom', '/mod/PLUGIN/views/default/css/theme/custom.css');
elgg_load_css('theme.custom');
```

**Prevention rule for migrations:** After migrating, verify simplecache CSS is non-empty:
```bash
# MUST return > 1000 bytes
curl -sL -o /dev/null -w "%{size_download}" "http://localhost:PORT/cache/$(curl -sL http://localhost:PORT/ | grep -oP 'cache/\K\d+' | head -1)/default/elgg.css"
```

19. **Settings.php overrides database values** — `simplecache_enabled` and `system_cache_enabled` in `settings.php` override database values. If `elgg_save_config('simplecache_enabled', 0)` writes to settings.php, it persists even after re-enabling in the database. Always check settings.php directly.

20. **Container doesn't reflect host file changes** — When plugins are COPY'd into the Docker image at build time (not volume-mounted), changes to host files require either `docker compose cp` or a full rebuild. The `./mod:/opt/plugins` volume mount only provides symlink targets, not the actual plugin source for COPY'd plugins.

## Docker Testing Setup for Site Verification

### Docker Architecture

Use a two-service Docker Compose stack:
- **app** — PHP 7.4-apache (for 3.x) with Elgg core installed via Composer
- **db** — MySQL 5.7 with healthcheck

**Key design decisions:**
- Keep composer.json minimal (only `elgg/elgg`, `composer/installers`, `symfony/var-dumper`)
- Replace `roave/security-advisories` in `composer.json` to avoid circular conflicts with older Elgg versions
- Mount local `mod/` to `/opt/plugins` and symlink at startup (see below)
- Set `simplecache_enabled = false` and `system_cache_enabled = false` in settings.php

### Plugin Loading via Symlinks

Plugins come from two sources:
1. **Git-tracked plugins** (custom plugins like `bodyology_*`) — COPY'd into the image via Dockerfile
2. **Composer-installed plugins** (upstream like `hype*`) — exist as symlinks in `mod/` pointing to `$PLUGINS_SOURCE/<name>` (resolved at runtime; never hard-coded)

**Problem:** Symlinks use absolute host paths that don't resolve inside the container.
**Solution:** Mount the plugin source directory at the same absolute path inside the container:

```yaml
volumes:
  - ./mod:/opt/plugins
  - <plugins-dir>:<absolute-host-path-of-plugins-dir>:ro
```

The entrypoint script:
1. Removes broken symlinks from `/var/www/html/mod/` (leftover from COPY of host symlinks)
2. Symlinks real directories from `/opt/plugins` into `/var/www/html/mod/`
3. Host-path symlinks resolve because the source dir is mounted at the same path

### Plugin Activation Order

Create `mod/.plugin-order.txt` with one plugin ID per line (comments with `#`).
Source the order from the production database or from a previously saved activation order file.

The entrypoint activates plugins in this order, logging OK/SKIP/FAIL for each.

### Playwright E2E Testing

**Setup:**
- Playwright 1.50+ with Chromium
- Auth setup stores session in `playwright/.auth/admin.json`
- Tests run sequentially (workers: 1) against the Docker site

**Gotchas:**
- **External resources block page load** — Themes that load fonts or assets from external CDNs (e.g., Google Fonts) block Playwright's `load` event for 30+ seconds. Fix: set `timeout: 60_000` in playwright config and use `waitUntil: "domcontentloaded"` for navigation.
- **Custom index has no login form** — Some themes show a branded homepage without a login form for unauthenticated users. Auth setup must navigate to `/login` explicitly.
- **Strict mode violations** — Always use `.first()` on multi-match CSS selectors (`".elgg-page-body, .elgg-page"`) to avoid Playwright strict mode errors.
- **Registration may be disabled** — Tests for `/register` should check if the form exists before interacting.

**Docker Compose for E2E:**
```yaml
# docker-compose.e2e.yml extends docker-compose.yml
services:
  app:
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:80/"]
      interval: 10s
      start_period: 60s  # Allow time for plugin activation
  playwright:
    image: mcr.microsoft.com/playwright:v1.50.0-noble
    working_dir: /e2e
    volumes:
      - ./e2e:/e2e
    environment:
      BASE_URL: http://app:80
    depends_on:
      app:
        condition: service_healthy
```

### Gathering Active Plugin List

To get the plugin activation order from a running Elgg site:

```php
php -r "
require 'vendor/autoload.php';
\$app = \Elgg\Application::getInstance();
\$app->bootCore();
\$plugins = elgg_get_plugins('active');
foreach (\$plugins as \$p) echo \$p->getID() . PHP_EOL;
" > mod/.plugin-order.txt
```

Or from the database directly:
```sql
SELECT ps.value FROM elgg_private_settings ps
JOIN elgg_entities e ON e.guid = ps.entity_guid
WHERE e.type = 'object' AND e.subtype = 'plugin'
AND ps.name = 'elgg:internal:priority'
ORDER BY CAST(ps.value AS UNSIGNED);
```

## Additional Learnings from Site Migration E2E Testing

### Issues Found by E2E Testing

18. **`foreach` by reference on iterators** — In Elgg 3.x, hook return values like `MenuItems` implement `Traversable` but cannot be iterated by reference. `foreach ($return as &$item)` crashes. **Rule 028 now catches this.** Replace with `iterator_to_array()` + key-based iteration.

19. **Plain array passed to menu hook** — `elgg_trigger_plugin_hook('register', 'menu:*', $params, $plain_array)` crashes because core hook handlers call `->merge()` on what they expect to be a `MenuItems` collection. **Rule 027 (LLM-guided) documents this.** Wrap default in `new \Elgg\Menu\MenuItems()`.

20. **`elgg_check_access_overrides()` in conditionals** — The removed functions rule had this as `action: remove`, but when used in `if` conditions, removal breaks logic. Changed to `action: rename, to: elgg_is_admin_user` since both take a user GUID argument.

21. **Composer version constraints stale after migration** — When plugins are migrated to a new major version, their packagist versions may change (major bumps). For Docker testing, it's simpler to strip all plugin deps from `composer.json` and use local symlinks instead.

22. **Image rebuild required for git-tracked plugin fixes** — Fixes to git-tracked plugins require a Docker image rebuild since they're COPY'd into the image. Symlinked/volume-mounted plugins are reflected immediately.

## Migration Branch Integrity Validation

### Verify the linear migration chain

Each migration branch must be a strict ancestor of the next. Use this check before starting any validation work:

```bash
cd <project>
git merge-base --is-ancestor master elgg-3.x      && echo "master ⊂ elgg-3.x ✓" || echo "FAIL"
git merge-base --is-ancestor elgg-3.x migrate/4.x  && echo "elgg-3.x ⊂ migrate/4.x ✓" || echo "FAIL"
git merge-base --is-ancestor migrate/4.x migrate/5.x && echo "4.x ⊂ 5.x ✓" || echo "FAIL"
git merge-base --is-ancestor migrate/5.x migrate/6.x && echo "5.x ⊂ 6.x ✓" || echo "FAIL"
git merge-base --is-ancestor migrate/6.x migrate/7.x && echo "6.x ⊂ 7.x ✓" || echo "FAIL"
```

If any check fails, the branch history has diverged — rebase before proceeding.

### Verify plugin branch consistency per step

For each migration branch, every `hypejunction/*` package should reference `dev-migrate/elgg-X.x` (where X matches the branch). Check this per branch with:

```bash
for b in elgg-3.x migrate/4.x migrate/5.x migrate/6.x migrate/7.x; do
  echo "=== $b ==="
  git show $b:composer.json | python3 -c "
import sys, json, re
d = json.load(sys.stdin)
branch = '$b'
# Extract expected version suffix from branch name
m = re.search(r'(\d+)', branch)
expected = 'elgg-' + m.group(1) + '.x' if m else '?'
anomalies = []
for k, v in d.get('require', {}).items():
    if 'hypejunction' in k and 'dev-migrate/' not in str(v):
        anomalies.append(f'{k}: {v}')
if anomalies:
    print('ANOMALIES:')
    for a in anomalies: print(' ', a)
else:
    print(f'All hypejunction packages on dev-migrate/{expected} ✓')
"
done
```

Version aliases (e.g. `dev-migrate/elgg-3.x as 1.2.1`) are normal for packages that require a semver constraint from downstream.

## Docker Validation of Migration Steps

### Database migration must be tested sequentially

**Never skip steps.** The correct test protocol for a full migration path:

1. Build and start the N.x stack (fresh database)
2. Run Elgg's seeder: `vendor/bin/elgg-cli database:seed --limit 20`
3. Export the database: `docker compose exec db mysqldump -uelgg -pelgg elgg > /tmp/elgg-Nx.sql`
4. Tear down the N.x stack (keep the volume for re-testing)
5. Build and start the (N+1).x stack; import the dump:
   ```bash
   docker compose exec -T db mysql -uelgg -pelgg elgg < /tmp/elgg-Nx.sql
   ```
6. Run Elgg's upgrade: `docker compose exec app vendor/bin/elgg-cli upgrade async -v`
7. Verify: site loads, plugins active, no fatal errors in logs
8. Repeat from step 3 for the next step

Skipping this and doing fresh installs at every step only validates installation, not data migration.

### 2.x → 3.x specific: Phinx schema migration required before elgg-cli upgrade

The 2.x → 3.x jump requires a Phinx schema migration that changes `elgg_entities.subtype` from `INT` (pointing to `elgg_entity_subtypes.id`) to `VARCHAR(252)` (the subtype string directly). This schema change **must run before** `elgg-cli upgrade` — the upgrade command creates `ElggUpgrade` entities which fail with `Incorrect integer value: 'elgg_upgrade' for column 'subtype'` if the column is still INT.

Run migration via `Elgg\Application::migrate()`:

```bash
docker compose exec app php -r "
require 'vendor/autoload.php';
\Elgg\Application::migrate();
echo 'Migrations complete.' . PHP_EOL;
"
```

**Blocker: reserved subtype in `elgg_entity_subtypes`**

The `denormalize_entity_subtypes` migration validates that `elgg_entity_subtypes` has no entries with reserved names (`user`, `group`, `site`) for their corresponding entity types. Elgg 2.x registers `type='group', subtype='group'` during bootstrap (via `elgg_register_entity_type('group', 'group')`), which triggers:

```
InstallationException: Unable to perform migration DenormalizeEntitySubtypes, because the database
contains entities with a reserved subtype name ['group'] for entities of 'group' type
```

**Fix:** Delete the reserved subtype entries before running migrations. Safe if no actual entities use them (check with `COUNT(*)`):

```sql
-- Verify no entities use these subtypes
SELECT es.subtype, COUNT(e.guid) as entity_count 
FROM elgg_entity_subtypes es
LEFT JOIN elgg_entities e ON e.subtype = es.id
WHERE es.type IN ('user','group','site')
GROUP BY es.id, es.subtype;

-- Only delete if entity_count = 0 for affected rows
DELETE FROM elgg_entity_subtypes 
WHERE type IN ('user','group','site') AND subtype IN ('user','group','site');
```

**Blocker: upgrade lock after partial run**

`elgg-cli upgrade` creates a mutex lock table. If the first run fails partway through (e.g., can't find a plugin's upgrade class before plugin is activated in the PHP process), the lock persists. Clear it:

```sql
DROP TABLE IF EXISTS elgg_upgrade_lock;
```

Then re-run with `upgrade async -v` (not plain `upgrade`). The `async` subcommand is more resilient to the chicken-and-egg problem where the first pass activates plugins in the DB but their autoloaders aren't registered in the running PHP process yet.

**Full 2.x → 3.x upgrade sequence:**

```bash
# 1. Import 2.x dump into 3.x db (start db service only, not app)
docker compose -f docker/elgg3/docker-compose.yml up -d db
# wait for healthy, then import
docker compose -f docker/elgg3/docker-compose.yml exec -T db mysql -uelgg -pelgg elgg < /tmp/elgg_2x_dump.sql

# 2. Start app (detects existing tables, skips fresh install)
docker compose -f docker/elgg3/docker-compose.yml up -d app

# 3. Remove reserved subtypes (needed for fresh installs that activated plugins)
docker compose -f docker/elgg3/docker-compose.yml exec db mysql -uelgg -pelgg elgg \
  -e "DELETE FROM elgg_entity_subtypes WHERE type IN ('user','group','site') AND subtype IN ('user','group','site');"

# 4. Run Phinx schema migrations
docker compose -f docker/elgg3/docker-compose.yml exec app php -r "
require 'vendor/autoload.php';
\Elgg\Application::migrate();
echo 'done' . PHP_EOL;
"

# 5. Clear any stale upgrade lock, then run upgrade
docker compose -f docker/elgg3/docker-compose.yml exec db mysql -uelgg -pelgg elgg \
  -e "DROP TABLE IF EXISTS elgg_upgrade_lock;"
docker compose -f docker/elgg3/docker-compose.yml exec app php vendor/bin/elgg-cli upgrade async -v
```

### 2.x Docker setup: composer/installers v1 + Composer 2 puts elgg-plugins in vendor/

In Elgg 2.x projects, `composer/installers v1.x` is locked. When built with **Composer 2**, the installer
no longer places `elgg-plugin` packages into `mod/<name>/` — they land at `vendor/<vendor>/<name>/` instead.
The symptom is a fatal "function not defined" on every page because the plugin's `start.php` was never included.

Confirm by checking `vendor/composer/installed.json`:
```bash
# 2.x (broken with Composer 2): install-path stays in vendor/
"install-path": "../hypejunction/menus_api"
# 3.x+ (correct): install-path goes to mod/
"install-path": "../../mod/menus_api"
```

**Fix:** ship a `fix-mod-symlinks.sh` script that symlinks all `elgg-plugin` packages from `vendor/` into `mod/`:

```sh
#!/bin/sh
php -r '
$json = json_decode(file_get_contents("vendor/composer/installed.json"), true);
$pkgs = isset($json["packages"]) ? $json["packages"] : $json;
foreach ($pkgs as $pkg) {
    if (($pkg["type"] ?? "") !== "elgg-plugin") continue;
    $basename = basename($pkg["name"]);
    $src = realpath("vendor/" . $pkg["name"]);
    $dst = "mod/" . $basename;
    if ($src && !file_exists($dst)) {
        symlink($src, $dst);
        echo "Linked: " . $dst . PHP_EOL;
    }
}
'
```

In the Dockerfile, **do not inline this PHP** with `RUN php -r "..."` — Docker's parser interprets lines
starting with `$json` as unknown instructions (parse error on `\$json`). Instead:

```dockerfile
COPY fix-mod-symlinks.sh .
RUN chmod +x fix-mod-symlinks.sh && ./fix-mod-symlinks.sh
```

**Elgg 2.x CLI: output_buffering required**

PHP 7.4 deprecation warnings in Elgg 2.x code (e.g., `continue` targeting switch in `engine/lib/input.php`)
are emitted before `session_start()`, causing "headers already sent" fatal errors on any CLI PHP invocation
that loads Elgg. Fix by adding `output_buffering = 4096` to `/usr/local/etc/php/conf.d/elgg.ini` AND
wrapping every CLI Elgg call with `ob_start()`:

```bash
# In the ini file
output_buffering = 4096

# In the entrypoint
php -d output_buffering=On -r "ob_start(); require_once 'vendor/autoload.php'; /* ... */ "
```

### Common Dockerfile issues in multi-branch repos

When the `migrate/7.x` branch gets a Dockerfile fix, earlier branches don't inherit it automatically (migration branches are linear tags, not a forking tree). After each session where you fix the top-most branch's Dockerfile, check these on every earlier branch:

**1. HTTPS git rewrite (must be present in all branches)**
```dockerfile
# Required before any composer install step
RUN git config --global url."https://github.com/".insteadOf "git@github.com:"
```
Without this, `composer install` will try SSH for VCS packages and fail with "Host key verification failed" inside Docker (no SSH keys in containers).

**2. `composer install` not `composer update`**
```dockerfile
# Use install (uses composer.lock) — NOT update (re-resolves from GitHub API)
RUN composer install --no-dev --prefer-dist --no-interaction
```
`composer update` re-resolves all versions via the GitHub API on every build. Without a token it rate-limits; with a token it's slow and non-deterministic. `composer install` uses the committed lock file — reproducible and faster.

Check all branches with:
```bash
for b in elgg-3.x migrate/4.x migrate/5.x migrate/6.x migrate/7.x; do
  echo -n "$b HTTPS-rewrite: "; git show $b:Dockerfile | grep -c "insteadOf" || echo "0 MISSING"
  echo -n "$b composer cmd: "; git show $b:Dockerfile | grep "^RUN composer " | grep -v "config\|global"
done
```

### Git worktrees and Docker: `.dockerignore` required

When running `git worktree add /tmp/foo branch-name`, the worktree contains a `.git` **file** (not directory) with content like:
```
gitdir: /home/user/project/.git/worktrees/foo
```

If this file is `COPY`'d into a Docker build context without a `.dockerignore`, git commands inside the container fail:
```
fatal: not a git repository: /home/user/project/.git/worktrees/foo
```
(The host path doesn't exist inside the container.)

**Fix:** always create `.dockerignore` containing `.git` in any worktree before running `docker compose build`:
```bash
echo ".git" > /tmp/worktree-dir/.dockerignore
```

This is only needed for worktree builds; normal checkouts have a full `.git` directory that Docker ignores by convention.

### PHP base image versions and EOL Debian

Use this mapping for the correct PHP base image per Elgg version:

| Elgg | Dockerfile `FROM` | Debian | Status |
|------|-------------------|--------|--------|
| 2.x | `php:7.4-apache`  | Bullseye | ✓ active |
| 3.x | `php:7.4-apache`  | Bullseye | ✓ active |
| 4.x | `php:8.1-apache`  | Bullseye | ✓ active |
| 5.x | `php:8.2-apache`  | Bookworm | ✓ active |
| 6.x | `php:8.2-apache`  | Bookworm | ✓ active |
| 7.x | `php:8.3-apache`  | Bookworm | ✓ active |

**Do not use `php:7.1-apache` or `php:7.2-apache`** — both are based on Debian Buster which reached EOL in June 2024. `apt-get update` inside the build will fail with 404 errors on the package repos. Elgg 2.x runs correctly on PHP 7.4.

The `gd` extension configure syntax changed between PHP 7.3 and 7.4:
```dockerfile
# PHP 7.1-7.3 (do not use — Buster EOL)
RUN docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/

# PHP 7.4+ (correct)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg
```

### Docker Compose v2 plugin path on Fedora/RHEL

On Fedora/RHEL systems, the `docker-compose-plugin` RPM installs the binary to `/usr/libexec/docker/cli-plugins/docker-compose`, not the standard `~/.docker/cli-plugins/` location that Docker CLI searches. Result: `docker compose` command not found even though the RPM is installed.

Fix (one-time, per user):
```bash
mkdir -p ~/.docker/cli-plugins
ln -sf /usr/libexec/docker/cli-plugins/docker-compose ~/.docker/cli-plugins/docker-compose
docker compose version   # should show Docker Compose version v5.x.x
```

## Available Migration Rules

| Step | Auto | LLM | Manifest |
|------|:----:|:---:|---------|
| 2.x→3.x | 13 | 17 | `rules/2x-to-3x/manifest.json` |
| 3.x→4.x | 6 | 22 | `rules/3x-to-4x/manifest.json` |
| 4.x→5.x | 0 | 20 | `rules/4x-to-5x/manifest.json` |
| 5.x→6.x | 0 | 12 | `rules/5x-to-6x/manifest.json` |
| 6.x→7.x | 0 | 21 | `rules/6x-to-7x/manifest.json` |
