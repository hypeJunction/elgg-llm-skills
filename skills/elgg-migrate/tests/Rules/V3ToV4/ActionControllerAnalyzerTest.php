<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\ActionControllerAnalyzer;
use PHPUnit\Framework\TestCase;

final class ActionControllerAnalyzerTest extends TestCase
{
    private ActionControllerAnalyzer $rule;

    protected function setUp(): void
    {
        $this->rule = new ActionControllerAnalyzer();
    }

    public function testRuleMetadata(): void
    {
        $this->assertSame('action-controller-analyzer', $this->rule->getId());
        $this->assertFalse($this->rule->canAutomate());
    }

    public function testSimpleActionProducesNoFindings(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/action-controller-analyzer/simple-plugin';
        $analysis = $this->rule->analyze($dir);

        $this->assertFalse(
            $analysis->applicable,
            'A trivial action file (< 15 LOC) should not produce findings',
        );
        $this->assertCount(0, $analysis->findings);
    }

    public function testComplexActionIsDetectedAsCandidate(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/action-controller-analyzer/complex-plugin';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue(
            $analysis->applicable,
            'A complex action file (> 30 LOC with multiple branches and loops) should be flagged',
        );
        $this->assertGreaterThanOrEqual(1, count($analysis->findings));
    }

    public function testComplexActionFindingDescribesMetrics(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/action-controller-analyzer/complex-plugin';
        $analysis = $this->rule->analyze($dir);

        $this->assertGreaterThanOrEqual(1, count($analysis->findings));

        $finding = $analysis->findings[0];

        $this->assertStringContainsString('Action candidate for Controller extraction', $finding->description);
        $this->assertStringContainsString('lines', $finding->description);
        $this->assertStringContainsString('branches', $finding->description);
        $this->assertStringContainsString('loops', $finding->description);
    }

    public function testComplexActionFindingContainsTargetClassPath(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/action-controller-analyzer/complex-plugin';
        $analysis = $this->rule->analyze($dir);

        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);
        $allDescriptions = implode("\n", $descriptions);

        // Should suggest a 'classes/.../Actions/...' path
        $this->assertStringContainsString('classes/', $allDescriptions);
        $this->assertStringContainsString('Actions/', $allDescriptions);
    }

    public function testActionRegisteredInElggPluginPhpIsDetected(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/action-controller-analyzer/complex-plugin';
        $analysis = $this->rule->analyze($dir);

        // The complex-plugin fixture registers 'myplugin/save' in elgg-plugin.php
        // and has a complex action file for it — it must be picked up
        $files = array_map(fn($f) => $f->file, $analysis->findings);
        $found = array_filter($files, fn($f) => str_contains($f, 'myplugin/save'));
        $this->assertNotEmpty($found, 'Action registered in elgg-plugin.php actions array should be detected');
    }

    public function testPluginWithNoActionsIsNotApplicable(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-aca-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/elgg-plugin.php', "<?php\nreturn [];\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
            $this->assertCount(0, $analysis->findings);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIsNoOp(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/action-controller-analyzer/complex-plugin';
        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertEmpty($result->changes, 'apply() must not modify any files');
    }

    public function testStartPhpActionRegistrationsAreDetected(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-aca-start-' . uniqid();
        mkdir($workDir . '/actions/myplugin', 0755, true);

        file_put_contents($workDir . '/start.php', <<<'PHP'
<?php
elgg_register_event_handler('init', 'system', function () {
    elgg_register_action('myplugin/complex', __DIR__ . '/actions/myplugin/complex.php');
});
PHP);

        // Write a complex action file
        $actionLines = [];
        $actionLines[] = '<?php';
        $actionLines[] = '$guid = (int) get_input(\'guid\');';
        for ($i = 0; $i < 40; $i++) {
            $actionLines[] = "\$v{$i} = get_input('field{$i}');";
        }
        $actionLines[] = 'if (!$guid) { return elgg_error_response(\'err\'); }';
        $actionLines[] = 'if (empty($v0)) { return elgg_error_response(\'err\'); }';
        $actionLines[] = 'if (empty($v1)) { return elgg_error_response(\'err\'); }';
        $actionLines[] = 'if (empty($v2)) { return elgg_error_response(\'err\'); }';
        $actionLines[] = 'foreach ([$v0, $v1, $v2] as $v) { echo $v; }';
        $actionLines[] = 'foreach ([$v3, $v4] as $v) { echo $v; }';
        $actionLines[] = 'return elgg_ok_response();';

        file_put_contents($workDir . '/actions/myplugin/complex.php', implode("\n", $actionLines));

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertTrue($analysis->applicable, 'start.php registered action should be analyzed');
            $this->assertGreaterThanOrEqual(1, count($analysis->findings));
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
