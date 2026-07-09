<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\TestsFirstGate;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the tests-first gate helpers (Iron Law 4), extracted from
 * bin/migrate.php. The load-bearing CLI contract these prove:
 *   - runTestsFirstGate() returns false  => bin/migrate.php exit(7) (refusal)
 *   - runTestsFirstGate() returns true    => apply proceeds
 *   - --no-tests => logTestsBypass() writes the bypass log
 */
final class TestsFirstGateTest extends TestCase
{
    private string $tmp;
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/tfg-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);

        // Snapshot env the gate reads so tests can't leak into each other.
        foreach (['ELGG_MIGRATE_BASELINE', 'ELGG_MIGRATE_STATE'] as $k) {
            $this->savedEnv[$k] = getenv($k);
            putenv($k); // unset
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === false) {
                putenv($k);
            } else {
                putenv("{$k}={$v}");
            }
        }
        $this->rrmdir($this->tmp);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** Create a plugin dir with any of: phpunit config, regression test, baseline record. */
    private function makePlugin(array $opts = []): string
    {
        $dir = $this->tmp . '/plugin-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tests', 0777, true);

        if ($opts['phpunit'] ?? false) {
            file_put_contents($dir . '/tests/phpunit.xml', "<phpunit/>\n");
        }
        if ($opts['regression'] ?? false) {
            file_put_contents($dir . '/tests/MigrationRegressionTest.php', "<?php\n");
        }
        if (array_key_exists('baseline', $opts)) {
            file_put_contents($dir . '/tests/.migration-baseline.json', $opts['baseline']);
        }
        return $dir;
    }

    private function passBaseline(?int $targetMajor = 4): string
    {
        $rec = ['status' => 'pass', 'phpunit' => ['failures' => 0, 'errors' => 0]];
        if ($targetMajor !== null) {
            $rec['target_major'] = $targetMajor;
        }
        return (string) json_encode($rec);
    }

    /**
     * Run the gate capturing STDOUT; returns [bool ok, string stdout].
     *
     * The remediation banner goes to the STDERR constant (a real stream we can't
     * rebind), so we assert on the boolean contract + STDOUT header. The boolean
     * is what bin/migrate.php turns into exit(7), so it is the load-bearing part.
     */
    private function runGate(string $pluginPath, array $manifest, ?string $override = null): array
    {
        ob_start();
        $ok = TestsFirstGate::runTestsFirstGate($pluginPath, $manifest, $override);
        $stdout = ob_get_clean();

        return [$ok, $stdout];
    }

    // --- detectTargetMajor -------------------------------------------------

    public function testDetectTargetMajorParsesNumericMajor(): void
    {
        $this->assertSame(4, TestsFirstGate::detectTargetMajor(['to' => '4.x']));
        $this->assertSame(7, TestsFirstGate::detectTargetMajor(['to' => 'elgg7']));
    }

    public function testDetectTargetMajorNullWhenAbsent(): void
    {
        $this->assertNull(TestsFirstGate::detectTargetMajor([]));
        $this->assertNull(TestsFirstGate::detectTargetMajor(['to' => 'x.y']));
    }

    // --- findPhpunitConfig -------------------------------------------------

    public function testFindPhpunitConfigMissingReturnsNull(): void
    {
        $dir = $this->makePlugin();
        $this->assertNull(TestsFirstGate::findPhpunitConfig($dir));
    }

    public function testFindPhpunitConfigFindsTestsConfig(): void
    {
        $dir = $this->makePlugin(['phpunit' => true]);
        $this->assertSame('tests/phpunit.xml', TestsFirstGate::findPhpunitConfig($dir));
    }

    public function testFindPhpunitConfigFindsRootDist(): void
    {
        $dir = $this->makePlugin();
        file_put_contents($dir . '/phpunit.xml.dist', "<phpunit/>\n");
        $this->assertSame('phpunit.xml.dist', TestsFirstGate::findPhpunitConfig($dir));
    }

    // --- findMigrationRegressionTest --------------------------------------

    public function testFindRegressionMissingReturnsNull(): void
    {
        $dir = $this->makePlugin(['phpunit' => true]);
        $this->assertNull(TestsFirstGate::findMigrationRegressionTest($dir));
    }

    public function testFindRegressionLocatesNestedFile(): void
    {
        $dir = $this->makePlugin();
        mkdir($dir . '/tests/unit', 0777, true);
        file_put_contents($dir . '/tests/unit/MigrationRegressionTest.php', "<?php\n");
        $this->assertSame(
            'tests/unit/MigrationRegressionTest.php',
            TestsFirstGate::findMigrationRegressionTest($dir)
        );
    }

    // --- resolveBaselineFile ----------------------------------------------

    public function testResolveBaselineOverrideWins(): void
    {
        $dir = $this->makePlugin(['baseline' => $this->passBaseline()]);
        $override = $this->tmp . '/custom-baseline.json';
        file_put_contents($override, $this->passBaseline());

        $this->assertSame($override, TestsFirstGate::resolveBaselineFile($dir, $override));
    }

    public function testResolveBaselineEnvResolution(): void
    {
        $dir = $this->makePlugin();
        $envFile = $this->tmp . '/env-baseline.json';
        file_put_contents($envFile, $this->passBaseline());
        putenv("ELGG_MIGRATE_BASELINE={$envFile}");

        $this->assertSame($envFile, TestsFirstGate::resolveBaselineFile($dir, null));
    }

    public function testResolveBaselineFallsBackToTestsDir(): void
    {
        $dir = $this->makePlugin(['baseline' => $this->passBaseline()]);
        $this->assertSame(
            $dir . '/tests/.migration-baseline.json',
            TestsFirstGate::resolveBaselineFile($dir, null)
        );
    }

    public function testResolveBaselineNullWhenNothing(): void
    {
        $dir = $this->makePlugin();
        $this->assertNull(TestsFirstGate::resolveBaselineFile($dir, null));
    }

    public function testResolveBaselineOverridePrecedesEnv(): void
    {
        $dir = $this->makePlugin();
        $override = $this->tmp . '/o.json';
        $envFile = $this->tmp . '/e.json';
        file_put_contents($override, $this->passBaseline());
        file_put_contents($envFile, $this->passBaseline());
        putenv("ELGG_MIGRATE_BASELINE={$envFile}");

        $this->assertSame($override, TestsFirstGate::resolveBaselineFile($dir, $override));
    }

    // --- validateBaseline --------------------------------------------------

    public function testValidateBaselinePasses(): void
    {
        $f = $this->tmp . '/good.json';
        file_put_contents($f, $this->passBaseline(4));
        [$ok, $reason] = TestsFirstGate::validateBaseline($f, 4);
        $this->assertTrue($ok, $reason);
        $this->assertSame('', $reason);
    }

    public function testValidateBaselineRejectsNonJson(): void
    {
        $f = $this->tmp . '/bad.json';
        file_put_contents($f, 'not json{{');
        [$ok, $reason] = TestsFirstGate::validateBaseline($f, 4);
        $this->assertFalse($ok);
        $this->assertStringContainsString('not valid JSON', $reason);
    }

    public function testValidateBaselineRejectsNonPassStatus(): void
    {
        $f = $this->tmp . '/red.json';
        file_put_contents($f, (string) json_encode(['status' => 'fail', 'phpunit' => ['failures' => 0, 'errors' => 0]]));
        [$ok, $reason] = TestsFirstGate::validateBaseline($f, 4);
        $this->assertFalse($ok);
        $this->assertStringContainsString("expected 'pass'", $reason);
    }

    public function testValidateBaselineRejectsFailuresAndErrors(): void
    {
        $f = $this->tmp . '/counts.json';
        file_put_contents($f, (string) json_encode(['status' => 'pass', 'phpunit' => ['failures' => 2, 'errors' => 1]]));
        [$ok, $reason] = TestsFirstGate::validateBaseline($f, 4);
        $this->assertFalse($ok);
        $this->assertStringContainsString('2 failure(s) / 1 error(s)', $reason);
    }

    public function testValidateBaselineRejectsStaleTargetMajor(): void
    {
        $f = $this->tmp . '/stale.json';
        file_put_contents($f, $this->passBaseline(3)); // captured for 3.x
        [$ok, $reason] = TestsFirstGate::validateBaseline($f, 4); // run targets 4.x
        $this->assertFalse($ok);
        $this->assertStringContainsString('captured for target Elgg 3.x but this run targets 4.x', $reason);
    }

    public function testValidateBaselineAcceptsWhenNoTargetDeclared(): void
    {
        $f = $this->tmp . '/notarget.json';
        file_put_contents($f, $this->passBaseline(null)); // no target_major key
        [$ok, $reason] = TestsFirstGate::validateBaseline($f, 4);
        $this->assertTrue($ok, $reason);
    }

    // --- runTestsFirstGate: refusals & pass -------------------------------

    public function testGateRefusesWhenPhpunitMissing(): void
    {
        $dir = $this->makePlugin(['regression' => true, 'baseline' => $this->passBaseline()]);
        [$ok, $stdout] = $this->runGate($dir, ['to' => '4.x']);
        $this->assertFalse($ok);
        $this->assertStringContainsString('TESTS-FIRST GATE', $stdout);
    }

    public function testGateRefusesWhenRegressionMissing(): void
    {
        $dir = $this->makePlugin(['phpunit' => true, 'baseline' => $this->passBaseline()]);
        [$ok] = $this->runGate($dir, ['to' => '4.x']);
        $this->assertFalse($ok);
    }

    public function testGateRefusesWhenBaselineMissing(): void
    {
        $dir = $this->makePlugin(['phpunit' => true, 'regression' => true]);
        [$ok] = $this->runGate($dir, ['to' => '4.x']);
        $this->assertFalse($ok);
    }

    public function testGateRefusesWhenBaselineStale(): void
    {
        // All three present, but baseline captured for the WRONG target major.
        $dir = $this->makePlugin([
            'phpunit' => true,
            'regression' => true,
            'baseline' => $this->passBaseline(3),
        ]);
        [$ok] = $this->runGate($dir, ['to' => '4.x']);
        $this->assertFalse($ok);
    }

    public function testGatePassesWhenAllPresentAndTargetMatches(): void
    {
        $dir = $this->makePlugin([
            'phpunit' => true,
            'regression' => true,
            'baseline' => $this->passBaseline(4),
        ]);
        [$ok, $stdout] = $this->runGate($dir, ['to' => '4.x']);
        $this->assertTrue($ok);
        $this->assertStringContainsString('tests-first gate satisfied', $stdout);
    }

    public function testGatePassesViaBaselineOverride(): void
    {
        $dir = $this->makePlugin(['phpunit' => true, 'regression' => true]);
        $override = $this->tmp . '/ov.json';
        file_put_contents($override, $this->passBaseline(4));
        [$ok] = $this->runGate($dir, ['to' => '4.x'], $override);
        $this->assertTrue($ok);
    }

    // --- logTestsBypass ----------------------------------------------------

    public function testLogTestsBypassWritesLogWhenStateDirSet(): void
    {
        $dir = $this->makePlugin();
        $stateDir = $this->tmp . '/state';
        mkdir($stateDir, 0777, true);
        putenv("ELGG_MIGRATE_STATE={$stateDir}");

        // Silence the STDERR/STDOUT banner the helper emits.
        ob_start();
        TestsFirstGate::logTestsBypass($dir, ['from' => '3.x', 'to' => '4.x']);
        ob_end_clean();

        $log = $stateDir . '/tests-bypass.log';
        $this->assertFileExists($log);
        $contents = (string) file_get_contents($log);
        $this->assertStringContainsString('TESTS-FIRST GATE BYPASSED (--no-tests)', $contents);
        $this->assertStringContainsString('(3.x→4.x)', $contents);
        $this->assertStringContainsString(basename($dir), $contents);
    }

    public function testLogTestsBypassNoStateDirWritesNoFile(): void
    {
        $dir = $this->makePlugin();
        // ELGG_MIGRATE_STATE unset in setUp.
        ob_start();
        TestsFirstGate::logTestsBypass($dir, ['from' => '3.x', 'to' => '4.x']);
        ob_end_clean();
        // Nothing to assert beyond "no crash / no file leaked into plugin dir".
        $this->assertFileDoesNotExist($dir . '/tests-bypass.log');
    }
}
