# Reference

## Version Upgrade Paths (from Elgg docs)

| From | To | Requirement |
|------|----|-------------|
| < 2.0 | 2.x | Upgrade one minor at a time |
| 2.x | 3.x | Must be on 2.3.x first |
| 2.3+ | Any future | Can jump directly (but one-at-a-time is safer) |

## PHP/MySQL Requirements

| Elgg | PHP | MySQL | MariaDB |
|------|-----|-------|---------|
| 2.3 | >=5.6 | >=5.5 | >=5.5 |
| 3.3 | >=7.2 | >=5.6 | >=10.1 |
| 4.3 | >=7.4 | >=5.7 | >=10.3 |
| 5.1 | >=8.0 | >=5.7 | >=10.3 |
| 6.1 | >=8.1 | >=8.0 | >=10.6 |

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

## Available Migration Rules

| Step | Auto | LLM | Manifest |
|------|:----:|:---:|---------|
| 2.x→3.x | 12 | 15 | `rules/2x-to-3x/manifest.json` |
| 3.x→4.x | 6 | 5 | `rules/3x-to-4x/manifest.json` |
| 4.x→5.x | — | — | TODO |
| 5.x→6.x | — | — | TODO |
