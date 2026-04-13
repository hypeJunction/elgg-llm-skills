<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Runs composer audit against a plugin's composer.lock to find CVE-rated
 * vulnerabilities in its dependencies.
 *
 * Strategy:
 * 1. If the plugin has its own composer.lock, audit that.
 * 2. Otherwise, look for a parent Elgg installation's composer.lock (walk up
 *    the directory tree, max 4 levels).
 * 3. If neither exists, return a clean result with a note that no audit was performed.
 *
 * The command runner is injectable so tests don't need network access.
 */
final class DependencyAudit
{
    /**
     * @var callable(string): array{exit: int, stdout: string, stderr: string}
     */
    private $runner;

    /**
     * @param (callable(string): array{exit: int, stdout: string, stderr: string})|null $runner
     *        Callable receiving a shell command, returning {exit, stdout, stderr}.
     *        Defaults to a real shell runner via proc_open.
     */
    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner ?? $this->defaultRunner();
    }

    /**
     * Run dependency audit against a plugin.
     */
    public function audit(string $pluginPath): AuditResult
    {
        $lockFile = $this->findLockFile($pluginPath);

        if ($lockFile === null) {
            return new AuditResult(
                advisories: [],
                abandoned: [],
                passed: true,
                source: '',
                summary: 'No composer.lock found — dependency audit skipped',
            );
        }

        $workingDir = dirname($lockFile);
        $cmd = sprintf(
            'composer audit --working-dir=%s --format=json --no-interaction 2>&1',
            escapeshellarg($workingDir),
        );

        $result = ($this->runner)($cmd);

        // composer audit exits non-zero when advisories are found, but JSON is still valid
        $json = $this->parseJson($result['stdout']);
        if ($json === null) {
            // Distinguish "no packages to audit" (clean) from actual failures
            $combined = trim($result['stderr'] ?: $result['stdout']);
            $noPackages = stripos($combined, 'no packages') !== false;

            return new AuditResult(
                advisories: [],
                abandoned: [],
                passed: true,
                source: $lockFile,
                summary: $noPackages
                    ? 'No packages locked — nothing to audit'
                    : sprintf('composer audit failed (exit %d): %s', $result['exit'], $combined),
            );
        }

        $advisories = $this->parseAdvisories($json['advisories'] ?? []);
        $abandoned = array_keys($json['abandoned'] ?? []);

        $critical = array_filter(
            $advisories,
            fn(Advisory $a) => in_array(strtolower($a->severity), ['critical', 'high'], true),
        );

        return new AuditResult(
            advisories: $advisories,
            abandoned: $abandoned,
            passed: count($critical) === 0,
            source: $lockFile,
            summary: $this->buildSummary($advisories, $abandoned),
        );
    }

    /**
     * Locate the composer.lock to audit.
     * Walks up to 4 parent directories looking for an Elgg root.
     */
    private function findLockFile(string $pluginPath): ?string
    {
        $own = $pluginPath . '/composer.lock';
        if (is_file($own)) {
            return $own;
        }

        // Walk up looking for a parent with composer.lock
        // (e.g., the Elgg installation that contains this plugin under mod/)
        $dir = realpath($pluginPath) ?: $pluginPath;
        for ($i = 0; $i < 4; $i++) {
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
            if (is_file($dir . '/composer.lock')) {
                return $dir . '/composer.lock';
            }
        }

        return null;
    }

    /**
     * Parse composer audit JSON output. Composer prefixes the JSON with text
     * sometimes ("No security vulnerability advisories found"), so we extract
     * the JSON object portion.
     */
    private function parseJson(string $output): ?array
    {
        // Find the first { and last } to extract just the JSON portion
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $jsonStr = substr($output, $start, $end - $start + 1);

        try {
            $data = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Convert composer audit's advisory format into Advisory objects.
     *
     * @return array<Advisory>
     */
    private function parseAdvisories(array $advisoriesByPackage): array
    {
        $result = [];

        foreach ($advisoriesByPackage as $packageName => $advisories) {
            if (!is_array($advisories)) continue;

            foreach ($advisories as $a) {
                if (!is_array($a)) continue;

                $result[] = new Advisory(
                    packageName: (string) ($a['packageName'] ?? $packageName),
                    advisoryId: (string) ($a['advisoryId'] ?? ''),
                    title: (string) ($a['title'] ?? 'Untitled advisory'),
                    severity: (string) ($a['severity'] ?? 'unknown'),
                    cve: (string) ($a['cve'] ?? ''),
                    affectedVersions: (string) ($a['affectedVersions'] ?? ''),
                    link: (string) ($a['link'] ?? ''),
                    reportedAt: (string) ($a['reportedAt'] ?? ''),
                );
            }
        }

        return $result;
    }

    /**
     * @param array<Advisory> $advisories
     * @param array<string> $abandoned
     */
    private function buildSummary(array $advisories, array $abandoned): string
    {
        if (empty($advisories) && empty($abandoned)) {
            return 'No advisories or abandoned packages found';
        }

        $parts = [];

        if (!empty($advisories)) {
            $bySeverity = [];
            foreach ($advisories as $a) {
                $sev = strtolower($a->severity);
                $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + 1;
            }

            $sevParts = [];
            foreach (['critical', 'high', 'medium', 'low', 'unknown'] as $sev) {
                if (!empty($bySeverity[$sev])) {
                    $sevParts[] = "{$bySeverity[$sev]} {$sev}";
                }
            }
            $parts[] = count($advisories) . ' advisory(ies) (' . implode(', ', $sevParts) . ')';
        }

        if (!empty($abandoned)) {
            $parts[] = count($abandoned) . ' abandoned package(s)';
        }

        return implode(', ', $parts);
    }

    /**
     * @return callable(string): array{exit: int, stdout: string, stderr: string}
     */
    private function defaultRunner(): callable
    {
        return function (string $cmd): array {
            $descriptors = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                return ['exit' => 127, 'stdout' => '', 'stderr' => 'failed to spawn process'];
            }

            $stdout = stream_get_contents($pipes[1]) ?: '';
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);

            return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
        };
    }
}
