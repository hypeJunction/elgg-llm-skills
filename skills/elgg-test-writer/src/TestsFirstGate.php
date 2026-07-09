<?php

namespace ElggMigrate;

/**
 * TESTS-FIRST gate (Iron Law 4).
 *
 * Refuse to MUTATE plugin code until a regression safety net is proven to exist:
 * (1) a plugin test suite incl. MigrationRegressionTest, and (2) a captured,
 * PASSING baseline run against the CURRENT (pre-migration) code. Without a
 * known-good baseline you cannot tell whether a later failure is a regression
 * the migration introduced or a bug that was already there.
 *
 * These were procedural helpers in bin/migrate.php; extracted verbatim so they
 * can be unit-tested. bin/migrate.php delegates here. The CLI contract is
 * load-bearing: runTestsFirstGate() returning false => the CLI exit(7)s, and
 * the remediation text / bypass log format are asserted by tests.
 */
class TestsFirstGate
{
    /**
     * TESTS-FIRST gate. Returns true if the plugin is safe to migrate (a test
     * suite incl. MigrationRegressionTest exists AND a passing baseline was
     * captured on the current code). On failure it prints a targeted
     * remediation and returns false so the caller can `exit(7)` before mutating
     * a single file.
     */
    public static function runTestsFirstGate(string $pluginPath, array $manifest, ?string $baselineOverride): bool
    {
        echo "--- TESTS-FIRST GATE (required before any transform) ---\n";

        $targetMajor = self::detectTargetMajor($manifest);
        $problems = [];

        // (1) A PHPUnit config must exist.
        $phpunitConfig = self::findPhpunitConfig($pluginPath);
        if ($phpunitConfig === null) {
            $problems[] = 'NO TEST SUITE — no phpunit.xml(.dist) found (checked tests/ and plugin root).';
        } else {
            echo "  ✓ test config: {$phpunitConfig}\n";
        }

        // (2) The RED→GREEN regression guard must be present.
        $regression = self::findMigrationRegressionTest($pluginPath);
        if ($regression === null) {
            $problems[] = 'NO MigrationRegressionTest — the RED-before/GREEN-after guard is missing.';
        } else {
            echo "  ✓ regression guard: {$regression}\n";
        }

        // (3) A passing baseline must have been captured on the CURRENT code.
        $baselineFile = self::resolveBaselineFile($pluginPath, $baselineOverride);
        if ($baselineFile === null) {
            $problems[] = 'NO BASELINE RECORD — run the suite on the CURRENT code and record a passing baseline.';
        } else {
            [$ok, $reason] = self::validateBaseline($baselineFile, $targetMajor);
            if ($ok) {
                echo "  ✓ baseline: {$baselineFile} (PASS)\n";
            } else {
                $problems[] = "BASELINE NOT USABLE — {$reason}";
            }
        }

        if (empty($problems)) {
            echo "  ✓ tests-first gate satisfied — proceeding to apply transforms.\n\n";
            return true;
        }

        $skillRoot = dirname(__DIR__);
        $targetLabel = $targetMajor !== null ? (string) $targetMajor : 'N';
        $baselinePath = rtrim($pluginPath, '/') . '/tests/.migration-baseline.json';

        fwrite(STDERR, "\n✗ TESTS-FIRST GATE FAILED — refusing to migrate {$pluginPath}\n");
        foreach ($problems as $p) {
            fwrite(STDERR, "  • {$p}\n");
        }
        fwrite(STDERR, "\n  Why: migration transforms are irreversible mutations. Without a passing\n");
        fwrite(STDERR, "  baseline on the CURRENT code you cannot tell whether a later failure is a\n");
        fwrite(STDERR, "  regression the migration introduced or a bug that was already there.\n");
        fwrite(STDERR, "\n  Remediation:\n");
        fwrite(STDERR, "  1. Generate the tests-first suite (BaselineTest + MigrationRegressionTest):\n");
        fwrite(STDERR, "       {$skillRoot}/../elgg-test-writer/bin/scaffold-smoke-tests.sh \\\n");
        fwrite(STDERR, "         --plugin-dir={$pluginPath} --target-version=elgg{$targetLabel}\n");
        fwrite(STDERR, "     (the guard mirrors references/migration-failure-catalog.md — RED before,\n");
        fwrite(STDERR, "      GREEN after; see references/migration-failure-catalog.md for each class.)\n");
        fwrite(STDERR, "  2. Run the suite against the CURRENT-version Docker stack. It MUST pass\n");
        fwrite(STDERR, "     (BaselineTest GREEN; MigrationRegressionTest RED is expected pre-migration).\n");
        fwrite(STDERR, "  3. Record the passing baseline so this gate can see it:\n");
        fwrite(STDERR, "       cat > {$baselinePath} <<'JSON'\n");
        fwrite(STDERR, "       {\"status\":\"pass\",\"target_major\":{$targetLabel},\n");
        fwrite(STDERR, "        \"captured_at\":\"<ISO8601>\",\"phpunit\":{\"failures\":0,\"errors\":0}}\n");
        fwrite(STDERR, "       JSON\n");
        fwrite(STDERR, "  4. Re-run this command. After the transform, re-run the suite on the TARGET\n");
        fwrite(STDERR, "     stack — BaselineTest must stay GREEN, MigrationRegressionTest must flip GREEN.\n");
        fwrite(STDERR, "\n  Override (unsafe, logged): pass --no-tests to bypass this gate.\n");

        return false;
    }

    /** Extract the numeric target major (e.g. "4.x" → 4) from the manifest 'to'. */
    public static function detectTargetMajor(array $manifest): ?int
    {
        $to = (string) ($manifest['to'] ?? '');
        if (preg_match('/(\d+)/', $to, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** First PHPUnit config found under tests/ or the plugin root, relative path or null. */
    public static function findPhpunitConfig(string $pluginPath): ?string
    {
        foreach (['tests/phpunit.xml', 'tests/phpunit.xml.dist', 'phpunit.xml', 'phpunit.xml.dist'] as $rel) {
            if (is_file(rtrim($pluginPath, '/') . '/' . $rel)) {
                return $rel;
            }
        }
        return null;
    }

    /** Locate MigrationRegressionTest.php anywhere under tests/; relative path or null. */
    public static function findMigrationRegressionTest(string $pluginPath): ?string
    {
        $base = rtrim($pluginPath, '/') . '/tests';
        if (!is_dir($base)) {
            return null;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->getFilename() === 'MigrationRegressionTest.php') {
                return ltrim(substr($f->getPathname(), strlen(rtrim($pluginPath, '/'))), '/');
            }
        }
        return null;
    }

    /** Resolve the baseline record path: override → env → tests/ → plugin root. */
    public static function resolveBaselineFile(string $pluginPath, ?string $override): ?string
    {
        $candidates = [];
        if ($override !== null && $override !== '') {
            $candidates[] = $override;
        }
        $env = getenv('ELGG_MIGRATE_BASELINE');
        if ($env !== false && $env !== '') {
            $candidates[] = $env;
        }
        $candidates[] = rtrim($pluginPath, '/') . '/tests/.migration-baseline.json';
        $candidates[] = rtrim($pluginPath, '/') . '/.migration-baseline.json';

        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Validate a baseline record: must be JSON, status pass/green, zero PHPUnit
     * failures/errors, and (if it declares a target_major) match this run's target.
     *
     * @return array{0:bool,1:string} [ok, reason-if-not-ok]
     */
    public static function validateBaseline(string $file, ?int $targetMajor): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return [false, "baseline file is not valid JSON: {$file}"];
        }

        $status = strtolower((string) ($data['status'] ?? ''));
        if (!in_array($status, ['pass', 'passed', 'green', 'ok'], true)) {
            return [false, "baseline status is '" . ($status ?: '(missing)') . "', expected 'pass' — the suite must be GREEN on the CURRENT code before migrating ({$file})"];
        }

        $pu = is_array($data['phpunit'] ?? null) ? $data['phpunit'] : [];
        $failures = (int) ($pu['failures'] ?? 0);
        $errors = (int) ($pu['errors'] ?? 0);
        if ($failures > 0 || $errors > 0) {
            return [false, "baseline records {$failures} failure(s) / {$errors} error(s) — a red baseline cannot prove the migration preserved behavior ({$file})"];
        }

        if ($targetMajor !== null && isset($data['target_major']) && (int) $data['target_major'] !== $targetMajor) {
            return [false, "baseline was captured for target Elgg {$data['target_major']}.x but this run targets {$targetMajor}.x — re-capture it for this step ({$file})"];
        }

        return [true, ''];
    }

    /** Loudly announce (and persist, if a state dir is set) a --no-tests bypass. */
    public static function logTestsBypass(string $pluginPath, array $manifest): void
    {
        $msg = sprintf(
            '[%s] TESTS-FIRST GATE BYPASSED (--no-tests) for %s (%s→%s) — migrating WITHOUT a regression baseline',
            date('c'),
            basename(rtrim($pluginPath, '/')),
            (string) ($manifest['from'] ?? '?'),
            (string) ($manifest['to'] ?? '?')
        );

        fwrite(STDERR, "\n⚠⚠⚠  {$msg}\n");
        fwrite(STDERR, "     Unsafe: no RED→GREEN proof the migration preserved behavior.\n");
        fwrite(STDERR, "     See SKILL.md 'Tests-first' and references/migration-failure-catalog.md.\n\n");

        // Persist to the job state dir if one is configured. NEVER write into the
        // plugin dir (skill invariant) or the skill dir.
        $stateDir = getenv('ELGG_MIGRATE_STATE');
        if ($stateDir !== false && $stateDir !== '' && is_dir($stateDir)) {
            @file_put_contents(rtrim($stateDir, '/') . '/tests-bypass.log', $msg . "\n", FILE_APPEND);
        }
    }
}
