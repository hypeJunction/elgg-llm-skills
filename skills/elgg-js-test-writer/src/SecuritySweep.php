<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Post-migration security scanner for Elgg plugins.
 *
 * Checks for common security issues that may exist in legacy code
 * or be introduced during migration. Runs after all migration rules
 * have been applied.
 *
 * Categories:
 * - sql-injection: Raw SQL without parameterization
 * - xss: Unescaped output in views
 * - command-injection: Shell command execution
 * - hardcoded-credentials: Passwords, API keys, secrets in code
 * - insecure-file-ops: Unsafe file operations
 * - deprecated-crypto: Weak hashing or encryption
 * - unserialize: PHP object injection via unserialize()
 * - eval: Code execution via eval/assert
 */
final class SecuritySweep
{
    public function __construct(
        private readonly ?SemgrepRunner $semgrep = null,
    ) {}

    /**
     * Patterns that are ALWAYS flagged regardless of context.
     * These are high-confidence indicators of security issues.
     */
    private const CRITICAL_PATTERNS = [
        'eval' => [
            'pattern' => '/\beval\s*\(/',
            'message' => 'eval() allows arbitrary code execution',
            'severity' => 'error',
            'category' => 'eval',
        ],
        'unserialize' => [
            'pattern' => '/\bunserialize\s*\((?![^)]*allowed_classes[\'"]?\s*=>\s*false)/',
            'message' => 'unserialize() can lead to PHP object injection (RCE). Use json_decode() or specify allowed_classes.',
            'severity' => 'error',
            'category' => 'unserialize',
        ],
        'exec' => [
            'pattern' => '/\b(?:exec|shell_exec|system|passthru|popen|proc_open)\s*\(/',
            'message' => 'Shell execution function — verify input is sanitized with escapeshellarg()',
            'severity' => 'error',
            'category' => 'command-injection',
        ],
        // Match the PHP backtick operator (shell_exec shorthand) at statement level only.
        // Avoid matching backticks inside double-quoted strings (SQL identifier quotes).
        // Heuristic: backticks must not be preceded by a quote earlier on the same line.
        'backtick' => [
            'pattern' => '/^(?:[^"\']|"[^"]*"|\'[^\']*\')*?`[^`]*\$[^`]*`/',
            'message' => 'Backtick operator (shell exec) with variable interpolation — potential command injection',
            'severity' => 'error',
            'category' => 'command-injection',
        ],
        'preg-e-modifier' => [
            'pattern' => '/preg_replace\s*\(\s*[\'"]\/.*\/e[\'"]\s*,/',
            'message' => 'preg_replace /e modifier allows code execution — use preg_replace_callback()',
            'severity' => 'error',
            'category' => 'eval',
        ],
        'assert-string' => [
            'pattern' => '/\bassert\s*\(\s*[\'"]/',
            'message' => 'assert() with string argument allows code execution',
            'severity' => 'error',
            'category' => 'eval',
        ],
    ];

    /**
     * Patterns that need context to determine severity.
     * Flagged as warnings unless additional context confirms the issue.
     */
    private const CONTEXTUAL_PATTERNS = [
        'raw-sql-concat' => [
            'pattern' => '/(?:->(?:getData|getDataRow|insertData|updateData|deleteData|query)\s*\(|(?:mysql_query|mysqli_query)\s*\().*\$/',
            'message' => 'SQL query with variable interpolation — use parameterized queries or QueryBuilder',
            'severity' => 'warning',
            'category' => 'sql-injection',
        ],
        'raw-sql-string-concat' => [
            'pattern' => '/(?:SELECT|INSERT|UPDATE|DELETE|FROM|WHERE)\s.*\.\s*\$/',
            'message' => 'SQL string concatenation with variable — potential SQL injection',
            'severity' => 'warning',
            'category' => 'sql-injection',
        ],
        'echo-get-input' => [
            'pattern' => '/echo\s+.*get_input\s*\(/',
            'message' => 'Echoing user input without escaping — potential XSS',
            'severity' => 'warning',
            'category' => 'xss',
        ],
        // Only flag echo of get_input() or $vars['...'] in views — these are user data
        // without an obvious safe-source assignment. Generic `echo $var` is too noisy
        // because the standard Elgg pattern is $content = elgg_view(...); echo $content;
        'echo-user-input-in-view' => [
            'pattern' => '/echo\s+(?:get_input\s*\(|\$vars\[)/',
            'message' => 'Echoing user input directly — verify it is escaped (use elgg_view_input(), elgg_format_element(), or htmlspecialchars())',
            'severity' => 'warning',
            'category' => 'xss',
            'context' => 'views',
        ],
        'hardcoded-password' => [
            'pattern' => '/(?:password|passwd|secret|api_key|apikey|token)\s*=\s*[\'"][^\'"]{4,}[\'"]/i',
            'message' => 'Possible hardcoded credential — use configuration or environment variables',
            'severity' => 'warning',
            'category' => 'hardcoded-credentials',
        ],
        'md5-hash' => [
            'pattern' => '/\bmd5\s*\(/',
            'message' => 'md5() is cryptographically weak — use password_hash() for passwords, hash("sha256",...) for integrity',
            'severity' => 'warning',
            'category' => 'deprecated-crypto',
        ],
        'sha1-hash' => [
            'pattern' => '/\bsha1\s*\(/',
            'message' => 'sha1() is cryptographically weak — use hash("sha256",...) or password_hash()',
            'severity' => 'warning',
            'category' => 'deprecated-crypto',
        ],
        'file-get-contents-url' => [
            'pattern' => '/file_get_contents\s*\(\s*\$/',
            'message' => 'file_get_contents() with variable path — potential SSRF or path traversal',
            'severity' => 'warning',
            'category' => 'insecure-file-ops',
        ],
        'include-variable' => [
            'pattern' => '/(?:include|require|include_once|require_once)\s*\(\s*\$/',
            'message' => 'Dynamic include with variable — potential local file inclusion (LFI)',
            'severity' => 'warning',
            'category' => 'insecure-file-ops',
        ],
        'move-uploaded-file' => [
            'pattern' => '/move_uploaded_file\s*\(/',
            'message' => 'File upload handling — verify filename is sanitized and destination is restricted',
            'severity' => 'warning',
            'category' => 'insecure-file-ops',
        ],
        'header-redirect' => [
            'pattern' => '/header\s*\(\s*[\'"]Location:\s*[\'"]?\s*\.\s*\$/',
            'message' => 'Open redirect via header() — validate URL against allowlist',
            'severity' => 'warning',
            'category' => 'xss',
        ],
        'extract' => [
            'pattern' => '/\bextract\s*\(\s*\$/',
            'message' => 'extract() with user-controlled data can overwrite variables — use explicit assignment',
            'severity' => 'warning',
            'category' => 'eval',
        ],
        // FC-ALL-02: a raw SQL REPLACE() in an UPDATE over stored data corrupts
        // PHP-serialized s:<len>: length prefixes (the byte count no longer matches
        // the string). Migrating serialized blobs (or serialize()->json_encode())
        // MUST go per-row (unserialize -> str_replace -> serialize) via DBAL, or
        // ship an Elgg\Upgrade\Batch — never a blanket SQL REPLACE.
        'serialized-sql-replace' => [
            'pattern' => '/UPDATE\b[^;]*\bREPLACE\s*\(/i',
            'message' => 'Raw SQL REPLACE() over stored data corrupts PHP-serialized s:<len>: length prefixes. Migrate per-row (unserialize -> str_replace -> serialize) via DBAL, or ship an Elgg\\Upgrade\\Batch.',
            'severity' => 'warning',
            'category' => 'data-migration',
        ],
    ];

    /**
     * Elgg-specific security patterns.
     */
    private const ELGG_PATTERNS = [
        'missing-csrf-check' => [
            'pattern' => '/function\s+\w+_action\b/',
            'message' => 'Action handler function — verify CSRF token is validated (Elgg does this automatically for registered actions)',
            'severity' => 'info',
            'category' => 'csrf',
        ],
        'direct-db-prefix' => [
            'pattern' => '/elgg_get_config\s*\(\s*[\'"]dbprefix[\'"]\s*\)/',
            'message' => 'Direct DB prefix access — use QueryBuilder or elgg()->db->getTablePrefix() for parameterized queries',
            'severity' => 'warning',
            'category' => 'sql-injection',
        ],
        'raw-get-input-in-sql' => [
            'pattern' => '/get_input\s*\(.*(?:SELECT|INSERT|UPDATE|DELETE|WHERE)/s',
            'message' => 'get_input() value used directly in SQL — always parameterize',
            'severity' => 'error',
            'category' => 'sql-injection',
        ],
    ];

    /**
     * Run the security sweep on a plugin directory.
     *
     * @return SecurityResult
     */
    public function scan(string $pluginPath): SecurityResult
    {
        $violations = [];

        foreach ($this->phpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $content = file_get_contents($file);
            if ($content === false) continue;

            $lines = explode("\n", $content);

            // Critical patterns — always check
            foreach (self::CRITICAL_PATTERNS as $id => $check) {
                $violations = array_merge(
                    $violations,
                    $this->scanLines($lines, $relativePath, $check),
                );
            }

            // Contextual patterns — check with directory awareness
            foreach (self::CONTEXTUAL_PATTERNS as $id => $check) {
                // Skip view-only checks for non-view files
                if (isset($check['context']) && $check['context'] === 'views') {
                    if (!str_contains($relativePath, 'views/')) {
                        continue;
                    }
                }

                $violations = array_merge(
                    $violations,
                    $this->scanLines($lines, $relativePath, $check),
                );
            }

            // Elgg-specific patterns
            foreach (self::ELGG_PATTERNS as $id => $check) {
                $violations = array_merge(
                    $violations,
                    $this->scanLines($lines, $relativePath, $check),
                );
            }
        }

        // External taint analysis (semgrep) — graceful skip if not installed
        $semgrep = $this->semgrep ?? new SemgrepRunner();
        $violations = array_merge($violations, $semgrep->scan($pluginPath));

        // Deduplicate by file+line+category
        $violations = $this->deduplicate($violations);

        $errors = array_filter($violations, fn(Violation $v) => $v->severity === 'error');

        return new SecurityResult(
            violations: $violations,
            passed: count($errors) === 0,
            summary: $this->buildSummary($violations),
        );
    }

    /**
     * @return array<Violation>
     */
    private function scanLines(array $lines, string $file, array $check): array
    {
        $violations = [];

        foreach ($lines as $lineNum => $line) {
            // Skip comments
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (preg_match($check['pattern'], $line)) {
                // Contextual filter: for sql-injection patterns, check if the
                // surrounding lines (±5) contain named parameter syntax (e.g. :foo).
                // Parameterized queries with bound params are safe even when the
                // query string interpolates $dbprefix.
                if ($check['category'] === 'sql-injection' && $this->looksParameterized($lines, $lineNum)) {
                    continue;
                }

                $violations[] = new Violation(
                    file: $file,
                    line: $lineNum + 1,
                    severity: $check['severity'],
                    message: $check['message'],
                    code: trim($line),
                    category: $check['category'],
                );
            }
        }

        return $violations;
    }

    /**
     * Check whether a SQL query at $lineNum uses safe parameterization.
     *
     * Looks at the matched line and ±10 surrounding lines for evidence of:
     *   1. Named parameter binding: `:identifier` syntax (PDO/Doctrine)
     *   2. Elgg QueryBuilder usage: `Select::|Insert::|Update::|Delete::fromTable`
     *      or `$qb->compare()|$qb->param()|$qb->expr()` calls
     *
     * Both patterns indicate the query is parameterized at a layer that
     * the line-level regex cannot see, so the warning is a false positive.
     */
    private function looksParameterized(array $lines, int $lineNum): bool
    {
        $start = max(0, $lineNum - 10);
        $end = min(count($lines) - 1, $lineNum + 10);
        $context = '';
        for ($i = $start; $i <= $end; $i++) {
            $context .= $lines[$i] . "\n";
        }

        // Named parameter syntax: :word, but NOT :: (scope resolution) or :// (URL)
        if (preg_match('/(?<![:\w])(?<!:)(?<![:])\:[a-zA-Z_][a-zA-Z0-9_]*(?![:\w\/])/', $context)) {
            return true;
        }

        // Elgg QueryBuilder usage indicates parameterization at the QB level
        if (preg_match('/\b(?:Select|Insert|Update|Delete)::(?:fromTable|table|into)\s*\(/', $context)) {
            return true;
        }
        if (preg_match('/\$\w+->(?:compare|param|expr|setParameter|where|andWhere|orWhere)\s*\(/', $context)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<Violation> $violations
     * @return array<Violation>
     */
    private function deduplicate(array $violations): array
    {
        $seen = [];
        $unique = [];

        foreach ($violations as $v) {
            $key = "{$v->file}:{$v->line}:{$v->category}";
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $v;
            }
        }

        return $unique;
    }

    /**
     * @param array<Violation> $violations
     */
    private function buildSummary(array $violations): string
    {
        if (empty($violations)) {
            return 'No security issues found';
        }

        $bySeverity = ['error' => 0, 'warning' => 0, 'info' => 0];
        $byCategory = [];

        foreach ($violations as $v) {
            $bySeverity[$v->severity] = ($bySeverity[$v->severity] ?? 0) + 1;
            $byCategory[$v->category] = ($byCategory[$v->category] ?? 0) + 1;
        }

        $parts = [];
        if ($bySeverity['error'] > 0) {
            $parts[] = "{$bySeverity['error']} error(s)";
        }
        if ($bySeverity['warning'] > 0) {
            $parts[] = "{$bySeverity['warning']} warning(s)";
        }
        if ($bySeverity['info'] > 0) {
            $parts[] = "{$bySeverity['info']} info";
        }

        $categoryList = [];
        foreach ($byCategory as $cat => $count) {
            $categoryList[] = "{$cat}: {$count}";
        }

        return implode(', ', $parts) . ' [' . implode(', ', $categoryList) . ']';
    }

    /**
     * @return \Generator<string>
     */
    private function phpFiles(string $dir): \Generator
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') continue;
            $path = $file->getPathname();
            // Skip third-party code and nested plugin installations
            if (str_contains($path, '/vendor/') || str_contains($path, '/vendors/') || str_contains($path, '/mod/')) {
                continue;
            }
            yield $path;
        }
    }

    private function relativePath(string $base, string $path): string
    {
        $base = rtrim($base, '/') . '/';
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }
        return $path;
    }
}
