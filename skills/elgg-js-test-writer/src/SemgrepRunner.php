<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Wrapper around the semgrep CLI for taint-aware security scanning.
 *
 * Runs the OWASP Top Ten and PHP rulesets against a plugin directory and
 * returns findings as Violation objects so they merge cleanly with the
 * regex-based SecuritySweep results.
 *
 * Severity mapping:
 *   semgrep ERROR   -> error   (fails the gate)
 *   semgrep WARNING -> warning (advisory)
 *   semgrep INFO    -> warning (advisory)
 */
final class SemgrepRunner
{
    /**
     * @param array<string> $configs Semgrep ruleset identifiers.
     */
    public function __construct(
        private readonly array $configs = ['p/php', 'p/owasp-top-ten'],
        private readonly string $binary = 'semgrep',
        private readonly int $timeoutSeconds = 300,
    ) {}

    public function isAvailable(): bool
    {
        $output = [];
        $code = 0;
        @exec(escapeshellcmd($this->binary) . ' --version 2>/dev/null', $output, $code);
        return $code === 0;
    }

    /**
     * Run semgrep against a plugin directory.
     *
     * Returns an empty array if semgrep is not installed — callers should
     * treat absence as a degraded mode, not a hard failure.
     *
     * @return array<Violation>
     */
    public function scan(string $pluginPath): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $cmd = escapeshellcmd($this->binary);
        foreach ($this->configs as $config) {
            $cmd .= ' --config ' . escapeshellarg($config);
        }
        $cmd .= ' --json --quiet --no-git-ignore --metrics=off';
        $cmd .= ' --timeout=' . (int)$this->timeoutSeconds;
        $cmd .= ' ' . escapeshellarg($pluginPath);
        $cmd .= ' 2>/dev/null';

        $output = shell_exec($cmd);
        if (!is_string($output) || $output === '') {
            return [];
        }

        return $this->parseJson($output, $pluginPath);
    }

    /**
     * Parse semgrep JSON output into Violation objects.
     *
     * Exposed for unit testing — callers normally use scan().
     *
     * @return array<Violation>
     */
    public function parseJson(string $json, string $pluginPath): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return [];
        }

        $base = rtrim($pluginPath, '/') . '/';
        $violations = [];

        foreach ($data['results'] as $r) {
            if (!is_array($r)) continue;

            $path = (string)($r['path'] ?? '');
            if ($path !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base));
            }

            $semgrepSeverity = strtoupper((string)($r['extra']['severity'] ?? 'INFO'));
            $severity = $semgrepSeverity === 'ERROR' ? 'error' : 'warning';

            $checkId = (string)($r['check_id'] ?? 'semgrep');
            $dotPos = strrpos($checkId, '.');
            $shortId = $dotPos !== false ? substr($checkId, $dotPos + 1) : $checkId;

            $message = (string)($r['extra']['message'] ?? '');
            $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

            $violations[] = new Violation(
                file: $path,
                line: (int)($r['start']['line'] ?? 0),
                severity: $severity,
                message: "[semgrep:{$shortId}] {$message}",
                code: trim((string)($r['extra']['lines'] ?? '')),
                category: $this->categorize($checkId, $message),
            );
        }

        return $violations;
    }

    /**
     * Map a semgrep rule id onto one of SecuritySweep's existing categories
     * so the merged report stays consistent.
     */
    private function categorize(string $checkId, string $message): string
    {
        $haystack = strtolower($checkId . ' ' . $message);

        return match (true) {
            str_contains($haystack, 'sql') => 'sql-injection',
            str_contains($haystack, 'xss') || str_contains($haystack, 'cross-site') => 'xss',
            str_contains($haystack, 'command') || str_contains($haystack, 'shell') || str_contains($haystack, 'exec') => 'command-injection',
            str_contains($haystack, 'unserialize') || str_contains($haystack, 'deserialization') => 'unserialize',
            str_contains($haystack, 'eval') => 'eval',
            str_contains($haystack, 'crypt') || str_contains($haystack, 'md5') || str_contains($haystack, 'sha1') || str_contains($haystack, 'hash') => 'deprecated-crypto',
            str_contains($haystack, 'lfi') || str_contains($haystack, 'path') || str_contains($haystack, 'file') || str_contains($haystack, 'ssrf') => 'insecure-file-ops',
            str_contains($haystack, 'csrf') => 'csrf',
            str_contains($haystack, 'secret') || str_contains($haystack, 'password') || str_contains($haystack, 'credential') || str_contains($haystack, 'token') => 'hardcoded-credentials',
            default => 'semgrep',
        };
    }
}
