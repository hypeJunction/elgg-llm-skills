<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\PostMigrationVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves the PostMigrationVerifier gate DETECTS every 6.x -> 7.x failure class
 * documented in references/migration-failure-catalog.md (FC-6x7x-01 .. -13).
 *
 * Each class gets a FLAGGED fixture (minimal reproduction of the failure
 * signature, asserted to raise the expected violation category at a 7.x target)
 * and a CLEAN fixture (the catalog's prescribed fix, asserted NOT to raise it).
 *
 * The 6.x-only checks are guarded by `$major >= 7` in PostMigrationVerifier, so
 * every FLAGGED case targets '7.x'. Mirrors PostMigrationVerifierTest style.
 */
final class PostMigrationVerifier6xTo7xTest extends TestCase
{
    private PostMigrationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PostMigrationVerifier();
    }

    // --- FC-6x7x-01: ELGG_CACHE_PERSISTENT constant removed (removed-constant) ---

    public function testFC6x7x01CatchesRemovedCacheConstant(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Cache.php' => "<?php\nnamespace Acme;\nclass Cache {\n    public function mode() {\n        return ELGG_CACHE_PERSISTENT;\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertFalse($result->passed);
            $this->assertHasCategory($result->violations, 'removed-constant');
            $this->assertMessagesContain($result->violations, 'ELGG_CACHE_PERSISTENT');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x01CleanCacheConstantNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Cache.php' => "<?php\nnamespace Acme;\nclass Cache {\n    public function mode() {\n        return ELGG_CACHE_RUNTIME;\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'removed-constant');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-02: elgg_new_entity() removed / ElggObject abstract (removed-function) ---

    public function testFC6x7x02CatchesElggNewEntity(): void
    {
        $dir = $this->makePluginDir([
            'lib/factory.php' => "<?php\n\$obj = elgg_new_entity('object', 'my_subtype');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertFalse($result->passed);
            $this->assertHasCategory($result->violations, 'removed-function');
            $this->assertMessagesContain($result->violations, 'elgg_new_entity');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x02CleanUndefinedObjectNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'lib/factory.php' => "<?php\n\$obj = new \\ElggUndefinedObject();\n\$obj->subtype = 'my_subtype';\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'removed-function');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-03: menu register value is an array, not ->add() (menu-add-value) ---

    public function testFC6x7x03CatchesMenuAddValue(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Menus.php' => "<?php\nnamespace Acme;\nclass Menus {\n    public static function extend(\\Elgg\\Event \$event) {\n        \$return = \$event->getValue();\n        \$return->add(\\ElggMenuItem::factory(['name' => 'x', 'text' => 'X']));\n        return \$return;\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertFalse($result->passed);
            $this->assertHasCategory($result->violations, 'menu-add-value');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x03CleanArrayPushMenuNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Menus.php' => "<?php\nnamespace Acme;\nclass Menus {\n    public static function extend(\\Elgg\\Event \$event) {\n        \$return = \$event->getValue();\n        \$return[] = \\ElggMenuItem::factory(['name' => 'x', 'text' => 'X']);\n        return \$return;\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'menu-add-value');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-04: 7.x global function removals (removed-function) ---

    public function testFC6x7x04CatchesRemovedGlobals(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Admin.php' => "<?php\nnamespace Acme;\nclass Admin {\n    public function run(\$guid) {\n        if (elgg_is_admin_user(\$guid)) {\n            elgg_reset_system_cache();\n        }\n        return elgg_get_entities_from_relationship(['relationship' => 'friend']);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertFalse($result->passed);
            $this->assertHasCategory($result->violations, 'removed-function');
            $joined = $this->messages($result->violations);
            $this->assertStringContainsString('elgg_is_admin_user', $joined);
            $this->assertStringContainsString('elgg_reset_system_cache', $joined);
            $this->assertStringContainsString('elgg_get_entities_from_relationship', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x04ReplacementsNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Admin.php' => "<?php\nnamespace Acme;\nclass Admin {\n    public function run(\$guid) {\n        \$user = elgg_get_entity(\$guid);\n        if (\$user instanceof \\ElggUser && \$user->isAdmin()) {\n            _elgg_services()->systemCache->clear();\n        }\n        return elgg_get_entities(['relationship' => 'friend']);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'removed-function');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x04RemovedGlobalsAllowedBelow7x(): void
    {
        // Boundary: elgg_reset_system_cache() is a verified 7.x removal — it still
        // exists at 6.x, so it must NOT flag at a 6.x target. (elgg_is_admin_user
        // is NOT a valid boundary probe here: core-verified removed at 4.x, so it
        // is already flagged at a 6.x target via the cumulative set.)
        $dir = $this->makePluginDir([
            'classes/Acme/Admin.php' => "<?php\nnamespace Acme;\nclass Admin {\n    public function run() {\n        return elgg_reset_system_cache();\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $joined = $this->messages($result->violations);
            $this->assertStringNotContainsString('elgg_reset_system_cache', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-05: CSS view relocation (css-view-relocation) ---

    public function testFC6x7x05CatchesOrphanedCssViewOverride(): void
    {
        $dir = $this->makePluginDir([
            'views/default/css/elements/buttons.php' => "<?php\n// legacy button styling\n.elgg-button { color: red; }\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertHasCategory($result->violations, 'css-view-relocation');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x05RelocatedCssNotFlagged(): void
    {
        // The 7.x home for the override is views/default/elements/buttons.css —
        // no css/elements/*.php override remains.
        $dir = $this->makePluginDir([
            'views/default/elements/buttons.css' => ".elgg-button { color: red; }\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'css-view-relocation');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-06: ESM bare specifier missing js/ prefix (esm-bare-specifier) ---

    public function testFC6x7x06CatchesBareEsmSpecifier(): void
    {
        $dir = $this->makePluginDir([
            'views/default/framework/gallery.mjs' => "import init from 'framework/gallery/init';\ninit();\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertHasCategory($result->violations, 'esm-bare-specifier');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x06PrefixedEsmSpecifierNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'views/default/framework/gallery.mjs' => "import init from 'js/framework/gallery/init';\ninit();\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'esm-bare-specifier');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-07: reliance on global jQuery (jquery-global) ---

    public function testFC6x7x07CatchesGlobalJquery(): void
    {
        $dir = $this->makePluginDir([
            'views/default/myplugin/tabs.js' => "jQuery('.tabs').tabs();\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertHasCategory($result->violations, 'jquery-global');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x07ImportedJqueryNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'views/default/myplugin/tabs.mjs' => "import jq from 'jquery';\nwindow.jQuery = jq;\njQuery('.tabs').tabs();\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'jquery-global');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-08: named import from elgg/i18n (i18n-named-import) ---

    public function testFC6x7x08CatchesNamedI18nImport(): void
    {
        $dir = $this->makePluginDir([
            'views/default/myplugin/init.mjs' => "import { echo } from 'elgg/i18n';\necho('foo');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertFalse($result->passed);
            $this->assertHasCategory($result->violations, 'i18n-named-import');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x08DefaultI18nImportNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'views/default/myplugin/init.mjs' => "import i18n from 'elgg/i18n';\nconst echo = (...a) => i18n.echo(...a);\necho('foo');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'i18n-named-import');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-09: elgg_format_element('') empty tag (empty-format-element) ---

    public function testFC6x7x09CatchesEmptyFormatElement(): void
    {
        $dir = $this->makePluginDir([
            'views/default/myplugin/field.php' => "<?php\necho elgg_format_element('', ['value' => \$vars['value']]);\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertFalse($result->passed);
            $this->assertHasCategory($result->violations, 'empty-format-element');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x09NonEmptyFormatElementNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'views/default/myplugin/field.php' => "<?php\necho elgg_format_element('div', ['value' => \$vars['value']]);\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'empty-format-element');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-10: DBAL named-param key carries a colon (dbal-colon-param) ---

    public function testFC6x7x10CatchesColonNamedParam(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Repo.php' => "<?php\nnamespace Acme;\nclass Repo {\n    public function delete(\$qb, \$id) {\n        return \$qb->executeStatement('DELETE FROM t WHERE id = :rid', [':rid' => \$id]);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertHasCategory($result->violations, 'dbal-colon-param');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x10ColonlessNamedParamNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Repo.php' => "<?php\nnamespace Acme;\nclass Repo {\n    public function delete(\$qb, \$id) {\n        return \$qb->executeStatement('DELETE FROM t WHERE id = :rid', ['rid' => \$id]);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'dbal-colon-param');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-11: canWriteToContainer() null subtype (canwrite-null-subtype) ---

    public function testFC6x7x11CatchesNullSubtypeCanWrite(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Groups.php' => "<?php\nnamespace Acme;\nclass Groups {\n    public function can(\$user, \$container) {\n        return \$user->canWriteToContainer(\$container->guid, 'group', null);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertHasCategory($result->violations, 'canwrite-null-subtype');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x11ResolvedSubtypeCanWriteNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Groups.php' => "<?php\nnamespace Acme;\nuse Elgg\\Groups\\Group;\nclass Groups {\n    public function can(\$user, \$container) {\n        return \$user->canWriteToContainer(\$container->guid, 'group', Group::SUBTYPE);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'canwrite-null-subtype');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-12: unbraced method call in double-quoted string (unbraced-method-interpolation) ---

    public function testFC6x7x12CatchesUnbracedMethodInterpolation(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Router.php' => "<?php\nnamespace Acme;\nclass Router {\n    public function view(\$event) {\n        return elgg_view(\"members/listing/\$event->getType()\");\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertHasCategory($result->violations, 'unbraced-method-interpolation');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x12BracedMethodInterpolationNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Router.php' => "<?php\nnamespace Acme;\nclass Router {\n    public function view(\$event) {\n        return elgg_view(\"members/listing/{\$event->getType()}\");\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'unbraced-method-interpolation');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-13: install admin password < 16 chars (admin-password-length) ---

    public function testFC6x7x13CatchesShortAdminPassword(): void
    {
        $dir = $this->makePluginDir([
            'install.sh' => "#!/bin/sh\nelgg-cli install --admin-username=admin --password=short123\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertHasCategory($result->violations, 'admin-password-length');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x13LongAdminPasswordNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'install.sh' => "#!/bin/sh\nelgg-cli install --admin-username=admin --password=correcthorsebatterystaple\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'admin-password-length');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Helpers ---

    /** @param array<object> $violations */
    private function assertHasCategory(array $violations, string $category): void
    {
        $cats = array_map(fn($v) => $v->category, $violations);
        $this->assertContains(
            $category,
            $cats,
            "Expected a '{$category}' violation. Got: " . implode(', ', $cats),
        );
    }

    /** @param array<object> $violations */
    private function assertNotHasCategory(array $violations, string $category): void
    {
        $cats = array_map(fn($v) => $v->category, $violations);
        $this->assertNotContains(
            $category,
            $cats,
            "Did not expect a '{$category}' violation on the clean fixture.",
        );
    }

    /** @param array<object> $violations */
    private function messages(array $violations): string
    {
        return implode(' ', array_map(fn($v) => $v->message, $violations));
    }

    /** @param array<object> $violations */
    private function assertMessagesContain(array $violations, string $needle): void
    {
        $this->assertStringContainsString($needle, $this->messages($violations));
    }

    /** @param array<string,string> $files */
    private function makePluginDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dir, 0755, true);

        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            $parentDir = dirname($path);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
            file_put_contents($path, $content);
        }

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
