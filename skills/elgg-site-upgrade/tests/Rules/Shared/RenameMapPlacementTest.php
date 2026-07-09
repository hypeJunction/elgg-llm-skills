<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\Shared;

use PHPUnit\Framework\TestCase;

/**
 * Enforces that references/removed-function-renames.json (the auto-rewrite side,
 * a curated subset) stays reconciled with references/removed-functions.json (the
 * core-verified detection side): every rename entry must sit in the SAME major
 * block as its removal floor. A drift means the rename fires at the wrong
 * migration step — the exact class of bug fixed under bd elgg-migrate-jfrc1,
 * where the 5.x message/redirect family sat in the 6.x block.
 */
final class RenameMapPlacementTest extends TestCase
{
    private const RENAMES = __DIR__ . '/../../../references/removed-function-renames.json';
    private const REMOVED = __DIR__ . '/../../../references/removed-functions.json';

    /** @return array<string, array<string, string>> */
    private function renames(): array
    {
        $data = json_decode((string) file_get_contents(self::RENAMES), true);
        $this->assertIsArray($data);
        unset($data['_meta']);
        return $data;
    }

    /** @return array<string, array<string, string>> */
    private function removed(): array
    {
        $data = json_decode((string) file_get_contents(self::REMOVED), true);
        $this->assertIsArray($data);
        unset($data['_meta']);
        return $data;
    }

    public function testEveryRenameSitsInItsCoreVerifiedRemovalMajor(): void
    {
        $renames = $this->renames();
        $removed = $this->removed();

        foreach ($renames as $major => $map) {
            $this->assertIsArray($map, "renames block {$major} must be an object");
            foreach ($map as $old => $new) {
                // Detection side must know this removal at exactly this major.
                $this->assertArrayHasKey(
                    $major,
                    $removed,
                    "removed-functions.json has no {$major} block for {$old}",
                );
                $this->assertArrayHasKey(
                    $old,
                    $removed[$major],
                    "{$old}() is renamed in removed-function-renames.json['{$major}'] but is NOT "
                    . "listed as a {$major} removal in removed-functions.json — placement drift.",
                );

                // It must not ALSO be filed under a different removal major.
                foreach ($removed as $otherMajor => $set) {
                    if ($otherMajor === $major) {
                        continue;
                    }
                    $this->assertArrayNotHasKey(
                        $old,
                        $set,
                        "{$old}() is removed at both {$major} and {$otherMajor} in removed-functions.json",
                    );
                }

                // Replacement must be a bare global-function name (a true 1:1 rename).
                $this->assertMatchesRegularExpression(
                    '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/',
                    $new,
                    "rename target for {$old}() must be a bare function name, got '{$new}'",
                );
            }
        }
    }

    /**
     * Locks in the specific reconciliation from bd elgg-migrate-jfrc1 so a future
     * edit cannot silently re-file these back into the wrong block.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function expectedPlacementProvider(): array
    {
        return [
            // message/redirect family — core-verified 5.x removals
            'forward @5.x'            => ['forward', '5.x'],
            'register_error @5.x'     => ['register_error', '5.x'],
            'system_message @5.x'     => ['system_message', '5.x'],
            'current_page_url @5.x'   => ['current_page_url', '5.x'],
            'elgg_get_version @5.x'   => ['elgg_get_version', '5.x'],
            // 3.x removals
            'elgg_redirect @3.x'      => ['elgg_redirect', '3.x'],
            '_elgg_rmdir @3.x'        => ['_elgg_rmdir', '3.x'],
            '_elgg_html_decode @3.x'  => ['_elgg_html_decode', '3.x'],
            'logged_in_user @3.x'     => ['elgg_get_logged_in_user', '3.x'],
            // 4.x removal
            'elgg_flush_caches @4.x'  => ['elgg_flush_caches', '4.x'],
            // genuine 6.x removals stay put
            'trigger_hook @6.x'       => ['elgg_trigger_plugin_hook', '6.x'],
            'elgg_strrchr @6.x'       => ['elgg_strrchr', '6.x'],
        ];
    }

    /**
     * @dataProvider expectedPlacementProvider
     */
    public function testKnownSymbolsAreInExpectedBlock(string $symbol, string $expectedMajor): void
    {
        $renames = $this->renames();
        foreach ($renames as $major => $map) {
            if (isset($map[$symbol])) {
                $this->assertSame(
                    $expectedMajor,
                    $major,
                    "{$symbol}() must be renamed in the {$expectedMajor} block, found in {$major}",
                );
                return;
            }
        }
        $this->fail("{$symbol}() is missing from removed-function-renames.json entirely");
    }

    public function testMessageRedirectFamilyIsNotInSixOrSevenXBlock(): void
    {
        $renames = $this->renames();
        foreach (['5.x-family' => ['forward', 'register_error', 'system_message', 'current_page_url', 'elgg_get_version']] as $set) {
            foreach ($set as $symbol) {
                $this->assertArrayNotHasKey($symbol, $renames['6.x'] ?? [], "{$symbol}() must not be in the 6.x block");
                $this->assertArrayNotHasKey($symbol, $renames['7.x'] ?? [], "{$symbol}() must not be in the 7.x block");
            }
        }
    }
}
