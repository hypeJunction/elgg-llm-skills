<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\NamespacedGlobalElggHelpers;
use PHPUnit\Framework\TestCase;

final class NamespacedGlobalElggHelpersTest extends TestCase
{
    private NamespacedGlobalElggHelpers $rule;

    protected function setUp(): void
    {
        $this->rule = new NamespacedGlobalElggHelpers();
    }

    public function testAnalyzeFlagsUnqualifiedHelperCallsInNamespacedFile(): void
    {
        $workDir = $this->workDir();
        file_put_contents($workDir . '/bootstrap.php', <<<'PHP'
            <?php
            namespace Bodyology;
            use Elgg\DefaultPluginBootstrap;
            class FeedbackBootstrap extends DefaultPluginBootstrap {
                public function init(): void {
                    elgg_register_admin_menu_item('administer', 'feedback', 'admin');
                    elgg_register_widget_type('feedback', elgg_echo('feedback:title'), '');
                }
            }
            PHP);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertTrue($analysis->applicable);
            $this->assertCount(3, $analysis->findings);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeIgnoresUnnamespacedFiles(): void
    {
        $workDir = $this->workDir();
        file_put_contents($workDir . '/procedural.php', <<<'PHP'
            <?php
            // No namespace declaration — these calls are already in global scope.
            function feedback_init() {
                elgg_register_admin_menu_item('administer', 'feedback', 'admin');
                elgg_register_widget_type('feedback', 'Feedback', '');
            }
            PHP);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeIgnoresAlreadyPrefixedCalls(): void
    {
        $workDir = $this->workDir();
        file_put_contents($workDir . '/correct.php', <<<'PHP'
            <?php
            namespace Bodyology;
            \elgg_register_admin_menu_item('administer', 'feedback', 'admin');
            \elgg_register_widget_type('feedback', \elgg_echo('feedback:title'), '');
            PHP);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeIgnoresUseFunctionImports(): void
    {
        $workDir = $this->workDir();
        file_put_contents($workDir . '/imported.php', <<<'PHP'
            <?php
            namespace Bodyology;
            use function elgg_register_admin_menu_item;
            use function elgg_echo as t;
            elgg_register_admin_menu_item('administer', 'feedback', 'admin');
            t('feedback:title');
            PHP);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeIgnoresLocallyDefinedFunctions(): void
    {
        $workDir = $this->workDir();
        file_put_contents($workDir . '/local-fn.php', <<<'PHP'
            <?php
            namespace Bodyology;
            function elgg_helper(): void { /* namespaced helper, NOT global */ }
            elgg_helper();
            elgg_register_admin_menu_item('a', 'b', 'c');
            PHP);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertTrue($analysis->applicable);
            // Only the register_admin_menu_item call — the elgg_helper() is local.
            $this->assertCount(1, $analysis->findings);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyPrefixesUnqualifiedHelperCalls(): void
    {
        $workDir = $this->workDir();
        $file = $workDir . '/Bootstrap.php';
        file_put_contents($file, <<<'PHP'
            <?php
            namespace Bodyology;
            class FeedbackBootstrap {
                public function init(): void {
                    elgg_register_admin_menu_item('administer', 'feedback', 'admin');
                    elgg_register_widget_type('feedback', elgg_echo('feedback:title'), '');
                }
            }
            PHP);

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $modified = file_get_contents($file);
            $this->assertStringContainsString('\elgg_register_admin_menu_item', $modified);
            $this->assertStringContainsString('\elgg_register_widget_type', $modified);
            $this->assertStringContainsString('\elgg_echo', $modified);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyPreservesUntouchedFormatting(): void
    {
        $workDir = $this->workDir();
        $file = $workDir . '/Bootstrap.php';
        $original = <<<'PHP'
            <?php

            /**
             * Bootstrap with bespoke formatting that must round-trip.
             */

            namespace Bodyology;

            use Elgg\DefaultPluginBootstrap;


            class FeedbackBootstrap extends DefaultPluginBootstrap {

                public function init(): void {
                    // Single targeted change — every other byte should survive.
                    elgg_register_admin_menu_item('administer', 'feedback', 'admin');
                }
            }

            PHP;
        file_put_contents($file, $original);

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);

            $modified = file_get_contents($file);
            $expected = str_replace(
                'elgg_register_admin_menu_item(',
                '\elgg_register_admin_menu_item(',
                $original,
            );
            $this->assertSame($expected, $modified);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplySkipsCallsInGlobalNamespaceFile(): void
    {
        $workDir = $this->workDir();
        $original = <<<'PHP'
            <?php
            // No namespace — already in global scope; rule must be a no-op.
            elgg_register_admin_menu_item('administer', 'feedback', 'admin');
            PHP;
        $file = $workDir . '/start.php';
        file_put_contents($file, $original);

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertSame($original, file_get_contents($file));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyDoesNotDoublePrefix(): void
    {
        $workDir = $this->workDir();
        $original = <<<'PHP'
            <?php
            namespace Bodyology;
            \elgg_register_admin_menu_item('a', 'b', 'c');
            PHP;
        $file = $workDir . '/already-fixed.php';
        file_put_contents($file, $original);

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertSame($original, file_get_contents($file));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyHandlesUnderscoreHelperPrefix(): void
    {
        $workDir = $this->workDir();
        $file = $workDir . '/uses-underscore.php';
        file_put_contents($file, <<<'PHP'
            <?php
            namespace Bodyology;
            _elgg_services()->session;
            _elgg_cache_views();
            PHP);

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);

            $modified = file_get_contents($file);
            $this->assertStringContainsString('\_elgg_services', $modified);
            $this->assertStringContainsString('\_elgg_cache_views', $modified);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIgnoresVendorAndNodeModulesDirs(): void
    {
        $workDir = $this->workDir();
        mkdir($workDir . '/vendor/some-lib', 0755, true);
        mkdir($workDir . '/node_modules/foo', 0755, true);
        mkdir($workDir . '/classes/Bodyology', 0755, true);

        $vendorContent = "<?php\nnamespace Vendor\\Lib;\nelgg_helper_from_vendor();\n";
        $nodeContent = "<?php\nnamespace Node\\Mod;\nelgg_helper_from_node();\n";
        $ownContent = "<?php\nnamespace Bodyology;\nelgg_register_admin_menu_item('a', 'b', 'c');\n";

        file_put_contents($workDir . '/vendor/some-lib/code.php', $vendorContent);
        file_put_contents($workDir . '/node_modules/foo/code.php', $nodeContent);
        file_put_contents($workDir . '/classes/Bodyology/Boot.php', $ownContent);

        try {
            $result = $this->rule->apply($workDir);

            // Only our own file should be modified
            $this->assertCount(1, $result->changes);
            $this->assertStringEndsWith('Boot.php', $result->changes[0]->file);

            // Vendor and node_modules untouched
            $this->assertSame($vendorContent, file_get_contents($workDir . '/vendor/some-lib/code.php'));
            $this->assertSame($nodeContent, file_get_contents($workDir . '/node_modules/foo/code.php'));

            // Our file got prefixed
            $this->assertStringContainsString('\elgg_register_admin_menu_item', file_get_contents($workDir . '/classes/Bodyology/Boot.php'));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIgnoresMethodCallsAndStaticCalls(): void
    {
        $workDir = $this->workDir();
        $file = $workDir . '/methods.php';
        $original = <<<'PHP'
            <?php
            namespace Bodyology;
            $obj->elgg_method('not a function');
            self::elgg_static('not a function');
            Foo::elgg_static('not a function');
            PHP;
        file_put_contents($file, $original);

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertSame($original, file_get_contents($file));
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function workDir(): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
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
