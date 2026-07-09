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
 * Scaffolds a <plugin_id>:doctor Symfony Console CLI command for post-migration data integrity checks.
 *
 * Detection:
 * - Reads elgg-plugin.php 'entities' key to discover owned types/subtypes.
 * - Skips if a DoctorCommand.php already exists under classes/.
 *
 * When applicable, the rule:
 * - Generates classes/<Namespace>/Cli/DoctorCommand.php extending \Elgg\Cli\Command
 * - Registers the command class in elgg-plugin.php under 'cli' => ['commands' => [...]]
 *
 * The generated command checks:
 * - Entity counts per owned type/subtype (sanity baseline)
 * - Completed Elgg\Upgrade\Batch scripts (no pending upgrades)
 * - Orphan relationship check stub
 * - Plugin-specific config invariants stub
 *
 * Priority 285: runs late, after entity/Bootstrap rules have run.
 */
final class ScaffoldDoctorCommand extends AbstractRule
{
    public function getId(): string
    {
        return 'scaffold-doctor-command';
    }

    public function getDescription(): string
    {
        return 'Scaffold <plugin_id>:doctor CLI command for post-migration data integrity';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        if (!is_file($pluginPath . '/elgg-plugin.php')) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No elgg-plugin.php found — cannot scaffold DoctorCommand',
            );
        }

        $entities = $this->detectOwnedEntities($pluginPath);

        if (empty($entities)) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No owned entity types/subtypes in elgg-plugin.php — DoctorCommand not required',
            );
        }

        if ($this->doctorCommandAlreadyExists($pluginPath)) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: sprintf(
                    'Found %d owned entity type(s) but DoctorCommand already exists — skipping',
                    count($entities),
                ),
            );
        }

        $findings = [];
        foreach ($entities as $entity) {
            $findings[] = new Finding(
                file: 'elgg-plugin.php',
                line: 0,
                description: sprintf(
                    'Plugin owns %s/%s — DoctorCommand should verify integrity after migration',
                    $entity['type'],
                    $entity['subtype'],
                ),
                code: sprintf("type=%s subtype=%s", $entity['type'], $entity['subtype']),
            );
        }

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: true,
            findings: $findings,
            summary: sprintf(
                'Plugin owns %d entity type(s) — will scaffold <plugin_id>:doctor CLI command',
                count($entities),
            ),
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        if (!is_file($pluginPath . '/elgg-plugin.php')) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No elgg-plugin.php found — DoctorCommand not scaffolded'],
            );
        }

        $entities = $this->detectOwnedEntities($pluginPath);

        if (empty($entities)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No owned entity types detected — DoctorCommand not scaffolded'],
            );
        }

        if ($this->doctorCommandAlreadyExists($pluginPath)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['DoctorCommand already exists — skipping scaffold'],
            );
        }

        $changes = [];
        $warnings = [];

        // Resolve namespace and plugin id
        $namespace = $this->resolveRootNamespace($pluginPath);
        $pluginId = $this->resolvePluginId($pluginPath);

        if ($namespace === null) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: false,
                changes: [],
                errors: ['Cannot resolve PSR-4 root namespace from composer.json — cannot scaffold DoctorCommand'],
            );
        }

        // --- 1. Generate DoctorCommand class ---
        $commandRelPath = $this->doctorCommandRelativePath($namespace);
        $commandAbsPath = $pluginPath . '/' . $commandRelPath;
        $commandDir = dirname($commandAbsPath);

        if (!is_dir($commandDir)) {
            mkdir($commandDir, 0755, true);
        }

        $commandContent = $this->generateDoctorCommandClass($namespace, $pluginId, $entities);
        file_put_contents($commandAbsPath, $commandContent);

        $changes[] = new FileChange(
            file: $commandRelPath,
            type: 'created',
            description: sprintf(
                'Scaffolded %s:doctor CLI command for %d owned entity type(s)',
                $pluginId,
                count($entities),
            ),
        );

        // --- 2. Register in elgg-plugin.php ---
        $registrationResult = $this->registerInPluginPhp($pluginPath, $namespace);
        if ($registrationResult) {
            $changes[] = new FileChange(
                file: 'elgg-plugin.php',
                type: 'modified',
                description: "Registered DoctorCommand under 'cli' => ['commands' => [...]]",
            );
        } else {
            $warnings[] = sprintf(
                "Could not auto-register DoctorCommand in elgg-plugin.php — add manually: 'cli' => ['commands' => [\\%s\\Cli\\DoctorCommand::class]]",
                $namespace,
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
    // Detection helpers
    // -------------------------------------------------------------------------

    /**
     * Return owned entity descriptors from elgg-plugin.php 'entities' key.
     *
     * @return array<array{type: string, subtype: string}>
     */
    private function detectOwnedEntities(string $pluginPath): array
    {
        $pluginPhpPath = $pluginPath . '/elgg-plugin.php';
        if (!file_exists($pluginPhpPath)) {
            return [];
        }

        $code = file_get_contents($pluginPhpPath);
        if ($code === false) return [];

        $ast = $this->parse($code);
        if ($ast === null) return [];

        // Walk AST to find top-level return [...] statement
        $topReturn = null;
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node\Expr\Array_) {
                $topReturn = $stmt->expr;
                break;
            }
        }

        if ($topReturn === null) return [];

        // Find 'entities' key
        $entitiesArray = $this->findArrayKey($topReturn, 'entities');
        if ($entitiesArray === null || !$entitiesArray instanceof Node\Expr\Array_) {
            return [];
        }

        $results = [];

        foreach ($entitiesArray->items as $item) {
            if (!$item instanceof Node\Expr\ArrayItem) continue;
            if (!$item->value instanceof Node\Expr\Array_) continue;

            $type = $this->extractStringKey($item->value, 'type');
            $subtype = $this->extractStringKey($item->value, 'subtype');

            if ($type === null || $subtype === null) continue;

            $results[] = ['type' => $type, 'subtype' => $subtype];
        }

        return $results;
    }

    /**
     * Check whether a DoctorCommand.php already exists under classes/.
     */
    private function doctorCommandAlreadyExists(string $pluginPath): bool
    {
        $classesDir = $pluginPath . '/classes';
        if (!is_dir($classesDir)) return false;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($classesDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getBasename() === 'DoctorCommand.php') {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // AST helpers for elgg-plugin.php
    // -------------------------------------------------------------------------

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

    private function extractStringKey(Node\Expr\Array_ $array, string $key): ?string
    {
        $value = $this->findArrayKey($array, $key);
        if ($value instanceof Node\Scalar\String_) {
            return $value->value;
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Namespace / plugin ID resolution
    // -------------------------------------------------------------------------

    private function resolveRootNamespace(string $pluginPath): ?string
    {
        $composerPath = $pluginPath . '/composer.json';
        if (!file_exists($composerPath)) return null;

        $data = json_decode(file_get_contents($composerPath), true);
        if (!is_array($data)) return null;

        $psr4 = $data['autoload']['psr-4'] ?? [];
        if (empty($psr4)) return null;

        foreach ($psr4 as $ns => $path) {
            $normalizedPath = rtrim((string) $path, '/');
            if ($normalizedPath === 'classes' || $normalizedPath === '' || str_starts_with($normalizedPath, 'classes/')) {
                return rtrim($ns, '\\');
            }
        }

        $ns = array_key_first($psr4);
        return rtrim((string) $ns, '\\');
    }

    private function resolvePluginId(string $pluginPath): string
    {
        // Try elgg-plugin.php 'plugin' => 'id'
        $pluginPhpPath = $pluginPath . '/elgg-plugin.php';
        if (file_exists($pluginPhpPath)) {
            $code = file_get_contents($pluginPhpPath);
            $ast = $this->parse($code ?: '');
            if ($ast !== null) {
                foreach ($ast as $stmt) {
                    if (!$stmt instanceof Node\Stmt\Return_) continue;
                    if (!$stmt->expr instanceof Node\Expr\Array_) continue;
                    $pluginSection = $this->findArrayKey($stmt->expr, 'plugin');
                    if ($pluginSection instanceof Node\Expr\Array_) {
                        $id = $this->extractStringKey($pluginSection, 'id');
                        if ($id !== null) return $id;
                    }
                }
            }
        }

        // Fall back to composer.json extra.installer-name
        $composerPath = $pluginPath . '/composer.json';
        if (file_exists($composerPath)) {
            $data = json_decode(file_get_contents($composerPath), true);
            if (is_array($data)) {
                $installerName = $data['extra']['installer-name'] ?? null;
                if (is_string($installerName)) return $installerName;
                $packageName = $data['name'] ?? '';
                if (str_contains($packageName, '/')) {
                    return explode('/', $packageName)[1];
                }
            }
        }

        return strtolower(basename($pluginPath));
    }

    // -------------------------------------------------------------------------
    // Code generation
    // -------------------------------------------------------------------------

    /**
     * Return the relative path for the generated DoctorCommand class.
     * e.g. "Notes\\" → "classes/Notes/Cli/DoctorCommand.php"
     */
    private function doctorCommandRelativePath(string $namespace): string
    {
        $parts = explode('\\', $namespace);
        $classPath = implode('/', $parts);
        return "classes/{$classPath}/Cli/DoctorCommand.php";
    }

    /**
     * Generate the full DoctorCommand class content.
     *
     * @param array<array{type: string, subtype: string}> $entities
     */
    private function generateDoctorCommandClass(string $namespace, string $pluginId, array $entities): string
    {
        $commandNs = $namespace . '\\Cli';
        $checksCode = $this->generateEntityCountChecks($pluginId, $entities);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$commandNs};

use Elgg\Cli\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Post-migration data integrity checks for the {$pluginId} plugin.
 *
 * Run with:
 *   php elgg-cli {$pluginId}:doctor
 */
class DoctorCommand extends Command {

    protected static \$defaultName = '{$pluginId}:doctor';

    protected function configure(): void {
        \$this->setDescription('Post-migration data integrity checks for {$pluginId}');
    }

    protected function command(InputInterface \$input, OutputInterface \$output): int {
        \$exitCode = self::SUCCESS;

{$checksCode}
        // Verify upgrades completed
        // TODO: check pending Elgg\\Upgrade\\Batch scripts for this plugin
        // Example: query elgg_entities for type='object' subtype='upgrade' with status != 'completed'

        // Orphan relationship check
        // TODO: check for relationships referencing non-existent entities owned by this plugin

        // Plugin-specific config invariants
        // TODO: verify expected plugin settings are set and valid

        if (\$exitCode === self::SUCCESS) {
            \$output->writeln('<info>{$pluginId}:doctor complete — no issues found</info>');
        } else {
            \$output->writeln('<error>{$pluginId}:doctor found issues — review output above</error>');
        }

        return \$exitCode;
    }
}
PHP;
    }

    /**
     * Generate entity count check stubs for each owned type/subtype.
     *
     * @param array<array{type: string, subtype: string}> $entities
     */
    private function generateEntityCountChecks(string $pluginId, array $entities): string
    {
        $lines = [];
        foreach ($entities as $entity) {
            $type = $entity['type'];
            $subtype = $entity['subtype'];
            $varName = '$count_' . preg_replace('/[^a-z0-9_]/', '_', $subtype);
            $lines[] = "        // Count {$type}/{$subtype} entities";
            $lines[] = "        {$varName} = (int) elgg_get_entities([";
            $lines[] = "            'type' => '{$type}',";
            $lines[] = "            'subtype' => '{$subtype}',";
            $lines[] = "            'count' => true,";
            $lines[] = "        ]);";
            $lines[] = "        \$output->writeln(\"  {$type}/{$subtype}: {{$varName}} entities\");";
            $lines[] = "";
        }
        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // elgg-plugin.php registration
    // -------------------------------------------------------------------------

    /**
     * Register the DoctorCommand class in elgg-plugin.php under 'cli' => ['commands' => [...]].
     *
     * Uses format-preserving printing where possible; falls back to appended text injection
     * if the AST approach cannot locate the return array.
     *
     * Returns true on success, false if the injection could not be applied.
     */
    private function registerInPluginPhp(string $pluginPath, string $namespace): bool
    {
        $pluginPhpPath = $pluginPath . '/elgg-plugin.php';
        $code = file_get_contents($pluginPhpPath);
        if ($code === false) return false;

        $commandClass = '\\' . $namespace . '\\Cli\\DoctorCommand::class';

        // Skip if already registered
        if (str_contains($code, 'DoctorCommand')) {
            return true;
        }

        // Check whether 'cli' key already exists in the file
        $hasCli = str_contains($code, "'cli'") || str_contains($code, '"cli"');

        if ($hasCli) {
            // Try to inject into an existing 'commands' array under 'cli'
            $injected = $this->injectIntoExistingCliSection($code, $commandClass);
            if ($injected !== null) {
                file_put_contents($pluginPhpPath, $injected);
                return true;
            }
        }

        // Append 'cli' key before the closing ]; of the return array
        $injected = $this->appendCliSection($code, $namespace);
        if ($injected !== null) {
            file_put_contents($pluginPhpPath, $injected);
            return true;
        }

        return false;
    }

    /**
     * Try to inject the DoctorCommand class into an existing 'commands' array under 'cli'.
     */
    private function injectIntoExistingCliSection(string $code, string $commandClass): ?string
    {
        // Match 'commands' => [ ... ] and append before the closing bracket
        $new = preg_replace_callback(
            "/'commands'\s*=>\s*\[([^\]]*)\]/s",
            function (array $m) use ($commandClass): string {
                $inner = rtrim($m[1]);
                $indent = '            ';
                if ($inner === '') {
                    return "'commands' => [\n{$indent}{$commandClass},\n        ]";
                }
                // Already contains entries — append after last entry
                return "'commands' => [{$m[1]}{$indent}{$commandClass},\n        ]";
            },
            $code,
        );

        if ($new === null || $new === $code) {
            return null;
        }

        return $new;
    }

    /**
     * Append a new 'cli' section to the elgg-plugin.php return array.
     * Inserts before the closing ]; of the outermost return array.
     */
    private function appendCliSection(string $code, string $namespace): ?string
    {
        $commandClass = '\\' . $namespace . '\\Cli\\DoctorCommand::class';

        $cliSection = <<<PHP
    'cli' => [
        'commands' => [
            {$commandClass},
        ],
    ],
PHP;

        // Insert before the last ]; in the file (closing of the return array)
        $lastBracket = strrpos($code, '];');
        if ($lastBracket === false) {
            return null;
        }

        return substr($code, 0, $lastBracket) . $cliSection . "\n" . substr($code, $lastBracket);
    }
}
