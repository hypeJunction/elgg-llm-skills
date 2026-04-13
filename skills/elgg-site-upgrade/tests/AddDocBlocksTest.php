<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\Rules\Shared\AddDocBlocks;
use PHPUnit\Framework\TestCase;

final class AddDocBlocksTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        // Copy the fixture to a throwaway working dir so each test
        // gets a fresh copy to mutate.
        $this->workDir = sys_get_temp_dir() . '/add-docblocks-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
        $src = __DIR__ . '/fixtures/shared/add-docblocks/input/sample.php';
        copy($src, $this->workDir . '/sample.php');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            foreach (glob($this->workDir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->workDir);
        }
    }

    public function testAnalyzeFlagsMissingDocBlocks(): void
    {
        $rule = new AddDocBlocks();
        $analysis = $rule->analyze($this->workDir);

        $this->assertTrue($analysis->applicable);
        $this->assertStringContainsString('without docblocks', $analysis->summary);
        $this->assertNotEmpty($analysis->findings);
    }

    public function testApplyInsertsDocBlocksAndLeavesExistingAlone(): void
    {
        $rule = new AddDocBlocks();
        $result = $rule->apply($this->workDir);

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->changes);

        $code = file_get_contents($this->workDir . '/sample.php');

        // Undocumented function got a docblock.
        $this->assertStringContainsString(
            "/**\n * @param string \$name\n * @param ?int \$age\n * @return bool\n */\nfunction undocumented_function",
            $code,
        );

        // void-returning function keeps @return void.
        $this->assertStringContainsString(
            "/**\n * @param array \$items\n * @return void\n */\nfunction undocumented_no_return",
            $code,
        );

        // Constructor does NOT get a @return line.
        $this->assertMatchesRegularExpression(
            '#/\*\*\s+\*\s*@param string \$label\s+\*/\s+public function __construct#',
            $code,
        );
        $this->assertDoesNotMatchRegularExpression(
            '#@return[^\n]*\n[^\n]*public function __construct#',
            $code,
        );

        // Variadic parameter is rendered with "...".
        $this->assertStringContainsString(' * @param int ...$values', $code);

        // Untyped parameter falls back to mixed.
        $this->assertStringContainsString(' * @param mixed $input', $code);

        // Typed property gains a @var.
        $this->assertStringContainsString('/** @var int */', $code);
        $this->assertStringContainsString('/** @var ?string */', $code);

        // Already-documented property is left as-is (only one @var on alreadyDocumented).
        $this->assertSame(1, substr_count($code, 'array<string,mixed>'));
    }

    public function testApplyIsIdempotent(): void
    {
        $rule = new AddDocBlocks();
        $rule->apply($this->workDir);
        $firstPass = file_get_contents($this->workDir . '/sample.php');

        $rule->apply($this->workDir);
        $secondPass = file_get_contents($this->workDir . '/sample.php');

        $this->assertSame($firstPass, $secondPass);
    }

    public function testSkipsFilesItCannotParse(): void
    {
        file_put_contents($this->workDir . '/broken.php', "<?php this is not valid php {{{{");
        $rule = new AddDocBlocks();
        // Must not throw.
        $result = $rule->apply($this->workDir);
        $this->assertTrue($result->success);
    }
}
