# LLM-Based Security Review

The automated `SecuritySweep` (`src/SecuritySweep.php`) catches pattern-based vulnerabilities — known dangerous functions, hardcoded credentials, obvious SQL injection. But pattern matching cannot reason about context, data flow, or business logic.

For comprehensive security review, combine the automated sweep with an LLM-based deep review using the `/security-review` skill.

## Two-Stage Security Workflow

### Stage 1: Automated Pattern Sweep

Runs as part of the migration workflow via `--security`:

```bash
docker compose run --rm migrate bin/migrate.php rules/3x-to-4x/manifest.json /plugins/myplugin --verify --security
```

Catches:
- Critical functions (eval, unserialize, shell_exec, etc.)
- Obvious SQL string concatenation
- Unescaped output in views
- Hardcoded credentials
- Weak crypto (md5, sha1)
- Insecure file operations

**Strength**: Fast, deterministic, runs on every migration.
**Weakness**: Cannot understand context or follow data flow.

### Stage 2: LLM Deep Review

After automated sweep passes, run the `/security-review` skill on the migrated plugin:

```
/security-review --files=<plugin-path>
```

The LLM review covers what pattern matching can't:

#### 1. Data Flow Analysis

The LLM traces user-controlled input through the codebase:

```
get_input('title')
  → passed to BlogEntity::__construct()
    → assigned to $this->title
      → echoed in views/default/object/blog/full.php (line 42)
        → VULNERABILITY: unescaped output
```

Pattern matching sees `echo $vars['title']` in isolation but can't tell if it's user-controlled. The LLM follows the chain from action → entity → view.

#### 2. Authorization Boundary Checks

The LLM identifies missing access checks:

```php
// actions/blog/delete.php
$guid = (int) get_input('guid');
$blog = get_entity($guid);
$blog->delete();  // ❌ No ownership check!
```

A pattern scanner sees `$blog->delete()` and ignores it. The LLM recognizes the pattern: user input → entity lookup → privileged action without authorization.

#### 3. Business Logic Vulnerabilities

The LLM catches logic flaws that look syntactically correct:

- **IDOR**: User passing another user's GUID to access their data
- **Race conditions**: Check-then-act patterns without locking
- **Time-of-check vs time-of-use** (TOCTOU)
- **Mass assignment**: User-controlled fields setting privileged attributes
- **Open redirects**: Validating URL but missing scheme check

#### 4. Hook/Event Handler Trust

In Elgg, hooks receive data from other plugins. The LLM identifies handlers that trust hook data without validation:

```php
public static function processMenu(\Elgg\Hook $hook): array {
    $entity = $hook->getEntityParam();  // Could be ANY entity type
    $entity->custom_field = 'modified';  // ❌ No type check
    return $hook->getValue();
}
```

#### 5. Migration-Introduced Vulnerabilities

Migrations can introduce subtle issues. The LLM flags:

- Bootstrap classes that run privileged operations on every page load
- New action handlers that don't follow the same auth pattern as their siblings
- Default values added during migration that weaken security
- Removed CSRF tokens from custom AJAX endpoints

## Integration with Migration Workflow

### Recommended Two-Stage Flow

```bash
# Stage 1: Apply migration with automated sweep
docker compose run --rm migrate bin/migrate.php rules/3x-to-4x/manifest.json /plugins/myplugin --verify --security

# If exit code 4: fix critical issues, re-run

# Stage 2: LLM review (run from agent context)
# Use the /security-review skill on the migrated plugin
# /security-review --files=/path/to/myplugin
```

### Phase 2.10 Addition

After Phase 2.9 (style check), the agent SHOULD invoke the LLM security review skill:

> Run `/security-review --files=<plugin-path>` to perform deep security analysis
> on the migrated code. Address any HIGH or MEDIUM confidence findings before
> committing the migration.

## What the LLM Should Look For

### High Priority (Always Address)

1. **Authentication bypass** — paths that should require login but don't
2. **Authorization bypass** — actions that don't verify ownership/permissions
3. **SQL injection** — even in deprecated DB calls
4. **XSS** — unescaped output of user-controlled data
5. **CSRF** — custom endpoints bypassing Elgg's action system
6. **File upload vulnerabilities** — missing validation, unsafe storage
7. **Open redirects** — uncontrolled URL forwarding
8. **Server-side request forgery** — fetch URLs from user input

### Medium Priority (Review Required)

1. **Weak crypto for non-password use** — may be acceptable
2. **Information disclosure** — verbose error messages, stack traces
3. **Missing rate limiting** on sensitive actions
4. **Insecure deserialization** of trusted data
5. **Business logic flaws** specific to plugin functionality

### Low Priority (Document, Don't Block)

1. **Dependencies with known CVEs** — track separately
2. **Missing security headers** — usually configured at server level
3. **Outdated cipher suites** — server config issue

## Reporting Format

LLM security findings should be reported as:

```markdown
## SECURITY-001: Missing ownership check in blog delete

**Severity**: HIGH
**Confidence**: HIGH
**File**: actions/blog/delete.php:8
**Category**: authorization-bypass

### Issue
The action retrieves a blog entity by GUID and deletes it without verifying
that the current user owns it.

### Attack Vector
```http
POST /action/blog/delete
__elgg_token=...&__elgg_ts=...&guid=42
```
Any logged-in user can delete any blog post by submitting the action with
another user's blog GUID.

### Fix
```php
$blog = get_entity($guid);
if (!$blog instanceof Blog || !$blog->canEdit()) {
    return elgg_error_response(elgg_echo('actionunauthorized'), '', 403);
}
$blog->delete();
```

### References
- OWASP Top 10: A01 Broken Access Control
- Elgg docs: Action Permissions
```

## Combining Both Stages

```
Migration Pipeline:
┌──────────────┐    ┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│  Apply rules │ → │  Verify APIs │ → │ Pattern sweep│ → │  LLM review  │
│    --apply   │    │   --verify   │    │  --security  │    │/security-review│
└──────────────┘    └──────────────┘    └──────────────┘    └──────────────┘
       ↓                  ↓                   ↓                    ↓
   Files modified    Future API leaks   Pattern matches      Logic flaws
                     blocked            blocked              flagged for review
```

Each stage catches a different class of issues. Skipping any stage leaves a gap.
