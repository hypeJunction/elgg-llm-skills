<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression lock for bin/scan-frontend-residue.sh.
 *
 * Each of the recurring runtime-fatal bug classes seen in real-world
 * migrations (signature-incompat, viewpage/viewmodule-null-title,
 * css-view-orphaned, legacy-language-file, removed-instance-method) has a
 * minimal reproducing fixture under tests/fixtures/scan-frontend-residue/.
 * The scanner must keep flagging each one, and must stay silent on the
 * clean fixture. Without this, a future edit could silently stop catching
 * a whole class of fatals while still exiting 0.
 */
final class ScanFrontendResidueTest extends TestCase
{
    private string $scanner;
    private string $fixtures;

    protected function setUp(): void
    {
        $this->scanner = \dirname(__DIR__) . '/bin/scan-frontend-residue.sh';
        $this->fixtures = __DIR__ . '/fixtures/scan-frontend-residue';
        $this->assertFileExists($this->scanner);
    }

    /**
     * @return array{0:int,1:string} [exitCode, combinedOutput]
     */
    private function scan(string $case): array
    {
        $dir = $this->fixtures . '/' . $case;
        $this->assertDirectoryExists($dir, "missing fixture: $case");
        $cmd = 'bash ' . \escapeshellarg($this->scanner) . ' ' . \escapeshellarg($dir) . ' 2>&1';
        $output = [];
        $code = 0;
        \exec($cmd, $output, $code);
        return [$code, \implode("\n", $output)];
    }

    /**
     * @return array<string,string> case => expected tag
     */
    public static function residueCases(): array
    {
        return [
            'signature-incompat (bug-007/013)' => ['signature-incompat', '[signature-incompat]'],
            'viewpage-null-title'              => ['null-title', '[viewpage-null-title]'],
            'viewmodule-null-title'            => ['null-title', '[viewmodule-null-title]'],
            'css-view-orphaned (bug-024)'      => ['css-orphan', '[css-view-orphaned]'],
            'legacy-language-file'             => ['legacy-language', '[legacy-language-file]'],
            'removed-instance-method'          => ['removed-instance-method', '[removed-instance-method]'],
        ];
    }

    /**
     * @dataProvider residueCases
     */
    public function testScannerFlagsResidue(string $case, string $expectedTag): void
    {
        [$code, $output] = $this->scan($case);
        $this->assertStringContainsString(
            $expectedTag,
            $output,
            "scanner did not emit $expectedTag for fixture '$case'.\n$output"
        );
        $this->assertSame(
            1,
            $code,
            "scanner should exit 1 (residue found) for fixture '$case', got $code.\n$output"
        );
    }

    public function testCleanPluginIsSilent(): void
    {
        [$code, $output] = $this->scan('clean');
        $this->assertSame(0, $code, "clean fixture must exit 0.\n$output");
        $this->assertStringContainsString('crit=0', $output, $output);
    }

    public function testCompatibleSignatureIsNotFlagged(): void
    {
        [, $output] = $this->scan('signature-clean');
        $this->assertStringNotContainsString(
            '[signature-incompat]',
            $output,
            "a signature matching Elgg 7 core must not be flagged.\n$output"
        );
    }
}
