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

## Available Migration Rules

| Step | Auto | LLM | Manifest |
|------|:----:|:---:|---------|
| 2.x→3.x | 13 | 17 | `rules/2x-to-3x/manifest.json` |
| 3.x→4.x | 6 | 22 | `rules/3x-to-4x/manifest.json` |
| 4.x→5.x | 0 | 20 | `rules/4x-to-5x/manifest.json` |
| 5.x→6.x | 0 | 12 | `rules/5x-to-6x/manifest.json` |
| 6.x→7.x | 0 | 21 | `rules/6x-to-7x/manifest.json` |
