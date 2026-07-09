<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V4ToV5;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;

/**
 * Scaffolds an \Elgg\Database\Seeds\Seed subclass for plugins that own entity types/subtypes.
 *
 * Detection sources:
 * 1. `elgg-plugin.php` 'entities' array — each entry with 'type' and 'subtype' keys.
 * 2. Legacy add_subtype() / update_subtype() function calls in any PHP file.
 *
 * When entity ownership is detected and no Seeder already exists, the rule:
 * - Generates `classes/<Namespace>/Database/Seeds/Seeder.php` extending \Elgg\Database\Seeds\Seed
 * - Inserts an elgg_register_event_handler() call into Bootstrap.php (or notes it in a warning)
 * - Appends a "Seeding" section to ARCHITECTURE.md if that file exists
 *
 * The Seed subclass implements: getType(), getCountOptions(), seed(), unseed(), addSeed().
 *
 * Verification: skipped unless an `elgg-cli` binary is on PATH within the plugin directory.
 */
final class ScaffoldSeeder extends AbstractRule
{
    public function getId(): string
    {
        return 'scaffold-seeder';
    }

    public function getDescription(): string
    {
        return 'Scaffold a \Elgg\Database\Seeds\Seed subclass for plugins owning entity types/subtypes';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $entities = $this->detectOwnedEntities($pluginPath);

        if (empty($entities)) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No owned entity types/subtypes detected — Seeder not required',
            );
        }

        $seederExists = $this->seederAlreadyExists($pluginPath);

        if ($seederExists) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: sprintf(
                    'Found %d owned entity type(s) but Seeder already exists — skipping',
                    count($entities),
                ),
            );
        }

        $findings = [];
        foreach ($entities as $entity) {
            $findings[] = new Finding(
                file: $entity['source'],
                line: $entity['line'] ?? 0,
                description: sprintf(
                    "Plugin owns %s/%s but has no Seeder subclass — scaffold required",
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
                'Plugin owns %d entity type(s) with no Seeder — will scaffold Seeder subclass',
                count($entities),
            ),
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $entities = $this->detectOwnedEntities($pluginPath);

        if (empty($entities)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No owned entity types detected — Seeder not scaffolded'],
            );
        }

        if ($this->seederAlreadyExists($pluginPath)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['Seeder already exists — skipping scaffold'],
            );
        }

        $changes = [];
        $warnings = [];

        // Resolve namespace and plugin id from composer.json / elgg-plugin.php
        $namespace = $this->resolveRootNamespace($pluginPath);
        $pluginId = $this->resolvePluginId($pluginPath);

        if ($namespace === null) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: false,
                changes: [],
                errors: ['Cannot resolve PSR-4 root namespace from composer.json — cannot scaffold Seeder'],
            );
        }

        // --- 1. Generate Seeder class ---
        $seederRelPath = $this->seederRelativePath($namespace);
        $seederAbsPath = $pluginPath . '/' . $seederRelPath;
        $seederDir = dirname($seederAbsPath);

        if (!is_dir($seederDir)) {
            mkdir($seederDir, 0755, true);
        }

        $seederContent = $this->generateSeederClass($namespace, $pluginId, $entities);
        file_put_contents($seederAbsPath, $seederContent);

        $changes[] = new FileChange(
            file: $seederRelPath,
            type: 'created',
            description: sprintf(
                'Scaffolded Seeder subclass for %d owned entity type(s)',
                count($entities),
            ),
        );

        // --- 2. Register in Bootstrap.php ---
        $bootstrapPath = $pluginPath . '/Bootstrap.php';
        if (file_exists($bootstrapPath)) {
            $bootstrapResult = $this->injectBootstrapRegistration($bootstrapPath, $namespace);
            if ($bootstrapResult) {
                $changes[] = new FileChange(
                    file: 'Bootstrap.php',
                    type: 'modified',
                    description: 'Registered Seeder via elgg_register_event_handler in init()',
                );
            } else {
                $warnings[] = 'Could not auto-inject Seeder registration into Bootstrap.php — add manually: elgg_register_event_handler(\'seeds\', \'database\', [\\' . $namespace . '\\Database\\Seeds\\Seeder::class, \'addSeed\'])';
            }
        } else {
            $warnings[] = 'Bootstrap.php not found — add Seeder registration manually: elgg_register_event_handler(\'seeds\', \'database\', [\\' . $namespace . '\\Database\\Seeds\\Seeder::class, \'addSeed\'])';
        }

        // --- 3. Append to ARCHITECTURE.md if it exists ---
        $archPath = $pluginPath . '/ARCHITECTURE.md';
        if (file_exists($archPath)) {
            $seeding = $this->generateArchitectureSection($pluginId, $entities);
            file_put_contents($archPath, "\n" . $seeding, FILE_APPEND);
            $changes[] = new FileChange(
                file: 'ARCHITECTURE.md',
                type: 'modified',
                description: 'Appended Seeding section',
            );
        }

        // --- 4. Optional CLI verification ---
        $elggCli = $pluginPath . '/bin/elgg-cli';
        if (is_file($elggCli) && is_executable($elggCli)) {
            $cmd = sprintf('%s database:seed --type=%s 2>&1', escapeshellarg($elggCli), escapeshellarg($pluginId));
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                $warnings[] = sprintf(
                    'elgg-cli database:seed --type=%s exited %d: %s',
                    $pluginId,
                    $exitCode,
                    implode(' ', $output),
                );
            }
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
     * Returns an array of owned entity descriptors.
     *
     * Each entry has keys: type, subtype, source, line (optional).
     *
     * @return array<array{type: string, subtype: string, source: string, line?: int}>
     */
    private function detectOwnedEntities(string $pluginPath): array
    {
        $entities = [];

        // 1. elgg-plugin.php 'entities' array
        $pluginPhpPath = $pluginPath . '/elgg-plugin.php';
        if (file_exists($pluginPhpPath)) {
            $fromManifest = $this->parsePluginPhpEntities($pluginPhpPath);
            foreach ($fromManifest as $e) {
                $entities[] = $e;
            }
        }

        // 2. add_subtype() / update_subtype() calls in all PHP files
        $seenKeys = array_map(fn($e) => $e['type'] . '/' . $e['subtype'], $entities);

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relative = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $calls = $this->findFunctionCalls($ast, ['add_subtype', 'update_subtype']);
            foreach ($calls as $call) {
                $typeArg = $call->args[0] ?? null;
                $subtypeArg = $call->args[1] ?? null;

                if ($typeArg === null || $subtypeArg === null) continue;
                if (!$typeArg instanceof Node\Arg || !$subtypeArg instanceof Node\Arg) continue;
                if (!$typeArg->value instanceof Node\Scalar\String_) continue;
                if (!$subtypeArg->value instanceof Node\Scalar\String_) continue;

                $type = $typeArg->value->value;
                $subtype = $subtypeArg->value->value;
                $key = $type . '/' . $subtype;

                if (in_array($key, $seenKeys, true)) continue;

                $seenKeys[] = $key;
                $entities[] = [
                    'type' => $type,
                    'subtype' => $subtype,
                    'source' => $relative,
                    'line' => $call->getLine(),
                ];
            }
        }

        return $entities;
    }

    /**
     * Parse the 'entities' section of elgg-plugin.php by inspecting the returned array literal.
     *
     * @return array<array{type: string, subtype: string, source: string}>
     */
    private function parsePluginPhpEntities(string $pluginPhpPath): array
    {
        $code = file_get_contents($pluginPhpPath);
        if ($code === false) return [];

        $ast = $this->parse($code);
        if ($ast === null) return [];

        $results = [];

        // Walk the AST looking for the top-level return statement's array
        $topReturn = null;
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node\Expr\Array_) {
                $topReturn = $stmt->expr;
                break;
            }
        }

        if ($topReturn === null) return [];

        // Find the 'entities' key in the top-level array
        $entitiesArray = $this->findArrayKey($topReturn, 'entities');
        if ($entitiesArray === null || !$entitiesArray instanceof Node\Expr\Array_) {
            return [];
        }

        $relative = basename($pluginPhpPath);

        foreach ($entitiesArray->items as $item) {
            if (!$item instanceof Node\Expr\ArrayItem) continue;
            if (!$item->value instanceof Node\Expr\Array_) continue;

            $type = $this->extractStringKey($item->value, 'type');
            $subtype = $this->extractStringKey($item->value, 'subtype');

            if ($type === null || $subtype === null) continue;

            $results[] = [
                'type' => $type,
                'subtype' => $subtype,
                'source' => $relative,
            ];
        }

        return $results;
    }

    /**
     * Find a specific string-keyed entry in an Array_ node and return its value.
     */
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

    /**
     * Extract a string value from a nested array by key.
     */
    private function extractStringKey(Node\Expr\Array_ $array, string $key): ?string
    {
        $value = $this->findArrayKey($array, $key);
        if ($value instanceof Node\Scalar\String_) {
            return $value->value;
        }
        return null;
    }

    /**
     * Check whether a Seeder class file already exists anywhere under classes/.
     */
    private function seederAlreadyExists(string $pluginPath): bool
    {
        $classesDir = $pluginPath . '/classes';
        if (!is_dir($classesDir)) return false;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($classesDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getBasename() === 'Seeder.php') {
                // Check if it extends Seed
                $contents = file_get_contents($file->getPathname());
                if ($contents !== false && str_contains($contents, 'Seed')) {
                    return true;
                }
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Namespace/plugin-id resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the plugin's PSR-4 root namespace from composer.json.
     * Returns the first autoload.psr-4 namespace that maps to "classes/" or "".
     */
    private function resolveRootNamespace(string $pluginPath): ?string
    {
        $composerPath = $pluginPath . '/composer.json';
        if (!file_exists($composerPath)) return null;

        $data = json_decode(file_get_contents($composerPath), true);
        if (!is_array($data)) return null;

        $psr4 = $data['autoload']['psr-4'] ?? [];
        if (empty($psr4)) return null;

        // Prefer the namespace mapping to classes/ or ""
        foreach ($psr4 as $ns => $path) {
            $normalizedPath = rtrim((string) $path, '/');
            if ($normalizedPath === 'classes' || $normalizedPath === '' || str_starts_with($normalizedPath, 'classes/')) {
                return rtrim($ns, '\\');
            }
        }

        // Fall back to the first defined namespace
        $ns = array_key_first($psr4);
        return rtrim((string) $ns, '\\');
    }

    /**
     * Resolve the plugin's ID from elgg-plugin.php or composer.json extra.installer-name.
     */
    private function resolvePluginId(string $pluginPath): string
    {
        // Try elgg-plugin.php
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
                // Derive from package name: vendor/name → name
                $packageName = $data['name'] ?? '';
                if (str_contains($packageName, '/')) {
                    return explode('/', $packageName)[1];
                }
            }
        }

        // Last resort: basename of plugin directory
        return strtolower(basename($pluginPath));
    }

    // -------------------------------------------------------------------------
    // Code generation
    // -------------------------------------------------------------------------

    /**
     * Return the relative path for the generated Seeder class.
     * e.g. "Tracker\\" → "classes/Tracker/Database/Seeds/Seeder.php"
     */
    private function seederRelativePath(string $namespace): string
    {
        $parts = explode('\\', $namespace);
        $classPath = implode('/', $parts);
        return "classes/{$classPath}/Database/Seeds/Seeder.php";
    }

    /**
     * Generate the full content of the Seeder class.
     *
     * @param array<array{type: string, subtype: string, source: string, line?: int}> $entities
     */
    private function generateSeederClass(string $namespace, string $pluginId, array $entities): string
    {
        $seederNs = $namespace . '\\Database\\Seeds';

        // Build seed() body — create one entity per owned type
        $seedLines = [];
        $unseedLines = [];

        foreach ($entities as $entity) {
            $type = $entity['type'];
            $subtype = $entity['subtype'];

            if ($type === 'user') {
                $seedLines[] = $this->indent(2, "// {$type}/{$subtype}");
                $seedLines[] = $this->indent(2, "\$user = \$this->createUser();");
                $seedLines[] = $this->indent(2, "\$user->subtype = '{$subtype}';");
                $seedLines[] = $this->indent(2, "\$user->__faker = true;");
                $seedLines[] = $this->indent(2, "\$user->save();");
                $seedLines[] = '';
            } elseif ($type === 'group') {
                $seedLines[] = $this->indent(2, "// {$type}/{$subtype}");
                $seedLines[] = $this->indent(2, "\$group = \$this->createGroup();");
                $seedLines[] = $this->indent(2, "\$group->subtype = '{$subtype}';");
                $seedLines[] = $this->indent(2, "\$group->__faker = true;");
                $seedLines[] = $this->indent(2, "\$group->save();");
                $seedLines[] = '';
            } else {
                // object or site
                $seedLines[] = $this->indent(2, "// {$type}/{$subtype}");
                $seedLines[] = $this->indent(2, "\$entity = \$this->createObject([");
                $seedLines[] = $this->indent(3, "'subtype' => '{$subtype}',");
                $seedLines[] = $this->indent(2, "]);");
                $seedLines[] = $this->indent(2, "\$entity->title = \$this->faker->sentence(3);");
                $seedLines[] = $this->indent(2, "\$entity->description = \$this->faker->paragraph();");
                $seedLines[] = $this->indent(2, "\$entity->__faker = true;");
                $seedLines[] = $this->indent(2, "\$entity->save();");
                $seedLines[] = '';
            }

            $unseedLines[] = $this->indent(2, "// Unseed {$type}/{$subtype}");
            $unseedLines[] = $this->indent(2, "\$entities = elgg_get_entities([");
            $unseedLines[] = $this->indent(3, "'type' => '{$type}',");
            $unseedLines[] = $this->indent(3, "'subtype' => '{$subtype}',");
            $unseedLines[] = $this->indent(3, "'metadata_name_value_pairs' => [['name' => '__faker', 'value' => true]],");
            $unseedLines[] = $this->indent(3, "'limit' => false,");
            $unseedLines[] = $this->indent(2, "]);");
            $unseedLines[] = $this->indent(2, "foreach (\$entities as \$e) {");
            $unseedLines[] = $this->indent(3, "\$e->delete();");
            $unseedLines[] = $this->indent(2, "}");
            $unseedLines[] = '';
        }

        $seedBody = implode("\n", $seedLines);
        $unseedBody = implode("\n", $unseedLines);

        $subtypesList = implode(', ', array_map(fn($e) => "'{$e['subtype']}'", $entities));

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$seederNs};

use Elgg\Database\Seeds\Seed;
use Elgg\Event;

/**
 * Seed / unseed {$pluginId} plugin entities.
 *
 * Owned entity types: {$subtypesList}
 *
 * Register via:
 *   elgg_register_event_handler('seeds', 'database', [Seeder::class, 'addSeed']);
 *
 * Run with:
 *   php elgg-cli database:seed --type={$pluginId}
 *   php elgg-cli database:unseed --type={$pluginId}
 */
final class Seeder extends Seed
{
    /**
     * Identifies this seeder to the CLI: --type={$pluginId}
     */
    public static function getType(): string
    {
        return '{$pluginId}';
    }

    /**
     * Options passed to elgg_get_entities() when counting existing seeds.
     *
     * @return array<string, mixed>
     */
    protected function getCountOptions(): array
    {
        return [
            'type' => 'object',
            'metadata_name_value_pairs' => [
                ['name' => '__faker', 'value' => true],
            ],
        ];
    }

    /**
     * Create one seeded entity of each owned type.
     */
    public function seed(): void
    {
{$seedBody}    }

    /**
     * Delete all entities previously created by this seeder (tagged __faker=true).
     */
    public function unseed(): void
    {
{$unseedBody}    }

    /**
     * Event handler for 'seeds', 'database' — appends this class to the seeds list.
     */
    public static function addSeed(Event \$event): array
    {
        \$value = \$event->getValue() ?? [];
        \$value[] = __CLASS__;
        return \$value;
    }
}
PHP;
    }

    /**
     * Inject the Seeder event handler registration into Bootstrap::init().
     * Returns true on success, false if the injection could not be applied.
     */
    private function injectBootstrapRegistration(string $bootstrapPath, string $namespace): bool
    {
        $code = file_get_contents($bootstrapPath);
        if ($code === false) return false;

        $seederClass = $namespace . '\\Database\\Seeds\\Seeder';
        $registration = "elgg_register_event_handler('seeds', 'database', [\\{$seederClass}::class, 'addSeed']);";

        // Skip if already registered
        if (str_contains($code, 'seeds') && str_contains($code, 'database') && str_contains($code, 'Seeder')) {
            return true; // already present
        }

        // Try to inject into init() method body — look for `public function init(): void`
        // or `public function init()` and inject before the closing brace
        if (!preg_match('/\bpublic\s+function\s+init\s*\(/', $code)) {
            return false;
        }

        // Find the init() method and insert registration at the end of its body
        $newCode = preg_replace_callback(
            '/(\bpublic\s+function\s+init\s*\(\)[^{]*\{)(.*?)(\n    \})/s',
            function (array $m) use ($registration): string {
                $body = rtrim($m[2]);
                $indent = '        '; // 8 spaces — inside class method
                if ($body === '') {
                    return $m[1] . "\n{$indent}{$registration}" . $m[3];
                }
                return $m[1] . $m[2] . "\n{$indent}{$registration}\n" . '    }';
            },
            $code,
        );

        if ($newCode === null || $newCode === $code) {
            return false;
        }

        file_put_contents($bootstrapPath, $newCode);
        return true;
    }

    /**
     * Generate the ARCHITECTURE.md "Seeding" section.
     *
     * @param array<array{type: string, subtype: string, source: string, line?: int}> $entities
     */
    private function generateArchitectureSection(string $pluginId, array $entities): string
    {
        $lines = ["## Seeding\n"];
        $lines[] = "This plugin owns the following entity types and ships a `Seeder` subclass:\n";

        foreach ($entities as $entity) {
            $lines[] = "- `{$entity['type']}/{$entity['subtype']}`";
        }

        $lines[] = '';
        $lines[] = "**Seed dev/QA data:**";
        $lines[] = "```bash";
        $lines[] = "php elgg-cli database:seed --type={$pluginId} --limit=10";
        $lines[] = "php elgg-cli database:unseed --type={$pluginId}";
        $lines[] = "```\n";

        return implode("\n", $lines);
    }

    /**
     * Produce a string of N*4-space indentation.
     */
    private function indent(int $levels, string $line): string
    {
        return str_repeat('    ', $levels) . $line;
    }
}
