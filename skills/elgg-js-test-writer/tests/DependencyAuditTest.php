<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\Advisory;
use ElggMigrate\DependencyAudit;
use PHPUnit\Framework\TestCase;

final class DependencyAuditTest extends TestCase
{
    public function testReturnsCleanResultWhenNoLockFileFound(): void
    {
        $dir = $this->makeWorkDir();

        try {
            $audit = new DependencyAudit($this->stubRunner(['exit' => 0, 'stdout' => '', 'stderr' => '']));
            $result = $audit->audit($dir);

            $this->assertTrue($result->passed);
            $this->assertEmpty($result->advisories);
            $this->assertSame('', $result->source);
            $this->assertStringContainsString('No composer.lock found', $result->summary);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testParsesAdvisoriesFromComposerOutput(): void
    {
        $dir = $this->makeWorkDir();
        file_put_contents($dir . '/composer.lock', '{"packages":[]}');

        $auditOutput = json_encode([
            'advisories' => [
                'symfony/http-foundation' => [
                    [
                        'advisoryId' => 'PKSA-1234-5678',
                        'packageName' => 'symfony/http-foundation',
                        'affectedVersions' => '<5.4.20',
                        'title' => 'CSRF token fixation in Symfony HttpFoundation',
                        'cve' => 'CVE-2023-12345',
                        'link' => 'https://example.com/advisory',
                        'reportedAt' => '2023-01-15T00:00:00+00:00',
                        'severity' => 'high',
                    ],
                ],
            ],
            'abandoned' => [],
        ]);

        try {
            $audit = new DependencyAudit($this->stubRunner([
                'exit' => 1, // composer audit returns nonzero when advisories found
                'stdout' => $auditOutput,
                'stderr' => '',
            ]));
            $result = $audit->audit($dir);

            $this->assertFalse($result->passed);
            $this->assertCount(1, $result->advisories);

            $advisory = $result->advisories[0];
            $this->assertInstanceOf(Advisory::class, $advisory);
            $this->assertSame('symfony/http-foundation', $advisory->packageName);
            $this->assertSame('CVE-2023-12345', $advisory->cve);
            $this->assertSame('high', $advisory->severity);

            $this->assertCount(1, $result->critical());
            $this->assertEmpty($result->nonCritical());
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testSeparatesCriticalFromNonCritical(): void
    {
        $dir = $this->makeWorkDir();
        file_put_contents($dir . '/composer.lock', '{}');

        $auditOutput = json_encode([
            'advisories' => [
                'pkg/critical' => [['severity' => 'critical', 'title' => 'A', 'cve' => 'CVE-1']],
                'pkg/high' => [['severity' => 'high', 'title' => 'B', 'cve' => 'CVE-2']],
                'pkg/medium' => [['severity' => 'medium', 'title' => 'C', 'cve' => 'CVE-3']],
                'pkg/low' => [['severity' => 'low', 'title' => 'D', 'cve' => 'CVE-4']],
            ],
            'abandoned' => [],
        ]);

        try {
            $audit = new DependencyAudit($this->stubRunner([
                'exit' => 1, 'stdout' => $auditOutput, 'stderr' => '',
            ]));
            $result = $audit->audit($dir);

            $this->assertCount(4, $result->advisories);
            $this->assertCount(2, $result->critical()); // critical + high
            $this->assertCount(2, $result->nonCritical()); // medium + low
            $this->assertFalse($result->passed);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testPassesWhenAllAdvisoriesAreNonCritical(): void
    {
        $dir = $this->makeWorkDir();
        file_put_contents($dir . '/composer.lock', '{}');

        $auditOutput = json_encode([
            'advisories' => [
                'pkg/medium' => [['severity' => 'medium', 'title' => 'C', 'cve' => 'CVE-3']],
            ],
            'abandoned' => [],
        ]);

        try {
            $audit = new DependencyAudit($this->stubRunner([
                'exit' => 1, 'stdout' => $auditOutput, 'stderr' => '',
            ]));
            $result = $audit->audit($dir);

            $this->assertTrue($result->passed); // medium-only passes the gate
            $this->assertCount(1, $result->advisories);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testHandlesAbandonedPackages(): void
    {
        $dir = $this->makeWorkDir();
        file_put_contents($dir . '/composer.lock', '{}');

        $auditOutput = json_encode([
            'advisories' => [],
            'abandoned' => [
                'old/package' => 'new/package',
                'unmaintained/lib' => null,
            ],
        ]);

        try {
            $audit = new DependencyAudit($this->stubRunner([
                'exit' => 1, 'stdout' => $auditOutput, 'stderr' => '',
            ]));
            $result = $audit->audit($dir);

            $this->assertTrue($result->passed); // abandoned packages don't fail the gate
            $this->assertCount(2, $result->abandoned);
            $this->assertStringContainsString('abandoned', $result->summary);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testParsesJsonWithLeadingComposerOutput(): void
    {
        // composer audit sometimes prefixes JSON with text
        $dir = $this->makeWorkDir();
        file_put_contents($dir . '/composer.lock', '{}');

        $output = "Found 1 security vulnerability advisory\n"
            . json_encode([
                'advisories' => [
                    'pkg/x' => [['severity' => 'high', 'title' => 'X', 'cve' => 'CVE-X']],
                ],
                'abandoned' => [],
            ]);

        try {
            $audit = new DependencyAudit($this->stubRunner([
                'exit' => 1, 'stdout' => $output, 'stderr' => '',
            ]));
            $result = $audit->audit($dir);

            $this->assertCount(1, $result->advisories);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testNoPackagesProducesCleanMessage(): void
    {
        $dir = $this->makeWorkDir();
        file_put_contents($dir . '/composer.lock', '{}');

        try {
            $audit = new DependencyAudit($this->stubRunner([
                'exit' => 0,
                'stdout' => 'No packages - skipping audit.',
                'stderr' => '',
            ]));
            $result = $audit->audit($dir);

            $this->assertTrue($result->passed);
            $this->assertStringContainsString('No packages locked', $result->summary);
            $this->assertStringNotContainsString('failed', $result->summary);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testHandlesComposerFailureGracefully(): void
    {
        $dir = $this->makeWorkDir();
        file_put_contents($dir . '/composer.lock', '{}');

        try {
            $audit = new DependencyAudit($this->stubRunner([
                'exit' => 127,
                'stdout' => '',
                'stderr' => 'composer: command not found',
            ]));
            $result = $audit->audit($dir);

            $this->assertTrue($result->passed); // failure doesn't block migration
            $this->assertStringContainsString('composer audit failed', $result->summary);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFindsParentLockFile(): void
    {
        // Plugin nested under elgg-root/mod/plugin/, parent has composer.lock
        $root = $this->makeWorkDir();
        $pluginDir = $root . '/mod/myplugin';
        mkdir($pluginDir, 0755, true);
        file_put_contents($root . '/composer.lock', '{}');

        $captured = null;
        $runner = function (string $cmd) use (&$captured): array {
            $captured = $cmd;
            return ['exit' => 0, 'stdout' => '{"advisories":{},"abandoned":{}}', 'stderr' => ''];
        };

        try {
            $audit = new DependencyAudit($runner);
            $result = $audit->audit($pluginDir);

            $this->assertSame($root . '/composer.lock', $result->source);
            $this->assertStringContainsString($root, $captured);
        } finally {
            $this->removeDir($root);
        }
    }

    public function testNoAdvisoriesProducesCleanSummary(): void
    {
        $dir = $this->makeWorkDir();
        file_put_contents($dir . '/composer.lock', '{}');

        try {
            $audit = new DependencyAudit($this->stubRunner([
                'exit' => 0,
                'stdout' => '{"advisories":{},"abandoned":{}}',
                'stderr' => '',
            ]));
            $result = $audit->audit($dir);

            $this->assertTrue($result->passed);
            $this->assertEmpty($result->advisories);
            $this->assertStringContainsString('No advisories', $result->summary);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Helpers ---

    private function stubRunner(array $response): callable
    {
        return fn(string $cmd): array => $response;
    }

    private function makeWorkDir(): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-audit-' . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
