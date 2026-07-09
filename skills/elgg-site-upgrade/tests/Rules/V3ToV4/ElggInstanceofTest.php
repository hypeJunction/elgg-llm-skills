<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\ElggInstanceof;
use PHPUnit\Framework\TestCase;

final class ElggInstanceofTest extends TestCase
{
    private ElggInstanceof $rule;
    private string $inputFixture;
    private string $expectedFixture;

    protected function setUp(): void
    {
        $this->rule = new ElggInstanceof();
        $this->inputFixture = __DIR__ . '/../../fixtures/3x-to-4x/elgg-instanceof/input';
        $this->expectedFixture = __DIR__ . '/../../fixtures/3x-to-4x/elgg-instanceof/expected';
    }

    public function testAnalyzeFindsThreeUsages(): void
    {
        $analysis = $this->rule->analyze($this->inputFixture);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(1, $analysis->findings, 'One finding per file (counts all occurrences within)');

        $this->assertStringContainsString('3', $analysis->findings[0]->description, 'Should report 3 occurrences');
    }

    public function testApplyProducesExpectedOutput(): void
    {
        $dir = $this->tempDir();
        copy($this->inputFixture . '/code.php', $dir . '/code.php');

        try {
            $result = $this->rule->apply($dir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $actual   = file_get_contents($dir . '/code.php');
            $expected = file_get_contents($this->expectedFixture . '/code.php');

            $this->assertSame($expected, $actual, 'Fixed PHP should match expected fixture');
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * A negated subtype check must wrap the whole instanceof+getSubtype
     * expression in parens. Without it, `!$e instanceof X && $e->getSubtype()`
     * parses as `(!$e instanceof X) && ...` — silently inverting the first half.
     */
    public function testNegatedSubtypeCallIsWrappedInParens(): void
    {
        $output = $this->applyTo("<?php\nif (!elgg_instanceof(\$entity, 'object', 'blog')) {\n}\n");

        $this->assertStringContainsString(
            "!(\$entity instanceof \\ElggObject && \$entity->getSubtype() === 'blog')",
            $output,
        );
    }

    /**
     * The string-based replacement must never leak a literal backslash-dollar
     * (`\$var`), which is invalid PHP.
     */
    public function testSubtypeOutputHasNoLiteralBackslashDollar(): void
    {
        $output = $this->applyTo("<?php\n\$ok = elgg_instanceof(\$page_owner, 'object', 'page');\n");

        $this->assertStringNotContainsString('\\$', $output);
        $this->assertStringContainsString(
            "(\$page_owner instanceof \\ElggObject && \$page_owner->getSubtype() === 'page')",
            $output,
        );
    }

    /**
     * A lone instanceof needs no parens: `instanceof` binds tighter than `!`,
     * so `!$e instanceof X` already means `!($e instanceof X)`.
     */
    public function testNonNegatedSingleInstanceofStaysUnwrapped(): void
    {
        $output = $this->applyTo("<?php\nif (elgg_instanceof(\$user, 'user')) {\n}\n");

        $this->assertStringContainsString("if (\$user instanceof \\ElggUser) {", $output);
    }

    public function testCleanFilesAreNotModified(): void
    {
        $dir = $this->tempDir();
        $original = "<?php\n\$result = \$entity instanceof \\ElggObject;\n";
        file_put_contents($dir . '/clean.php', $original);

        try {
            $result = $this->rule->apply($dir);
            $this->assertEmpty($result->changes);
            $this->assertSame($original, file_get_contents($dir . '/clean.php'));
        } finally {
            $this->removeDir($dir);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Run the rule over a single in-memory source string and return the
     * transformed file contents.
     */
    private function applyTo(string $source): string
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/code.php', $source);

        try {
            $this->rule->apply($dir);
            return file_get_contents($dir . '/code.php');
        } finally {
            $this->removeDir($dir);
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-instanceof-' . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
