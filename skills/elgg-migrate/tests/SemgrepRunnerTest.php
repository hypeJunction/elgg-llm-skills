<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\SemgrepRunner;
use ElggMigrate\Violation;
use PHPUnit\Framework\TestCase;

final class SemgrepRunnerTest extends TestCase
{
    private SemgrepRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new SemgrepRunner();
    }

    public function testParsesEmptyResults(): void
    {
        $violations = $this->runner->parseJson('{"results": []}', '/tmp/plugin');
        $this->assertSame([], $violations);
    }

    public function testParsesMalformedJsonGracefully(): void
    {
        $this->assertSame([], $this->runner->parseJson('not json', '/tmp/plugin'));
        $this->assertSame([], $this->runner->parseJson('{}', '/tmp/plugin'));
        $this->assertSame([], $this->runner->parseJson('{"results": "wrong"}', '/tmp/plugin'));
    }

    public function testMapsErrorSeverityToError(): void
    {
        $json = json_encode([
            'results' => [[
                'check_id' => 'php.lang.security.injection.tainted-sql-string',
                'path' => '/tmp/plugin/classes/Repo.php',
                'start' => ['line' => 42],
                'extra' => [
                    'severity' => 'ERROR',
                    'message' => 'SQL injection via tainted input',
                    'lines' => '$db->query("SELECT * FROM x WHERE id = " . $id);',
                ],
            ]],
        ]);

        $violations = $this->runner->parseJson($json, '/tmp/plugin');

        $this->assertCount(1, $violations);
        $v = $violations[0];
        $this->assertInstanceOf(Violation::class, $v);
        $this->assertSame('error', $v->severity);
        $this->assertSame('sql-injection', $v->category);
        $this->assertSame('classes/Repo.php', $v->file);
        $this->assertSame(42, $v->line);
        $this->assertStringContainsString('semgrep:tainted-sql-string', $v->message);
    }

    public function testMapsWarningAndInfoToWarning(): void
    {
        $json = json_encode([
            'results' => [
                [
                    'check_id' => 'php.lang.crypto.md5-used',
                    'path' => '/tmp/plugin/a.php',
                    'start' => ['line' => 1],
                    'extra' => ['severity' => 'WARNING', 'message' => 'md5 weak hash', 'lines' => 'md5($x);'],
                ],
                [
                    'check_id' => 'php.lang.style.foo',
                    'path' => '/tmp/plugin/b.php',
                    'start' => ['line' => 2],
                    'extra' => ['severity' => 'INFO', 'message' => 'style note', 'lines' => ''],
                ],
            ],
        ]);

        $violations = $this->runner->parseJson($json, '/tmp/plugin');

        $this->assertCount(2, $violations);
        $this->assertSame('warning', $violations[0]->severity);
        $this->assertSame('warning', $violations[1]->severity);
        $this->assertSame('deprecated-crypto', $violations[0]->category);
    }

    public function testCategorizesByRuleId(): void
    {
        $cases = [
            ['php.lang.security.xss.echoed-tainted', 'xss'],
            ['php.lang.security.injection.command-injection', 'command-injection'],
            ['php.lang.security.unserialize-use', 'unserialize'],
            ['php.lang.security.eval-use', 'eval'],
            ['php.lang.security.path-traversal', 'insecure-file-ops'],
            ['generic.secrets.aws-token', 'hardcoded-credentials'],
            ['php.lang.security.csrf.missing-token', 'csrf'],
            ['something.unknown', 'semgrep'],
        ];

        foreach ($cases as [$checkId, $expectedCategory]) {
            $json = json_encode([
                'results' => [[
                    'check_id' => $checkId,
                    'path' => '/tmp/plugin/x.php',
                    'start' => ['line' => 1],
                    'extra' => ['severity' => 'WARNING', 'message' => '', 'lines' => ''],
                ]],
            ]);

            $violations = $this->runner->parseJson($json, '/tmp/plugin');
            $this->assertSame($expectedCategory, $violations[0]->category, "Rule {$checkId} should map to {$expectedCategory}");
        }
    }

    public function testStripsPluginPathPrefix(): void
    {
        $json = json_encode([
            'results' => [[
                'check_id' => 'x.y.z',
                'path' => '/tmp/plugin/classes/Deep/File.php',
                'start' => ['line' => 5],
                'extra' => ['severity' => 'ERROR', 'message' => 'm', 'lines' => ''],
            ]],
        ]);

        $violations = $this->runner->parseJson($json, '/tmp/plugin');
        $this->assertSame('classes/Deep/File.php', $violations[0]->file);
    }

    public function testIsAvailableReturnsFalseForMissingBinary(): void
    {
        $runner = new SemgrepRunner(binary: '/nonexistent/semgrep');
        $this->assertFalse($runner->isAvailable());
    }

    public function testScanReturnsEmptyWhenBinaryMissing(): void
    {
        $runner = new SemgrepRunner(binary: '/nonexistent/semgrep');
        $this->assertSame([], $runner->scan('/tmp'));
    }
}
