# Post-Migration Security Review Checklist

This checklist documents the security checks performed by the `SecuritySweep` class (`src/SecuritySweep.php`) and additional manual checks for the LLM-based security review.

## Automated Checks (via `--security` flag)

### Critical (always flagged, blocks migration)

| Pattern | Category | Risk |
|---------|----------|------|
| `eval()` | eval | Arbitrary code execution |
| `unserialize()` without allowed_classes | unserialize | PHP object injection (RCE) |
| `exec()`, `shell_exec()`, `system()`, `passthru()`, `popen()`, `proc_open()` | command-injection | Shell command injection |
| Backtick operator with variables | command-injection | Shell injection |
| `preg_replace()` with /e modifier | eval | Code execution via regex |
| `assert()` with string argument | eval | Code execution |

### Contextual (warning, requires review)

| Pattern | Category | Context |
|---------|----------|---------|
| SQL query with variable interpolation | sql-injection | Any DB call |
| SQL string concatenation (`SELECT ... . $var`) | sql-injection | Raw queries |
| `echo get_input()` | xss | Direct user input output |
| `echo $variable` in views/ | xss | Views directory only |
| Hardcoded passwords/secrets | hardcoded-credentials | Config files |
| `md5()` for hashing | deprecated-crypto | Password/auth context |
| `sha1()` for hashing | deprecated-crypto | Password/auth context |
| `file_get_contents($var)` | insecure-file-ops | SSRF, path traversal |
| Dynamic `include`/`require` with variable | insecure-file-ops | Local file inclusion |
| `move_uploaded_file()` | insecure-file-ops | File upload handling |
| `header("Location: " . $var)` | xss | Open redirect |
| `extract($var)` | eval | Variable overwrite |

### Elgg-Specific

| Pattern | Category | Notes |
|---------|----------|-------|
| `elgg_get_config('dbprefix')` | sql-injection | Use QueryBuilder or `getTablePrefix()` |
| `get_input()` in SQL context | sql-injection | Always parameterize |

## LLM-Based Security Review (manual)

After the automated sweep, run the `/security-review` skill on the plugin for deeper analysis:

```
/security-review --files=<plugin-path>
```

### Focus Areas for Elgg Plugins

1. **Action CSRF protection**
   - All actions registered via `elgg-plugin.php` get automatic CSRF tokens
   - Custom AJAX endpoints that bypass Elgg's action system need manual token validation
   - Check: `elgg_get_input('__elgg_token')` and `elgg_get_input('__elgg_ts')`

2. **Access control in queries**
   - `elgg_get_entities()` respects access control by default
   - `elgg_call(ELGG_IGNORE_ACCESS, ...)` bypasses it — verify this is intentional
   - Check entity ownership before allowing edit/delete operations

3. **View output escaping**
   - Elgg's view system does NOT auto-escape — `echo $vars['title']` is XSS
   - Use `elgg_echo()` for translations, `htmlspecialchars()` for user data
   - `elgg_view()` is safe (it processes through the view system)

4. **File upload validation**
   - Check MIME type server-side (don't trust client `Content-Type`)
   - Validate file extension against allowlist
   - Store uploads outside webroot or use Elgg's file storage API

5. **SQL in custom queries**
   - Prefer `elgg_get_entities()` and QueryBuilder over raw SQL
   - If raw SQL is necessary, use prepared statements via `elgg()->db`
   - Never interpolate `get_input()` into SQL strings

6. **Hook/event handler input trust**
   - Hook values (`$hook->getValue()`) may contain data from other plugins
   - Validate before using in security-sensitive contexts
   - Event objects (`$event->getObject()`) — verify entity type before casting

7. **Plugin settings storage**
   - `$plugin->setSetting()` stores values as strings — no serialization needed
   - Never store sensitive data (API keys, passwords) in plugin settings without encryption
   - Plugin settings are visible to admins

8. **Upgrade scripts**
   - `Elgg\Upgrade\Batch` scripts run with elevated privileges
   - Validate data integrity during migration
   - Use transactions for multi-step data changes

## Integration with Migration Workflow

The security sweep runs automatically when using `--security`:

```bash
# During migration (Phase 2.1)
docker compose run --rm migrate bin/migrate.php rules/3x-to-4x/manifest.json /plugins/myplugin --verify --security

# Standalone scan
docker compose run --rm migrate bin/migrate.php rules/3x-to-4x/manifest.json /plugins/myplugin --dry-run --security
```

Exit code 4 indicates critical security issues that must be resolved before committing.
