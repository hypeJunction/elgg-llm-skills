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
1. **Git-tracked plugins** (custom plugins like `myproject_*`) — COPY'd into the image via Dockerfile
2. **Composer-installed plugins** (upstream like `vendor/*`) — exist as symlinks in `mod/` pointing to `$PLUGINS_SOURCE/<name>` (resolved at runtime; never hard-coded)

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
    image: mcr.microsoft.com/playwright:v1.49.0-noble
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

For each migration branch, every project-vendor (`acme/*`) package should reference `dev-migrate/elgg-X.x` (where X matches the branch). Check this per branch with:

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
    if 'acme' in k and 'dev-migrate/' not in str(v):
        anomalies.append(f'{k}: {v}')
if anomalies:
    print('ANOMALIES:')
    for a in anomalies: print(' ', a)
else:
    print(f'All project packages on dev-migrate/{expected} ✓')
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
"install-path": "../acme/menus_api"
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

## 5.x → 6.x specific: breaking API changes found during linear migration validation

The following were found during sequential 2x→3x→4x→5x→6x DB migration validation on a production project (May 2026).

### `Elgg\Lifecycle\BootstrapInterface` renamed and moved

`Elgg\Lifecycle\BootstrapInterface` no longer exists in Elgg 6.x. The replacement is `Elgg\PluginBootstrapInterface`. However, the interface gained two new required methods — `elgg()` and `plugin()` — that only the abstract base class `Elgg\PluginBootstrap` implements.

**Fix for all plugin Bootstrap classes:**
```php
// OLD (5.x)
use Elgg\Lifecycle\BootstrapInterface;
class Bootstrap implements BootstrapInterface {

// NEW (6.x)
use Elgg\PluginBootstrap;
class Bootstrap extends PluginBootstrap {
```

Do NOT implement `BootstrapInterface` directly; extend `PluginBootstrap`. The base class also provides constructor injection for `$plugin` and `$dic`.

Files to scan: `grep -rl "Elgg\\Lifecycle\\BootstrapInterface" mod/`

### `elgg_get_breadcrumbs()` removed in 5.x

Breadcrumbs became a menu in Elgg 5.x. Any `navigation/breadcrumbs` view override that calls `elgg_get_breadcrumbs()` will crash with `Call to undefined function`.

**Fix:** replace with `elgg_view_menu('breadcrumbs', ...)`:
```php
// OLD (4.x)
$breadcrumbs = elgg_get_breadcrumbs();
// ... custom <ul> rendering

// NEW (5.x+)
echo elgg_view_menu('breadcrumbs', [
    'sort_by' => 'register',
    'class' => elgg_extract_class($vars, ['elgg-menu', 'elgg-breadcrumbs', 'elgg-menu-hz']),
]);
```

### `elgg_register_widget_type()` changed to array syntax in 6.x

Positional arguments removed; only accepts a single `array $options` argument.

```php
// OLD (5.x)
elgg_register_widget_type('feedback', elgg_echo('feedback:name'), elgg_echo('feedback:desc'), ['profile']);

// NEW (6.x)
elgg_register_widget_type([
    'id' => 'feedback',
    'name' => elgg_echo('feedback:name'),
    'description' => elgg_echo('feedback:desc'),
    'context' => ['profile'],
]);
```

### `add_group_tool_option()` replaced by service API in 6.x

```php
// OLD (5.x)
add_group_tool_option('news', elgg_echo('news:enablenews'), true);

// NEW (6.x)
elgg()->group_tools->register('news', [
    'label' => elgg_echo('news:enablenews'),
    'default_on' => true,
]);
```

### `elgg_register_entity_type()` removed in 6.x

Entity type registration for search now goes exclusively in `elgg-plugin.php` under the `'entities'` key:

```php
// elgg-plugin.php
return [
    'entities' => [
        [
            'type' => 'object',
            'subtype' => 'my_subtype',
            'searchable' => true,
        ],
    ],
];
```

Remove all `elgg_register_entity_type()` / `elgg_unregister_entity_type()` calls from Bootstrap and start.php files. Plugins that only have `start.php` (no `elgg-plugin.php`) need a new manifest file for search to work.

### `elgg_get_upgrade_files()` removed in 6.x

The old file-based upgrade mechanism (`upgrades/*.php` included via `elgg_get_upgrade_files()`) is gone. Plugins using this pattern:

```php
// OLD — crashes in 6.x
public static function runUpgrades(\Elgg\Event $event) {
    $files = elgg_get_upgrade_files(__DIR__ . '/../../../upgrades/');
    foreach ($files as $file) { include $file; }
}
```

**Fix:** stub out with an empty method body (old file-based upgrades from 2012 have certainly already run on any live site). Register proper `ElggUpgrade` batch classes in `elgg-plugin.php` for any new upgrade logic.

### `elgg_entities.deleted` column required before upgrade CLI can boot (5.x → 6.x)

Elgg 6.x added `deleted` and `time_deleted` columns to `elgg_entities` (migration `20230606155735_add_delete_columns_to_entities_tables.php`). But the upgrade CLI queries this column during its own bootstrap — before the Phinx migration that adds it has run.

**Symptom:** `elgg-cli upgrade async` fails immediately with:
```
[DatabaseException] Unknown column 'e.deleted' in 'where clause'
```

**Fix:** add the column manually before running the upgrade CLI:
```sql
ALTER TABLE elgg_entities
    ADD COLUMN deleted ENUM('yes','no') NOT NULL DEFAULT 'no',
    ADD COLUMN time_deleted INT(11) NOT NULL DEFAULT 0;
```

Then run `upgrade async -v`. The Phinx migration will detect the column already exists and skip it.

Note: MySQL 5.7 does not support `ADD COLUMN IF NOT EXISTS` — use a raw `ALTER TABLE` and handle the "Duplicate column" error if re-running.

### phpfastcache boot directory must exist before Elgg 5.x boots

Elgg 5.x added phpfastcache as a boot-time cache. The directory must exist before the first request or CLI command:

```bash
# In docker-entrypoint.sh
mkdir -p "$ELGG_DATA_ROOT" \
    "${ELGG_DATA_ROOT}cache/fastcache" \
    "${ELGG_DATA_ROOT}cache/localfastcache" \
    "${ELGG_DATA_ROOT}cache/stash" \
    "${ELGG_DATA_ROOT}assets"
chown -R www-data:www-data "$ELGG_DATA_ROOT"
```

Without this, you get:
```
PhpfastcacheIOException: The directory /var/data/elgg/cache/fastcache/elgg_boot/Files could not be created
```

### Composer-installed plugin fixes require updating composer.lock

When a bug fix is committed to a plugin's VCS branch (e.g., `migrate/elgg-6.x`) and the root project's `composer.json` references that branch via `dev-migrate/elgg-6.x`, composer installs the **locked commit** from `composer.lock`. Simply pushing a fix to the branch is not enough.

**Fix:** update the `reference` field for that package in `composer.lock`:
```python
import json
data = json.load(open('composer.lock'))
for pkg in data['packages']:
    if pkg['name'] == 'vendor/plugin-name':
        pkg['source']['reference'] = '<new-commit-hash>'
        if 'dist' in pkg:
            pkg['dist']['reference'] = '<new-commit-hash>'
        break
json.dump(data, open('composer.lock', 'w'), indent=4)
```

Or run `composer update vendor/plugin-name` inside the project to have composer resolve the latest commit on the branch.

### Dockerfile rm -rf of composer-managed plugins must not include custom plugins

The 6.x Dockerfile explicitly deletes composer-managed plugin directories before `composer install` so they are refreshed from VCS. **Do not include custom/git-tracked plugin directories** in this list — they are not reinstalled by composer and will be permanently deleted from the container.

Verify the list matches exactly what is in `composer.json`'s `require` block.

## Available Migration Rules

| Step | Auto | LLM | Manifest |
|------|:----:|:---:|---------|
| 2.x→3.x | 13 | 17 | `rules/2x-to-3x/manifest.json` |
| 3.x→4.x | 6 | 22 | `rules/3x-to-4x/manifest.json` |
| 4.x→5.x | 0 | 20 | `rules/4x-to-5x/manifest.json` |
| 5.x→6.x | 0 | 12 | `rules/5x-to-6x/manifest.json` |
| 6.x→7.x | 0 | 21 | `rules/6x-to-7x/manifest.json` |

## upgrade-linear.sh: production upgrade script

`bin/upgrade-linear.sh` automates Part B for a git-managed production site.
Run it on the server where the site is installed and the production DB is accessible.

### Prerequisites

- Site root is a git repo with all migration branches prepared (Part A complete)
- `git`, `composer`, `php`, `mysqldump`, `mysql` on PATH
- Site DB credentials in `<project>/elgg-config/settings.php`

### Basic usage

```bash
# Auto-detect current version, upgrade to 6.x
bin/upgrade-linear.sh --project /var/www/html --to 6

# Non-interactive (CI/automation)
bin/upgrade-linear.sh --project /var/www/html --from 5 --to 6 --yes

# Dry run to preview steps
bin/upgrade-linear.sh --project /var/www/html --from 5 --to 6 --dry-run
```

### Branch name defaults

| Version | Default branch |
|---------|---------------|
| 2 | `main` |
| 3 | `migrate/elgg-3.x` |
| 4 | `migrate/4.x` |
| 5 | `migrate/5.x` |
| 6 | `migrate/6.x` |
| 7 | `migrate/7.x` |

Override with `ELGG_BRANCH_N=<name>` env vars.

### Known MySQL client quirks

- **TLS errors in Docker**: use `--skip-ssl` (script adds this automatically). `--ssl-mode=DISABLED` is MySQL client only — MariaDB client uses `--skip-ssl`.
- **mysqldump PROCESS privilege**: `mysqldump: Access denied for PROCESS privilege` is a warning, not a failure. The dump still succeeds for the schema. Suppress by granting PROCESS or ignore the warning.

### Composer install fallback

If `composer install` fails due to missing platform extensions or platform-version mismatches (common when upgrading in-place on an older PHP), the script retries with `--ignore-platform-reqs`. This mirrors the Dockerfile approach used during Part A.

### Schema pre-fixes encoded

| Step | Fix |
|------|-----|
| 5→6 | `ALTER TABLE elgg_entities ADD COLUMN deleted ENUM('yes','no') NOT NULL DEFAULT 'no', ADD COLUMN time_deleted INT(11) NOT NULL DEFAULT 0` — guards with INFORMATION_SCHEMA check so it's idempotent |

### Validation test (2026-05-13)

Tested via `docker exec` inside the 5x container against the live production-like DB:
- Input: Elgg 5.1.x site, 5x DB with carried-forward data from 2x→3x→4x→5x chain
- `git checkout migrate/6.x` pulled latest branch (commit `164bc3a`)
- Composer installed with `--ignore-platform-reqs` (PHP 8.2 + MariaDB client)
- Schema pre-fix added `deleted`/`time_deleted` columns successfully
- `elgg-cli upgrade async` ran all Phinx migrations
- `elgg-cli --version` reported **6.1.5** ✓
- Site returned HTTP 200 ✓
- Exit code: 0 ✓

## Composer lock file consistency — prep gate

Run this check on every migration branch **before** declaring Part A complete
and before running `upgrade-linear.sh` in production:

```bash
composer install --no-interaction --ignore-platform-reqs 2>&1 | grep -E '^(Problem|Your lock|Installing)' | head -10
```

If it fails, diagnose by category:

| Symptom | Root cause | Fix |
|---------|-----------|-----|
| `lock file does not contain compatible packages` | composer.json changed after lock was committed | `composer update && git add composer.lock && git commit` |
| `dev-master not found` | Package renamed default branch to `main` | `composer require vendor/pkg:dev-main` or pin to a tag |
| `requires ext-gmp * → missing` | PHP extension not installed | Add `--ignore-platform-reqs` OR install the extension |
| `requires php >=8.4` with platform override of `8.2` | Lock was generated without platform override | Add `"platform": {"php": "8.2"}` to `composer.json` config and `composer update` |
| Dependency conflict between packages | Incompatible package version tree | Update the specific conflicting constraint; may need to upgrade plugin to a newer release |

### upgrade-linear.sh fallback chain

The script tries three tiers automatically:
1. `composer install` (from lock file, exact versions)
2. `composer install --ignore-platform-reqs` (platform mismatch only)
3. `composer update --ignore-platform-reqs` (stale lock file)

Tier 3 (`composer update`) changes the lock file in the running upgrade — it
is NOT committed back to the migration branch. If tier 3 was needed, fix and
commit the lock file to the migration branch before the next production run.

### Composer `version` field in tagged commits silently breaks constraints

When a plugin's `composer.json` contains a hard-coded `"version"` field (e.g., `"version": "5.1.3"`) AND
you create a git tag with a different name (e.g., `5.0.0`), Composer silently rejects the tag.

**Symptom:** `composer require vendor/plugin:^5.0` resolves to a pre-existing 5.1.x tag instead of
your migration `5.0.0` tag, or `[InvalidArgumentException] version constraint is not parseable`.

**Root cause:** Composer reads the `version` field from the tagged commit's `composer.json`. If the field
value disagrees with the tag name, Composer ignores the tag. If the field value matches a *different*
existing tag, the two entries collide.

**Fix:** remove the `"version"` field entirely from `composer.json` before tagging. Composer will then
use the git tag name as the version. Commit the removal, force-retag, and force-push:

```bash
# In the plugin repo, on the migration branch
python3 -c "
import json, sys
d = json.load(open('composer.json'))
d.pop('version', None)
json.dump(d, open('composer.json', 'w'), indent=4)
print('version field removed')
"
git add composer.json && git commit -m 'chore: remove version field so Composer uses tag name'
git tag -f 5.0.0   # or whatever the migration tag is
git push origin 5.0.0 --force
```

Scan all migration-branch `composer.json` files before tagging:
```bash
for dir in "$PLUGINS_SOURCE"/*/; do
  f="$dir/composer.json"
  [ -f "$f" ] && python3 -c "import json,sys; d=json.load(open('$f')); print('$f:', d.get('version','OK — no version field'))" 2>/dev/null
done | grep -v 'OK'
```

### `amdConfig` DI service removed in Elgg 6.x

`_elgg_services()->amdConfig` (the RequireJS AMD configuration service) was removed in Elgg 6.x when
AMD/RequireJS was replaced by native ES modules.

**Symptom:** `DI\NotFoundException: No entry or class found for 'amdConfig'` — site returns HTTP 500
on the first request after the 5→6 upgrade.

**Common location:** theme plugins' `foot.php` view override:
```php
// OLD (5.x) — remove both lines
$deps = _elgg_services()->amdConfig->getDependencies();
// ... later in the file:
require(<?= json_encode($deps, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>);
```

**Fix:** delete both lines. In Elgg 6.x, JS modules are loaded via `<script type="module">` tags
managed by the core `page/elements/foot` view. Theme `foot.php` overrides should not inject AMD
require calls.

Scan for usages: `grep -rl "amdConfig" mod/`

### Elgg 3.x `Upgrade\Locator::getBatch()` fails when plugin classes not yet autoloaded

During an in-place upgrade from 2.x to 3.x, the upgrade CLI bootstraps before plugins are fully
initialized. If a plugin registers an `ElggUpgrade` batch class in `elgg-plugin.php`, the class exists
in `mod/<plugin>/classes/` but is NOT yet on the autoloader path when `Locator::getBatch()` is called.

**Symptom:** `InvalidArgumentException: Upgrade class Foo\Bar\Upgrade\MyBatch was not found` — the
upgrade skips or aborts the batch even though the file exists.

**Fix:** patch `Elgg\Upgrade\Locator::getBatch()` to fall back to scanning `mod/*/classes/` before
throwing:

```php
public function getBatch($class) {
    if (!class_exists($class)) {
        $classFile = str_replace('\\', DIRECTORY_SEPARATOR, ltrim($class, '\\')) . '.php';
        $modDir = realpath(__DIR__ . '/../../../../../../../mod');
        if ($modDir) {
            foreach (glob($modDir . '/*/classes/', GLOB_ONLYDIR) ?: [] as $dir) {
                $path = $dir . $classFile;
                if (file_exists($path)) {
                    require_once $path;
                    break;
                }
            }
        }
    }
    if (!class_exists($class)) {
        throw new \InvalidArgumentException("Upgrade class $class was not found");
    }
    // ... rest unchanged
}
```

The `realpath(...)` depth is 7 levels up from `engine/classes/Elgg/Upgrade/Locator.php` to the webroot.

This patch should be submitted upstream to Elgg 3.x or carried as a project-level override. Once plugins
are properly activated in the running PHP process (second pass with `upgrade async`), the fallback is
no longer needed.

### `checkout_branch()` in upgrade-linear.sh: reset tracked files before clean

`git clean -fd` only removes *untracked* files. After `composer update` modifies `composer.lock` (a
tracked file), switching branches with `git checkout` fails:

```
error: Your local changes to the following files would be overwritten by checkout:
    composer.lock
```

**Fix** (already applied to `bin/upgrade-linear.sh`): add `git reset --hard HEAD` before `git clean -fd`:

```bash
git -C "$PROJECT" reset --hard HEAD --quiet 2>/dev/null || true
git -C "$PROJECT" clean -fd --quiet
```

### Known migration branch issues

| Branch | Issue | Status |
|--------|-------|--------|
| `migrate/elgg-3.x` | composer.lock has `dev-master` refs for packages that renamed to `main`; `composer update` also fails due to pelago/emogrifier conflict. Workaround: pre-seed vendor/ from the 3x Docker container. | Open — composer.lock needs regeneration |

## 6.x → 7.x specific: breaking API changes found during linear migration validation

The following were found during sequential 2x→3x→4x→5x→6x→7x DB migration validation on a production project (May 2026).

### `add_translation()` removed in 7.x

Language files using the old `add_translation($lang, $array)` pattern fail at boot with `Call to undefined function add_translation()`.

**Old format (pre-5.x):**
```php
$english = array('key' => 'value');
add_translation('en', $english);
```

**New format (5.x+):**
```php
return ['key' => 'value'];
```

For dynamic translation registration inside class methods (e.g., `Config::registerLabels()`), replace with the translator service:
```php
// OLD
add_translation('en', ['key' => 'value']);
// NEW
elgg()->translator->addTranslation('en', ['key' => 'value']);
```

Scan: `grep -rl 'add_translation' mod/ | grep '\.php$' | grep -v vendor`

### `elgg_register_notification_event()` action arg must be string in 7.x

Passing an array of actions no longer works:
```php
// OLD (5.x) — crashes in 7.x
elgg_register_notification_event('object', 'my_subtype', ['create', 'update']);

// NEW — one call per action
elgg_register_notification_event('object', 'my_subtype', 'create');
elgg_register_notification_event('object', 'my_subtype', 'update');
```

Scan: `grep -rn 'elgg_register_notification_event.*\[' mod/ | grep -v vendor`

### Upgrade succeeds (exit 0) despite "Cannot include elgg-plugin.php" warnings

During `elgg-cli upgrade async`, Elgg logs `ERROR: Cannot include elgg-plugin.php for plugin X` for
plugins that don't have an `elgg-plugin.php` (still in 2.x/3.x format with only `start.php`). These
are non-fatal warnings — the upgrade completes successfully (exit 0) even if many plugins log this.
The affected plugins simply won't be activated during the upgrade run; they remain disabled until
migrated.

### Validation test (2026-05-14)

Tested via Docker 7x stack (PHP 8.3, the 7x app container), seeded with 6x DB dump:
- Phinx migration `20250904095834 UpdateSystemLog` ran successfully
- `elgg-cli upgrade async` exit code: 0
- Additional fixes required beyond what the 5→6 step needed (see above)
- Site returned HTTP 200, CSS 164KB ✓
- Elgg version: 7.0.0-rc.1
