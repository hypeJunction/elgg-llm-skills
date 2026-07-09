<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\PostMigrationVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Fixture coverage for the failure-catalog (FC-*) detectors added to
 * PostMigrationVerifier. Every check gets one POSITIVE fixture (the broken
 * pattern must be flagged with the expected category) and one NEGATIVE fixture
 * (the correctly-migrated form must NOT be flagged with that category), so a
 * regex regression that stops firing OR starts false-positiving fails a test.
 *
 * Cases are keyed by category so a failure names the exact detector.
 */
final class FailureCatalogGateTest extends TestCase
{
    private PostMigrationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PostMigrationVerifier();
    }

    /**
     * @dataProvider positiveCases
     */
    public function testDetectorFiresOnBrokenCode(string $category, string $target, array $files): void
    {
        $dir = $this->makePluginDir($files);
        try {
            $result = $this->verifier->verify($dir, $target);
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertContains(
                $category,
                $cats,
                "Expected category '{$category}' at target {$target}. Got: " . implode(', ', $cats ?: ['<none>'])
            );
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @dataProvider negativeCases
     */
    public function testDetectorSilentOnMigratedCode(string $category, string $target, array $files): void
    {
        $dir = $this->makePluginDir($files);
        try {
            $result = $this->verifier->verify($dir, $target);
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains(
                $category,
                $cats,
                "Correctly-migrated fixture wrongly flagged '{$category}' at target {$target}."
            );
        } finally {
            $this->removeDir($dir);
        }
    }

    // Each entry: category => [target, positiveFiles, negativeFiles].
    // Providers below fan this single table out into the two directions.
    private static function cases(): array
    {
        $plugin = ['elgg-plugin.php' => "<?php\nreturn [];\n"];

        return [
            // --- FC-3x4x-10 ---
            'camelcase-plugin-id' => ['4.x',
                $plugin + ['classes/P.php' => "<?php\nfunction f() { return elgg_get_plugin_from_id('HypeWall'); }\n"],
                $plugin + ['classes/P.php' => "<?php\nfunction f() { return elgg_get_plugin_from_id('hypewall'); }\n"],
            ],
            // --- FC-3x4x-04 ---
            'removed-method' => ['4.x',
                $plugin + ['classes/F.php' => "<?php\nfunction m(\$file) { return \$file->detectMimeType(); }\n"],
                $plugin + ['classes/F.php' => "<?php\nfunction m(\$path) { return mime_content_type(\$path); }\n"],
            ],
            // --- FC-3x4x-14 ---
            'relocated-symbol' => ['4.x',
                $plugin + ['classes/G.php' => "<?php\nuse Elgg\\GatekeeperException;\n"],
                $plugin + ['classes/G.php' => "<?php\nuse Elgg\\Exceptions\\Http\\GatekeeperException;\n"],
            ],
            // --- FC-3x4x-13 ---
            'install-sql-not-run' => ['4.x',
                $plugin + ['install/mysql.sql' => "CREATE TABLE prefix_thing (id INT);\n"],
                $plugin + [
                    'install/mysql.sql' => "CREATE TABLE prefix_thing (id INT);\n",
                    'classes/Bootstrap.php' => "<?php\nclass Bootstrap {\n  public function activate() {\n    \$this->db->executeStatement(\$sql);\n  }\n}\n",
                ],
            ],
            // --- FC-3x4x-12 / FC-ALL-04 ---
            'legacy-handler-signature' => ['4.x',
                $plugin + ['classes/H.php' => "<?php\nfunction handle(\$hook, \$type, \$return, \$params) { return \$return; }\n"],
                $plugin + ['classes/H.php' => "<?php\nfunction handle(\\Elgg\\Event \$event) { return \$event->getValue(); }\n"],
            ],
            // --- FC-2x3x-03 ---
            'search-hook-return' => ['3.x',
                $plugin + ['classes/S.php' => "<?php\n\$type = 'search';\nfunction s() { return ['entities' => \$found]; }\n"],
                $plugin + ['classes/S.php' => "<?php\n\$type = 'search';\nfunction s() { return elgg_search(['type' => 'object']); }\n"],
            ],
            // --- FC-2x3x-04 ---
            'site-secret-scrub' => ['3.x',
                $plugin + ['anon.sql' => "UPDATE elgg_config SET value = '' WHERE name = '__site_secret__';\n"],
                $plugin + ['anon.sql' => "UPDATE elgg_users SET email = 'anon@example.test';\n"],
            ],
            // --- FC-4x5x-05 ---
            'removed-service' => ['5.x',
                $plugin + ['classes/A.php' => "<?php\n\$svc = new PluginHooksService();\n"],
                $plugin + ['classes/A.php' => "<?php\n\$svc = new EventsService();\n"],
            ],
            // --- FC-4x5x-06 ---
            'removed-js-api' => ['5.x',
                $plugin + ['views/default/x.js' => "require(['jquery-ui'], function () {});\n"],
                $plugin + ['views/default/x.js' => "import \$ from 'jquery';\n"],
            ],
            // --- FC-4x5x-07 ---
            'subtype-assignment' => ['5.x',
                $plugin + ['classes/E.php' => "<?php\n\$entity->subtype = 'blog';\n"],
                $plugin + ['classes/E.php' => "<?php\n\$entity->setSubtype('blog');\n"],
            ],
            // --- FC-4x5x-08 ---
            'test-mock-constructor' => ['5.x',
                $plugin + ['tests/AThingTest.php' => "<?php\n\$m = \$this->getMockBuilder(Event::class)->getMock();\n"],
                $plugin + ['tests/AThingTest.php' => "<?php\n\$m = \$this->getMockBuilder(Event::class)->disableOriginalConstructor()->getMock();\n"],
            ],
            // --- FC-5x6x-03 ---
            'seed-abstract-methods' => ['6.x',
                $plugin + ['classes/Seeder.php' => "<?php\nclass Seeder extends \\Elgg\\Database\\Seeds\\Seed {\n  public function up() {}\n  public function down() {}\n}\n"],
                $plugin + ['classes/Seeder.php' => "<?php\nclass Seeder extends \\Elgg\\Database\\Seeds\\Seed {\n  public static function getType(): string { return 'object'; }\n  public function getCountOptions(): array { return []; }\n  public function up() {}\n  public function down() {}\n}\n"],
            ],
            // --- FC-6x7x-01 ---
            'removed-constant' => ['7.x',
                $plugin + ['classes/C.php' => "<?php\n\$flags = ELGG_CACHE_PERSISTENT;\n"],
                $plugin + ['classes/C.php' => "<?php\n\$flags = ELGG_CACHE_RUNTIME;\n"],
            ],
            // --- FC-6x7x-03 ---
            'menu-add-value' => ['7.x',
                $plugin + ['classes/M.php' => "<?php\nfunction m(\$return) { \$return->add(\\ElggMenuItem::factory([])); return \$return; }\n"],
                $plugin + ['classes/M.php' => "<?php\nfunction m(\$return) { \$return[] = \\ElggMenuItem::factory([]); return \$return; }\n"],
            ],
            // --- FC-6x7x-05 ---
            'css-view-relocation' => ['7.x',
                $plugin + ['views/default/css/elements/forms.php' => "<?php\n// legacy css view\n"],
                $plugin + ['views/default/elements/forms.css' => ".elgg-form {}\n"],
            ],
            // --- FC-6x7x-06 ---
            'esm-bare-specifier' => ['7.x',
                $plugin + ['views/default/x.mjs' => "import init from 'framework/gallery/init';\n"],
                $plugin + ['views/default/x.mjs' => "import init from 'js/framework/gallery/init';\n"],
            ],
            // --- FC-6x7x-07 ---
            'jquery-global' => ['7.x',
                $plugin + ['views/default/x.js' => "jQuery('.foo').hide();\n"],
                $plugin + ['views/default/x.js' => "import \$ from 'jquery';\n\$('.foo').hide();\n"],
            ],
            // --- FC-6x7x-08 ---
            'i18n-named-import' => ['7.x',
                $plugin + ['views/default/x.js' => "import { echo } from 'elgg/i18n';\n"],
                $plugin + ['views/default/x.js' => "import i18n from 'elgg/i18n';\n"],
            ],
            // --- FC-6x7x-09 ---
            'empty-format-element' => ['7.x',
                $plugin + ['classes/F.php' => "<?php\necho elgg_format_element('', \$attrs);\n"],
                $plugin + ['classes/F.php' => "<?php\necho elgg_format_element('div', \$attrs);\n"],
            ],
            // --- FC-6x7x-10 ---
            'dbal-colon-param' => ['7.x',
                $plugin + ['classes/D.php' => "<?php\n\$db->executeStatement(\$sql, [':relationship_id' => \$id]);\n"],
                $plugin + ['classes/D.php' => "<?php\n\$db->executeStatement(\$sql, ['relationship_id' => \$id]);\n"],
            ],
            // --- FC-6x7x-11 ---
            'canwrite-null-subtype' => ['7.x',
                $plugin + ['classes/W.php' => "<?php\n\$user->canWriteToContainer(\$guid, 'object', null);\n"],
                $plugin + ['classes/W.php' => "<?php\n\$user->canWriteToContainer(\$guid, 'object', 'blog');\n"],
            ],
            // --- FC-6x7x-12 ---
            'unbraced-method-interpolation' => ['7.x',
                $plugin + ['classes/U.php' => "<?php\n\$v = \"members/listing/\$event->getType()\";\n"],
                $plugin + ['classes/U.php' => "<?php\n\$v = \"members/listing/{\$event->getType()}\";\n"],
            ],
            // --- FC-6x7x-13 ---
            'admin-password-length' => ['7.x',
                $plugin + ['install.sh' => "elgg-cli install --password=short123\n"],
                $plugin + ['install.sh' => "elgg-cli install --password=averylongadminpassword32\n"],
            ],
            // --- version-agnostic: composer version field ---
            'composer-version-field' => ['4.x',
                $plugin + ['composer.json' => "{\n  \"name\": \"acme/thing\",\n  \"version\": \"1.0.0\"\n}\n"],
                $plugin + ['composer.json' => "{\n  \"name\": \"acme/thing\"\n}\n"],
            ],
            // --- version-agnostic: docblock terminator ---
            'docblock-terminator' => ['4.x',
                $plugin + ['classes/DB.php' => "<?php\n/**\n * doc\n */ */\nfunction f() {}\n"],
                $plugin + ['classes/DB.php' => "<?php\n/**\n * doc\n */\nfunction f() {}\n"],
            ],
            // --- FC-ALL-05 ---
            'route-rewrite-timing' => ['4.x',
                ['elgg-plugin.php' => "<?php\nreturn ['events' => ['route:rewrite' => ['all' => ['Handler' => 'x']]]];\n"],
                ['elgg-plugin.php' => "<?php\nreturn ['events' => ['ready' => ['system' => ['Handler' => 'x']]]];\n"],
            ],
            // --- FC-ALL-06 ---
            'elgg-plugin-side-effects' => ['4.x',
                ['elgg-plugin.php' => "<?php\nmkdir('/tmp/x');\nreturn [];\n"],
                ['elgg-plugin.php' => "<?php\nreturn ['plugin' => []];\n"],
            ],
            // --- FC-ALL-07 ---
            'lib-functions-autoload' => ['4.x',
                [
                    'lib/functions.php' => "<?php\nfunction my_helper() { return 1; }\n",
                    'elgg-plugin.php' => "<?php\nreturn [];\n",
                ],
                [
                    'lib/functions.php' => "<?php\nfunction my_helper() { return 1; }\n",
                    'elgg-plugin.php' => "<?php\nrequire_once __DIR__ . '/lib/functions.php';\nreturn [];\n",
                ],
            ],
            // --- FC-ALL-08 ---
            'unguarded-optional-dep' => ['4.x',
                $plugin + ['classes/O.php' => "<?php\nfunction o() { return elgg_get_shortcodes(); }\n"],
                $plugin + ['classes/O.php' => "<?php\nfunction o() { return function_exists('elgg_get_shortcodes') ? elgg_get_shortcodes() : []; }\n"],
            ],
        ];
    }

    public static function positiveCases(): \Generator
    {
        foreach (self::cases() as $category => [$target, $positive, $_negative]) {
            yield $category => [$category, $target, $positive];
        }
    }

    public static function negativeCases(): \Generator
    {
        foreach (self::cases() as $category => [$target, $_positive, $negative]) {
            yield $category => [$category, $target, $negative];
        }
    }

    private function makePluginDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-fc-' . uniqid();
        mkdir($dir, 0755, true);
        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }
            file_put_contents($path, $content);
        }
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
