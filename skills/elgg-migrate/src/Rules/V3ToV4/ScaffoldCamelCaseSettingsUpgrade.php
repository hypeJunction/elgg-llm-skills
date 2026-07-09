<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;

/**
 * Scaffolds an Elgg\Upgrade\Batch that rescues plugin settings stranded by the
 * 3.x → 4.x plugin ID lowercasing.
 *
 * Elgg 4.x requires lowercase plugin IDs. During the core upgrade Elgg creates a
 * *fresh* plugin entity for the lowercase ID rather than renaming the existing
 * camelCase one. Settings live as metadata on the plugin entity, so every stored
 * setting stays behind on the orphaned camelCase entity and the active plugin
 * silently falls back to the `settings` defaults in elgg-plugin.php.
 *
 * Rule 029 (lowercase-plugin-id-callsites) fixes the *code* side of this break.
 * This rule fixes the *data* side.
 *
 * Detection: the plugin's legacy ID (directory name / composer installer-name /
 * elgg-plugin.php `plugin.id`) contains an uppercase character. A plugin that was
 * already lowercase in 3.x never had its entity duplicated and needs no upgrade.
 *
 * Priority 206: immediately after 029, so both halves of the same breaking change
 * land together.
 */
final class ScaffoldCamelCaseSettingsUpgrade extends AbstractRule
{
    private const UPGRADE_CLASS = 'MigrateCamelCasePluginSettings';

    public function getId(): string
    {
        return 'migrate-camelcase-plugin-settings';
    }

    public function getDescription(): string
    {
        return 'Scaffold an upgrade batch that copies plugin settings from the legacy camelCase plugin entity onto the lowercase one';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $legacyId = $this->detectLegacyPluginId($pluginPath);

        if ($legacyId === null) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'Plugin ID is already lowercase — Elgg 4.x did not duplicate the plugin entity, no settings can be stranded',
            );
        }

        if (!is_file($pluginPath . '/elgg-plugin.php')) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No elgg-plugin.php found — cannot register a settings-migration upgrade',
            );
        }

        if ($this->upgradeAlreadyExists($pluginPath)) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: sprintf('%s already exists — skipping', self::UPGRADE_CLASS),
            );
        }

        $finding = new Finding(
            file: 'elgg-plugin.php',
            line: 0,
            description: sprintf(
                "Plugin ID '%s' lowercases to '%s' — Elgg 4.x will create a new plugin entity and strand every stored setting on the old one",
                $legacyId,
                strtolower($legacyId),
            ),
            code: sprintf("legacy_id=%s new_id=%s", $legacyId, strtolower($legacyId)),
        );

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: true,
            findings: [$finding],
            summary: sprintf(
                "Will scaffold %s to copy settings from the '%s' entity onto '%s'",
                self::UPGRADE_CLASS,
                $legacyId,
                strtolower($legacyId),
            ),
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $legacyId = $this->detectLegacyPluginId($pluginPath);

        if ($legacyId === null) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['Plugin ID is already lowercase — no settings-migration upgrade scaffolded'],
            );
        }

        if (!is_file($pluginPath . '/elgg-plugin.php')) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No elgg-plugin.php found — no settings-migration upgrade scaffolded'],
            );
        }

        if ($this->upgradeAlreadyExists($pluginPath)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: [sprintf('%s already exists — skipping scaffold', self::UPGRADE_CLASS)],
            );
        }

        $namespace = $this->resolveRootNamespace($pluginPath);

        if ($namespace === null) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: false,
                changes: [],
                errors: ['Cannot resolve PSR-4 root namespace from composer.json — cannot scaffold ' . self::UPGRADE_CLASS],
            );
        }

        $changes = [];
        $warnings = [];

        $relPath = $this->upgradeRelativePath($namespace);
        $absPath = $pluginPath . '/' . $relPath;
        $dir = dirname($absPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($absPath, $this->generateUpgradeClass($namespace, $legacyId));

        $changes[] = new FileChange(
            file: $relPath,
            type: 'created',
            description: sprintf(
                "Scaffolded %s to rescue settings stranded on the '%s' plugin entity",
                self::UPGRADE_CLASS,
                $legacyId,
            ),
        );

        if ($this->registerInPluginPhp($pluginPath, $namespace)) {
            $changes[] = new FileChange(
                file: 'elgg-plugin.php',
                type: 'modified',
                description: "Registered {$this->upgradeFqcn($namespace)} under 'upgrades'",
            );
        } else {
            $warnings[] = sprintf(
                "Could not auto-register the upgrade in elgg-plugin.php — add manually: 'upgrades' => [%s::class]",
                $this->upgradeFqcn($namespace),
            );
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    // -------------------------------------------------------------------------
    // Detection
    // -------------------------------------------------------------------------

    /**
     * Return the plugin's legacy (pre-4.x) ID if it contains an uppercase
     * character, otherwise null.
     *
     * The plugin ID is the directory name. During a 3.x → 4.x migration the
     * directory may already have been renamed, so composer.json's installer-name
     * and elgg-plugin.php's declared id are consulted first — whichever source
     * still carries the original casing wins.
     */
    private function detectLegacyPluginId(string $pluginPath): ?string
    {
        foreach ($this->pluginIdCandidates($pluginPath) as $candidate) {
            if ($candidate !== '' && $candidate !== strtolower($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function pluginIdCandidates(string $pluginPath): array
    {
        $candidates = [];

        $composerPath = $pluginPath . '/composer.json';
        if (is_file($composerPath)) {
            $data = json_decode((string) file_get_contents($composerPath), true);
            if (is_array($data)) {
                $installerName = $data['extra']['installer-name'] ?? null;
                if (is_string($installerName)) {
                    $candidates[] = $installerName;
                }

                $packageName = $data['name'] ?? '';
                if (is_string($packageName) && str_contains($packageName, '/')) {
                    $candidates[] = explode('/', $packageName)[1];
                }
            }
        }

        $declaredId = $this->declaredPluginId($pluginPath);
        if ($declaredId !== null) {
            $candidates[] = $declaredId;
        }

        $candidates[] = basename($pluginPath);

        return $candidates;
    }

    /**
     * Read `plugin.id` out of elgg-plugin.php, if declared.
     */
    private function declaredPluginId(string $pluginPath): ?string
    {
        $pluginPhpPath = $pluginPath . '/elgg-plugin.php';
        if (!is_file($pluginPhpPath)) {
            return null;
        }

        $ast = $this->parse((string) file_get_contents($pluginPhpPath));
        if ($ast === null) {
            return null;
        }

        foreach ($ast as $stmt) {
            if (!$stmt instanceof Node\Stmt\Return_) continue;
            if (!$stmt->expr instanceof Node\Expr\Array_) continue;

            $pluginSection = $this->findArrayKey($stmt->expr, 'plugin');
            if ($pluginSection instanceof Node\Expr\Array_) {
                $id = $this->findArrayKey($pluginSection, 'id');
                if ($id instanceof Node\Scalar\String_) {
                    return $id->value;
                }
            }
        }

        return null;
    }

    private function upgradeAlreadyExists(string $pluginPath): bool
    {
        $classesDir = $pluginPath . '/classes';
        if (!is_dir($classesDir)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($classesDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getBasename() === self::UPGRADE_CLASS . '.php') {
                return true;
            }
        }

        return false;
    }

    private function findArrayKey(Node\Expr\Array_ $array, string $key): ?Node\Expr
    {
        foreach ($array->items as $item) {
            if (!$item instanceof Node\Expr\ArrayItem) continue;
            if (!$item->key instanceof Node\Scalar\String_) continue;
            if ($item->key->value === $key) {
                return $item->value;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Namespace resolution
    // -------------------------------------------------------------------------

    private function resolveRootNamespace(string $pluginPath): ?string
    {
        $composerPath = $pluginPath . '/composer.json';
        if (!is_file($composerPath)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($data)) {
            return null;
        }

        $psr4 = $data['autoload']['psr-4'] ?? [];
        if (empty($psr4)) {
            return null;
        }

        foreach ($psr4 as $ns => $path) {
            $normalizedPath = rtrim((string) $path, '/');
            if ($normalizedPath === 'classes' || $normalizedPath === '' || str_starts_with($normalizedPath, 'classes/')) {
                return rtrim((string) $ns, '\\');
            }
        }

        return rtrim((string) array_key_first($psr4), '\\');
    }

    private function upgradeRelativePath(string $namespace): string
    {
        $classPath = implode('/', explode('\\', $namespace));

        return sprintf('classes/%s/Upgrades/%s.php', $classPath, self::UPGRADE_CLASS);
    }

    private function upgradeFqcn(string $namespace): string
    {
        return '\\' . $namespace . '\\Upgrades\\' . self::UPGRADE_CLASS;
    }

    // -------------------------------------------------------------------------
    // Code generation
    // -------------------------------------------------------------------------

    /**
     * Generate the upgrade batch.
     *
     * Emitted against the 3.x/4.x `implements \Elgg\Upgrade\Batch` shape. The
     * 4.x → 5.x rule 018-upgrade-class-changes rewrites it to extend
     * \Elgg\Upgrade\AsynchronousUpgrade when the plugin moves on; the method set
     * and signatures are identical either way.
     *
     * The batch is deliberately non-destructive: it disables the drained legacy
     * entity rather than deleting it, so a mis-copied setting can still be
     * recovered. Delete the disabled entities once the site is verified.
     */
    private function generateUpgradeClass(string $namespace, string $legacyId): string
    {
        $upgradeNs = $namespace . '\\Upgrades';
        $pluginId = strtolower($legacyId);
        $version = date('Ymd') . '00';
        $class = self::UPGRADE_CLASS;

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$upgradeNs};

use Elgg\Upgrade\Batch;
use Elgg\Upgrade\Result;

/**
 * Copies plugin settings stranded on the legacy camelCase plugin entity onto the
 * lowercase plugin entity that Elgg 4.x created during the 3.x upgrade.
 *
 * Elgg 4.x requires lowercase plugin IDs. Core creates a *fresh* plugin entity for
 * the lowercase ID instead of renaming the camelCase one. Plugin settings are stored
 * as metadata on the plugin entity, so every stored setting stays behind on the old
 * entity and {$pluginId} silently runs on the defaults declared in elgg-plugin.php.
 */
class {$class} implements Batch {

    /**
     * Plugin ID as it was spelled before Elgg 4.x required lowercase IDs.
     */
    const LEGACY_PLUGIN_ID = '{$legacyId}';

    /**
     * Current plugin ID.
     */
    const PLUGIN_ID = '{$pluginId}';

    public function getVersion(): int {
        return {$version};
    }

    public function shouldBeSkipped(): bool {
        return !\$this->getLegacyPlugin() instanceof \ElggPlugin;
    }

    public function needsIncrementOffset(): bool {
        return false;
    }

    public function countItems(): int {
        \$legacy = \$this->getLegacyPlugin();
        if (!\$legacy instanceof \ElggPlugin) {
            return 0;
        }

        return count(\$this->getStrandedSettings(\$legacy));
    }

    public function run(Result \$result, \$offset): Result {
        \$legacy = \$this->getLegacyPlugin();
        \$target = elgg_get_plugin_from_id(self::PLUGIN_ID);

        if (!\$legacy instanceof \ElggPlugin || !\$target instanceof \ElggPlugin) {
            \$result->markComplete();

            return \$result;
        }

        elgg_call(ELGG_IGNORE_ACCESS | ELGG_SHOW_DISABLED_ENTITIES, function () use (\$legacy, \$target, \$result) {
            \$already_set = \$target->getAllMetadata();

            foreach (\$this->getStrandedSettings(\$legacy) as \$name => \$value) {
                // An admin may have re-entered the value on the new entity already.
                if (array_key_exists(\$name, \$already_set)) {
                    \$result->addSuccesses(1);
                    continue;
                }

                if (\$target->setSetting(\$name, \$value)) {
                    \$result->addSuccesses(1);
                } else {
                    \$result->addFailures(1);
                    \$result->addError("Could not copy setting '{\$name}' from " . self::LEGACY_PLUGIN_ID);
                }
            }

            // Drained. The legacy entity has no directory on disk and is unreachable
            // from the plugin list; disabling hides it without discarding the data.
            // Delete the disabled entity by hand once the site is verified.
            if (\$result->getFailureCount() === 0 && \$legacy->isEnabled()) {
                \$legacy->disable();
            }
        });

        \$result->markComplete();

        return \$result;
    }

    /**
     * Find the orphaned camelCase plugin entity, if it still exists.
     */
    protected function getLegacyPlugin(): ?\ElggPlugin {
        if (self::LEGACY_PLUGIN_ID === self::PLUGIN_ID) {
            return null;
        }

        return elgg_call(ELGG_IGNORE_ACCESS | ELGG_SHOW_DISABLED_ENTITIES, function () {
            \$candidates = elgg_get_entities([
                'type' => 'object',
                'subtype' => 'plugin',
                'limit' => false,
                'metadata_name_value_pairs' => [
                    ['name' => 'title', 'value' => self::LEGACY_PLUGIN_ID],
                ],
            ]);

            foreach (\$candidates as \$candidate) {
                // The database collation is case-insensitive, so the lowercase twin
                // matches the query above too. Compare case-sensitively in PHP.
                if (\$candidate instanceof \ElggPlugin && \$candidate->getID() === self::LEGACY_PLUGIN_ID) {
                    return \$candidate;
                }
            }

            return null;
        });
    }

    /**
     * Settings held as metadata on the legacy entity.
     *
     * @return array<string, mixed>
     */
    protected function getStrandedSettings(\ElggPlugin \$legacy): array {
        \$settings = elgg_call(ELGG_SHOW_DISABLED_ENTITIES, function () use (\$legacy) {
            return \$legacy->getAllMetadata();
        });

        unset(\$settings['title'], \$settings['description']);

        foreach (\$settings as \$name => \$value) {
            // Internal bookkeeping such as elgg:internal:priority is not a setting,
            // and copying it would reorder the plugin list.
            if (strpos(\$name, 'elgg:internal:') === 0) {
                unset(\$settings[\$name]);
                continue;
            }

            // Plugin settings cannot hold arrays, so a multi-value metadata name
            // cannot have come from setSetting() and must not be copied back.
            if (is_array(\$value)) {
                unset(\$settings[\$name]);
            }
        }

        return \$settings;
    }
}
PHP;
    }

    // -------------------------------------------------------------------------
    // elgg-plugin.php registration
    // -------------------------------------------------------------------------

    private function registerInPluginPhp(string $pluginPath, string $namespace): bool
    {
        $pluginPhpPath = $pluginPath . '/elgg-plugin.php';
        $code = file_get_contents($pluginPhpPath);
        if ($code === false) {
            return false;
        }

        if (str_contains($code, self::UPGRADE_CLASS)) {
            return true;
        }

        $fqcn = $this->upgradeFqcn($namespace) . '::class';

        $injected = $this->injectIntoExistingUpgrades($code, $fqcn)
            ?? $this->appendUpgradesSection($code, $fqcn);

        if ($injected === null) {
            return false;
        }

        file_put_contents($pluginPhpPath, $injected);

        return true;
    }

    /**
     * Append to an existing `'upgrades' => [...]` array.
     */
    private function injectIntoExistingUpgrades(string $code, string $fqcn): ?string
    {
        $new = preg_replace_callback(
            "/'upgrades'\s*=>\s*\[([^\]]*)\]/s",
            static function (array $m) use ($fqcn): string {
                $indent = '        ';
                if (trim($m[1]) === '') {
                    return "'upgrades' => [\n{$indent}{$fqcn},\n    ]";
                }

                return "'upgrades' => [{$m[1]}{$indent}{$fqcn},\n    ]";
            },
            $code,
            1,
        );

        if ($new === null || $new === $code) {
            return null;
        }

        return $new;
    }

    /**
     * Append a new `'upgrades'` section before the closing `];` of the return array.
     */
    private function appendUpgradesSection(string $code, string $fqcn): ?string
    {
        $lastBracket = strrpos($code, '];');
        if ($lastBracket === false) {
            return null;
        }

        $section = <<<PHP
    'upgrades' => [
        {$fqcn},
    ],
PHP;

        return substr($code, 0, $lastBracket) . $section . "\n" . substr($code, $lastBracket);
    }
}
