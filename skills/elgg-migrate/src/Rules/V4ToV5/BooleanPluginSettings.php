<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V4ToV5;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Detects yes/no string-valued plugin settings and scaffolds a SystemUpgrade.
 *
 * In Elgg 5.x the canonical pattern for boolean plugin settings is PHP bool,
 * not the legacy 'yes'/'no' strings that shipped with every Elgg 2/3/4 plugin.
 *
 * Detection targets (per-plugin):
 *   - elgg_get_plugin_setting() or $plugin->getSetting() compared to 'yes'/'no'
 *   - elgg_set_plugin_setting() or $plugin->setSetting() called with 'yes'/'no'
 *   - 'settings' entries in elgg-plugin.php whose default value is 'yes'/'no'
 *
 * When applicable, apply():
 *   1. Rewrites comparison expressions (=== 'yes', == 'no', …) to bool checks.
 *   2. Rewrites write callsites (set to 'yes'/'no') to true/false.
 *   3. Updates default values in elgg-plugin.php settings array.
 *   4. Scaffolds classes/<Namespace>/Upgrades/MigrateSwitchSettings.php extending
 *      \Elgg\Upgrade\SystemUpgrade to convert stored string values to bool.
 *   5. Registers the upgrade class in elgg-plugin.php 'upgrades' array.
 */
final class BooleanPluginSettings extends AbstractRule
{
    /** @var array<string> Functions that READ a plugin setting. */
    private const READ_FUNCS = [
        'elgg_get_plugin_setting',
    ];

    /** @var array<string> Functions that WRITE a plugin setting. */
    private const WRITE_FUNCS = [
        'elgg_set_plugin_setting',
    ];

    /** @var array<string> All target function names. */
    private const ALL_FUNCS = [
        'elgg_get_plugin_setting',
        'elgg_set_plugin_setting',
    ];

    /** @var array<string> The yes/no string literals to detect. */
    private const YES_NO = ['yes', 'no'];

    public function getId(): string
    {
        return 'boolean-plugin-settings';
    }

    public function getDescription(): string
    {
        return 'Detect yes/no string plugin settings and scaffold a SystemUpgrade to migrate stored values to bool';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        // Scan PHP source files for yes/no reads/writes.
        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $ast = $this->parse($code);
            if ($ast === null) {
                continue;
            }

            foreach ($this->findYesNoFindings($ast, $relativePath) as $finding) {
                $findings[] = $finding;
            }
        }

        // Scan elgg-plugin.php for settings array defaults.
        $pluginPhpFindings = $this->findElggPluginPhpDefaults($pluginPath);
        foreach ($pluginPhpFindings as $finding) {
            $findings[] = $finding;
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d yes/no plugin setting usage(s) requiring bool migration', count($findings))
                : 'No yes/no string plugin settings found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        $settingNames = $this->collectSettingNames($pluginPath);

        // 1. Rewrite PHP source files (reads + writes).
        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $parsed = $this->parsePreserving($code);
            if ($parsed === null) {
                continue;
            }

            $transformed = $this->transformYesNoCalls($parsed, $relativePath);
            if ($transformed !== null) {
                file_put_contents($file, $transformed);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Rewrote yes/no plugin setting reads/writes to boolean',
                );
            }
        }

        // 2. Update elgg-plugin.php settings defaults + add upgrade registration.
        $elggPluginFile = $pluginPath . '/elgg-plugin.php';
        if (is_file($elggPluginFile)) {
            $upgradeClass = $this->resolveUpgradeClassName($pluginPath);
            $pluginPhpChanged = $this->updateElggPluginPhp($elggPluginFile, $upgradeClass);
            if ($pluginPhpChanged) {
                $changes[] = new FileChange(
                    file: 'elgg-plugin.php',
                    type: 'modified',
                    description: 'Updated yes/no defaults to bool; registered MigrateSwitchSettings upgrade',
                );
            }
        }

        // 3. Scaffold the SystemUpgrade class.
        if (!empty($settingNames)) {
            $scaffoldResult = $this->scaffoldUpgradeClass($pluginPath, $settingNames);
            if ($scaffoldResult !== null) {
                $changes[] = $scaffoldResult;
            }
        } else {
            $warnings[] = 'No setting names could be statically detected — scaffold MigrateSwitchSettings manually';
        }

        if (empty($changes)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No yes/no plugin settings found — nothing to migrate'],
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
    // Analysis helpers
    // -------------------------------------------------------------------------

    /**
     * Yield Finding objects for yes/no function calls in an AST.
     *
     * @param array<Node\Stmt> $ast
     * @return array<Finding>
     */
    private function findYesNoFindings(array $ast, string $relativePath): array
    {
        $findings = [];
        $printer = $this->printer();
        $calls = $this->findFunctionCalls($ast, self::ALL_FUNCS);

        foreach ($calls as $call) {
            $funcName = $call->name->toString();

            if (in_array($funcName, self::READ_FUNCS, true)) {
                // elgg_get_plugin_setting() — the call itself is not a yes/no literal,
                // but look for comparisons of the result (parent expression context).
                // We flag the call as a candidate when its value appears in a comparison
                // with 'yes' or 'no'. Because we only have the call node here (not the
                // parent), we flag any elgg_get_plugin_setting() call that is wrapped in
                // a BinaryOp comparison with a 'yes'/'no' literal in the same AST.
                // A simpler and more reliable approach: scan all binary ops in the file.
                continue; // handled below via comparison scan
            }

            if (in_array($funcName, self::WRITE_FUNCS, true)) {
                // elgg_set_plugin_setting($name, $value, $pluginId) — value is arg 1.
                $valueArg = $call->args[1] ?? null;
                if ($valueArg instanceof Node\Arg
                    && $valueArg->value instanceof Node\Scalar\String_
                    && in_array($valueArg->value->value, self::YES_NO, true)
                ) {
                    $findings[] = new Finding(
                        file: $relativePath,
                        line: $call->getLine(),
                        description: sprintf(
                            "elgg_set_plugin_setting() writes '%s' — should write bool %s",
                            $valueArg->value->value,
                            $valueArg->value->value === 'yes' ? 'true' : 'false',
                        ),
                        code: $printer->prettyPrintExpr($call),
                    );
                }
            }
        }

        // Scan comparison expressions: $x === 'yes', $x == 'no', 'yes' === $x, etc.
        $comparisons = $this->finder()->find($ast, fn(Node $n) =>
            ($n instanceof Node\Expr\BinaryOp\Identical
                || $n instanceof Node\Expr\BinaryOp\Equal
                || $n instanceof Node\Expr\BinaryOp\NotIdentical
                || $n instanceof Node\Expr\BinaryOp\NotEqual)
            && $this->hasYesNoLiteral($n)
            && $this->involvesSettingRead($n)
        );

        foreach ($comparisons as $cmp) {
            /** @var Node\Expr\BinaryOp $cmp */
            $literal = $this->extractYesNoLiteral($cmp);
            $findings[] = new Finding(
                file: $relativePath,
                line: $cmp->getLine(),
                description: sprintf(
                    "Comparison with '%s' string — should compare with boolean after migration",
                    $literal,
                ),
                code: $printer->prettyPrintExpr($cmp),
            );
        }

        return $findings;
    }

    /**
     * Return true if the binary op node has a 'yes' or 'no' string literal on either side.
     */
    private function hasYesNoLiteral(Node\Expr\BinaryOp $node): bool
    {
        return $this->isYesNoString($node->left) || $this->isYesNoString($node->right);
    }

    private function isYesNoString(Node $node): bool
    {
        return $node instanceof Node\Scalar\String_
            && in_array($node->value, self::YES_NO, true);
    }

    /**
     * Return true if either side of a comparison involves a plugin setting read.
     */
    private function involvesSettingRead(Node\Expr\BinaryOp $node): bool
    {
        return $this->containsSettingRead($node->left)
            || $this->containsSettingRead($node->right);
    }

    private function containsSettingRead(Node $node): bool
    {
        if ($node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && in_array($node->name->toString(), self::READ_FUNCS, true)
        ) {
            return true;
        }

        // $plugin->getSetting('key') or $plugin->{'key'}
        if ($node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->name === 'getSetting'
        ) {
            return true;
        }

        // Variable that was assigned from a setting read — we can't trace this statically,
        // so we only flag direct call comparisons.
        return false;
    }

    private function extractYesNoLiteral(Node\Expr\BinaryOp $node): string
    {
        if ($this->isYesNoString($node->left)) {
            return ($node->left instanceof Node\Scalar\String_) ? $node->left->value : '';
        }
        return ($node->right instanceof Node\Scalar\String_) ? $node->right->value : '';
    }

    /**
     * Scan elgg-plugin.php for 'settings' keys whose default is 'yes' or 'no'.
     *
     * @return array<Finding>
     */
    private function findElggPluginPhpDefaults(string $pluginPath): array
    {
        $file = $pluginPath . '/elgg-plugin.php';
        if (!is_file($file)) {
            return [];
        }

        $code = file_get_contents($file);
        $ast = $this->parse($code);
        if ($ast === null) {
            return [];
        }

        $findings = [];
        $printer = $this->printer();
        $settingsArrays = $this->findSettingsArrayInPluginPhp($ast);

        foreach ($settingsArrays as [$keyNode, $defaultNode]) {
            if ($defaultNode instanceof Node\Scalar\String_
                && in_array($defaultNode->value, self::YES_NO, true)
            ) {
                $findings[] = new Finding(
                    file: 'elgg-plugin.php',
                    line: $defaultNode->getLine(),
                    description: sprintf(
                        "Setting '%s' has default '%s' — should be %s",
                        $keyNode instanceof Node\Scalar\String_ ? $keyNode->value : '?',
                        $defaultNode->value,
                        $defaultNode->value === 'yes' ? 'true' : 'false',
                    ),
                    code: $printer->prettyPrintExpr($defaultNode),
                );
            }
        }

        return $findings;
    }

    /**
     * Find all [setting_name => ..., default => 'yes'/'no'] entries in the
     * 'settings' key of elgg-plugin.php.
     *
     * Returns pairs of [name_node|null, default_value_node].
     *
     * @param array<Node\Stmt> $ast
     * @return array<array{Node\Expr|null, Node\Expr}>
     */
    private function findSettingsArrayInPluginPhp(array $ast): array
    {
        $results = [];

        // Find all ArrayItem nodes whose key is the string 'settings'.
        $settingsItems = $this->finder()->find($ast, fn(Node $n) =>
            $n instanceof Node\Expr\ArrayItem
            && $n->key instanceof Node\Scalar\String_
            && $n->key->value === 'settings'
            && $n->value instanceof Node\Expr\Array_
        );

        foreach ($settingsItems as $settingsItem) {
            /** @var Node\Expr\ArrayItem $settingsItem */
            $settingsArray = $settingsItem->value;
            if (!$settingsArray instanceof Node\Expr\Array_) {
                continue;
            }

            // Each item is: 'setting_name' => ['value' => 'yes', ...]
            foreach ($settingsArray->items as $item) {
                if (!$item instanceof Node\Expr\ArrayItem) {
                    continue;
                }
                $nameNode = $item->key;
                if (!$item->value instanceof Node\Expr\Array_) {
                    continue;
                }

                // Look for 'value' or 'default' key inside the setting config array.
                foreach ($item->value->items as $configItem) {
                    if (!$configItem instanceof Node\Expr\ArrayItem) {
                        continue;
                    }
                    if (!$configItem->key instanceof Node\Scalar\String_) {
                        continue;
                    }
                    if (in_array($configItem->key->value, ['value', 'default'], true)) {
                        $results[] = [$nameNode, $configItem->value];
                    }
                }
            }
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Apply helpers
    // -------------------------------------------------------------------------

    /**
     * Collect setting names used with 'yes'/'no' values in elgg_set_plugin_setting()
     * and elgg-plugin.php settings defaults.
     *
     * @return array<string>
     */
    private function collectSettingNames(string $pluginPath): array
    {
        $names = [];

        // From PHP source: elgg_set_plugin_setting($name, 'yes'/'no', ...)
        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }
            $ast = $this->parse($code);
            if ($ast === null) {
                continue;
            }

            $calls = $this->findFunctionCalls($ast, self::WRITE_FUNCS);
            foreach ($calls as $call) {
                $nameArg = $call->args[0] ?? null;
                $valueArg = $call->args[1] ?? null;
                if ($nameArg instanceof Node\Arg
                    && $nameArg->value instanceof Node\Scalar\String_
                    && $valueArg instanceof Node\Arg
                    && $valueArg->value instanceof Node\Scalar\String_
                    && in_array($valueArg->value->value, self::YES_NO, true)
                ) {
                    $names[] = $nameArg->value->value;
                }
            }
        }

        // From elgg-plugin.php settings defaults.
        $elggPluginFile = $pluginPath . '/elgg-plugin.php';
        if (is_file($elggPluginFile)) {
            $code = file_get_contents($elggPluginFile);
            $ast = $this->parse($code);
            if ($ast !== null) {
                foreach ($this->findSettingsArrayInPluginPhp($ast) as [$nameNode, $defaultNode]) {
                    if ($nameNode instanceof Node\Scalar\String_
                        && $defaultNode instanceof Node\Scalar\String_
                        && in_array($defaultNode->value, self::YES_NO, true)
                    ) {
                        $names[] = $nameNode->value;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Transform yes/no string literals in function call arguments to bool literals.
     *
     * Returns the transformed source code string, or null if no change was made.
     */
    private function transformYesNoCalls(array $parsed, string $relativePath): ?string
    {
        $traverser = new NodeTraverser();
        $visitor = new class extends NodeVisitorAbstract {
            public bool $changed = false;

            /** @var array<string> */
            private array $writeFuncs = [
                'elgg_set_plugin_setting',
            ];

            public function leaveNode(Node $node): ?Node
            {
                // Rewrite write calls: elgg_set_plugin_setting($n, 'yes'/'no', $id)
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && in_array($node->name->toString(), $this->writeFuncs, true)
                ) {
                    $valueArg = $node->args[1] ?? null;
                    if ($valueArg instanceof Node\Arg
                        && $valueArg->value instanceof Node\Scalar\String_
                        && in_array($valueArg->value->value, ['yes', 'no'], true)
                    ) {
                        $boolValue = $valueArg->value->value === 'yes';
                        $valueArg->value = new Node\Expr\ConstFetch(
                            new Node\Name($boolValue ? 'true' : 'false')
                        );
                        $this->changed = true;
                        return $node;
                    }
                }

                // Rewrite comparisons: $x === 'yes', $x == 'no', etc.
                if ($node instanceof Node\Expr\BinaryOp\Identical
                    || $node instanceof Node\Expr\BinaryOp\Equal
                    || $node instanceof Node\Expr\BinaryOp\NotIdentical
                    || $node instanceof Node\Expr\BinaryOp\NotEqual
                ) {
                    /** @var Node\Expr\BinaryOp $node */
                    $rewritten = $this->tryRewriteComparison($node);
                    if ($rewritten !== null) {
                        $this->changed = true;
                        return $rewritten;
                    }
                }

                return null;
            }

            /**
             * Rewrite `$expr === 'yes'` → `(bool)$expr` (or the bool literal form).
             * Rewrite `$expr === 'no'`  → `!(bool)$expr`.
             * Rewrite `$expr == 'yes'`  → `(bool)$expr`.
             * etc.
             *
             * Only rewrites when one side involves a setting read.
             */
            private function tryRewriteComparison(Node\Expr\BinaryOp $node): ?Node\Expr
            {
                $leftIsYesNo = $node->left instanceof Node\Scalar\String_
                    && in_array($node->left->value, ['yes', 'no'], true);
                $rightIsYesNo = $node->right instanceof Node\Scalar\String_
                    && in_array($node->right->value, ['yes', 'no'], true);

                if (!$leftIsYesNo && !$rightIsYesNo) {
                    return null;
                }

                $literal = $leftIsYesNo
                    ? ($node->left instanceof Node\Scalar\String_ ? $node->left->value : null)
                    : ($node->right instanceof Node\Scalar\String_ ? $node->right->value : null);
                $otherExpr = $leftIsYesNo ? $node->right : $node->left;

                if ($literal === null) {
                    return null;
                }

                // Only rewrite if the other side is a setting read or method call.
                if (!$this->isSettingRead($otherExpr)) {
                    return null;
                }

                // === 'yes' / == 'yes'  → (bool) $otherExpr
                // === 'no'  / == 'no'   → !(bool) $otherExpr
                $isNegated = ($node instanceof Node\Expr\BinaryOp\NotIdentical
                    || $node instanceof Node\Expr\BinaryOp\NotEqual)
                    ? true : false;

                $isYes = ($literal === 'yes');
                $shouldNegate = $isYes ? $isNegated : !$isNegated;

                $castExpr = new Node\Expr\Cast\Bool_($otherExpr);

                if ($shouldNegate) {
                    return new Node\Expr\BooleanNot($castExpr);
                }

                return $castExpr;
            }

            private function isSettingRead(Node $node): bool
            {
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && in_array($node->name->toString(), ['elgg_get_plugin_setting'], true)
                ) {
                    return true;
                }

                if ($node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->name === 'getSetting'
                ) {
                    return true;
                }

                return false;
            }
        };

        $traverser->addVisitor($visitor);
        $parsed['new'] = $traverser->traverse($parsed['new']);

        if (!$visitor->changed) {
            return null;
        }

        return $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']);
    }

    /**
     * Update elgg-plugin.php: rewrite 'yes'/'no' defaults to true/false,
     * and append the upgrade class to the 'upgrades' array.
     */
    private function updateElggPluginPhp(string $file, string $upgradeClass): bool
    {
        $code = file_get_contents($file);
        $parsed = $this->parsePreserving($code);
        if ($parsed === null) {
            return false;
        }

        $changed = false;

        $traverser = new NodeTraverser();
        $visitor = new class($upgradeClass, $changed) extends NodeVisitorAbstract {
            public bool $settingsChanged = false;
            public bool $upgradesChanged = false;
            public bool $upgradeAlreadyRegistered = false;
            private bool $inSettings = false;

            public function __construct(
                private readonly string $upgradeClass,
                bool &$changed,
            ) {}

            public function enterNode(Node $node): ?Node
            {
                // Track when we're inside the 'settings' value array.
                if ($node instanceof Node\Expr\ArrayItem
                    && $node->key instanceof Node\Scalar\String_
                    && $node->key->value === 'settings'
                    && $node->value instanceof Node\Expr\Array_
                ) {
                    $this->inSettings = true;
                }
                return null;
            }

            public function leaveNode(Node $node): int|Node|null
            {
                if ($this->inSettings && $node instanceof Node\Expr\ArrayItem) {
                    // Look for 'value' or 'default' keys within setting config arrays.
                    if ($node->key instanceof Node\Scalar\String_
                        && in_array($node->key->value, ['value', 'default'], true)
                        && $node->value instanceof Node\Scalar\String_
                        && in_array($node->value->value, ['yes', 'no'], true)
                    ) {
                        $boolValue = $node->value->value === 'yes';
                        $node->value = new Node\Expr\ConstFetch(
                            new Node\Name($boolValue ? 'true' : 'false')
                        );
                        $this->settingsChanged = true;
                        return $node;
                    }
                }

                // Detect/add 'upgrades' array.
                if ($node instanceof Node\Expr\ArrayItem
                    && $node->key instanceof Node\Scalar\String_
                    && $node->key->value === 'upgrades'
                    && $node->value instanceof Node\Expr\Array_
                ) {
                    // Check if already registered.
                    foreach ($node->value->items as $item) {
                        if ($item instanceof Node\Expr\ArrayItem
                            && $item->value instanceof Node\Scalar\String_
                            && $item->value->value === $this->upgradeClass
                        ) {
                            $this->upgradeAlreadyRegistered = true;
                            return null;
                        }
                    }
                    // Append the upgrade class string.
                    $node->value->items[] = new Node\Expr\ArrayItem(
                        new Node\Scalar\String_($this->upgradeClass)
                    );
                    $this->upgradesChanged = true;
                    return $node;
                }

                // If we exit an 'ArrayItem' with key 'settings', clear flag.
                if ($this->inSettings && $node instanceof Node\Expr\ArrayItem
                    && $node->key instanceof Node\Scalar\String_
                    && $node->key->value === 'settings'
                ) {
                    $this->inSettings = false;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $parsed['new'] = $traverser->traverse($parsed['new']);

        $anyAstChange = $visitor->settingsChanged || $visitor->upgradesChanged;

        // Write AST-modified content first (handles settings rewrites).
        if ($anyAstChange) {
            $newCode = $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']);
            file_put_contents($file, $newCode);
        }

        // If the 'upgrades' key wasn't in the AST, inject it via text into whatever
        // is now on disk (either the AST-rewritten content or the original).
        if (!$visitor->upgradesChanged && !$visitor->upgradeAlreadyRegistered) {
            $injected = $this->injectUpgradesKey($file, $upgradeClass);
            if ($injected !== null) {
                file_put_contents($file, $injected);
                return true;
            }
        }

        return $anyAstChange || !$visitor->upgradeAlreadyRegistered;
    }

    /**
     * Inject an 'upgrades' key into the return array of elgg-plugin.php via
     * text-based search (for when no 'upgrades' key exists yet).
     */
    private function injectUpgradesKey(string $file, string $upgradeClass): ?string
    {
        $code = file_get_contents($file);

        // Append before the closing `];` of the returned array.
        if (preg_match('/^(\s*)\];?\s*$/m', $code, $m, PREG_OFFSET_CAPTURE)) {
            $insertAt = $m[0][1];
            $indent = "\t";
            $entry = "{$indent}'upgrades' => [\n{$indent}{$indent}\\{$upgradeClass}::class,\n{$indent}],\n";
            return substr($code, 0, $insertAt) . $entry . substr($code, $insertAt);
        }

        return null;
    }

    /**
     * Resolve the upgrade class FQN from plugin namespace.
     *
     * Looks for a namespace declaration in elgg-plugin.php or the first class file.
     */
    private function resolveUpgradeClassName(string $pluginPath): string
    {
        // Try to detect namespace from composer.json or first PHP class.
        $composerFile = $pluginPath . '/composer.json';
        if (is_file($composerFile)) {
            $data = json_decode(file_get_contents($composerFile), true);
            $autoload = $data['autoload']['psr-4'] ?? [];
            foreach ($autoload as $ns => $src) {
                $ns = rtrim($ns, '\\');
                return $ns . '\\Upgrades\\MigrateSwitchSettings';
            }
        }

        // Fallback: scan first PHP file with a namespace.
        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }
            $ast = $this->parse($code);
            if ($ast === null) {
                continue;
            }
            $ns = $this->finder()->findFirst(
                $ast,
                fn(Node $n) => $n instanceof Node\Stmt\Namespace_ && $n->name !== null,
            );
            if ($ns instanceof Node\Stmt\Namespace_ && $ns->name !== null) {
                $parts = explode('\\', $ns->name->toString());
                $rootNs = $parts[0];
                return $rootNs . '\\Upgrades\\MigrateSwitchSettings';
            }
        }

        return 'Plugin\\Upgrades\\MigrateSwitchSettings';
    }

    /**
     * Scaffold the MigrateSwitchSettings SystemUpgrade class.
     *
     * @param array<string> $settingNames
     */
    private function scaffoldUpgradeClass(string $pluginPath, array $settingNames): ?FileChange
    {
        [$namespace, $classDir] = $this->resolveUpgradeNamespaceAndDir($pluginPath);

        if ($classDir === null) {
            return null;
        }

        $dir = $pluginPath . '/' . ltrim($classDir, '/') . '/Upgrades';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $targetFile = $dir . '/MigrateSwitchSettings.php';
        if (is_file($targetFile)) {
            return null; // Do not overwrite existing class.
        }

        $settingsPhp = '';
        foreach ($settingNames as $name) {
            $escaped = addslashes($name);
            $settingsPhp .= "            '{$escaped}',\n";
        }

        $content = $this->buildUpgradeClassContent($namespace, $settingsPhp);
        file_put_contents($targetFile, $content);

        $relativePath = $this->relativePath($pluginPath, $targetFile);

        return new FileChange(
            file: $relativePath,
            type: 'created',
            description: 'Scaffolded MigrateSwitchSettings SystemUpgrade to migrate yes/no settings to bool',
        );
    }

    /**
     * Resolve the upgrade namespace and source directory from composer.json.
     *
     * @return array{string, string|null}  [namespace, srcDir]
     */
    private function resolveUpgradeNamespaceAndDir(string $pluginPath): array
    {
        $composerFile = $pluginPath . '/composer.json';
        if (is_file($composerFile)) {
            $data = json_decode(file_get_contents($composerFile), true);
            $autoload = $data['autoload']['psr-4'] ?? [];
            foreach ($autoload as $ns => $src) {
                $ns = rtrim($ns, '\\');
                $src = rtrim($src, '/');
                return [$ns, $src];
            }
        }

        // Fallback: classes/ directory.
        return ['Plugin', 'classes'];
    }

    /**
     * Build the PHP source for MigrateSwitchSettings.
     */
    private function buildUpgradeClassContent(string $namespace, string $settingsPhp): string
    {
        $pluginId = $this->pluginIdFromNamespace($namespace);
        $escapedPluginId = addslashes($pluginId);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Upgrades;

use Elgg\\Upgrade\\SystemUpgrade;

/**
 * Migrates yes/no string-valued plugin settings to bool.
 *
 * Generated by elgg-migrate boolean-plugin-settings rule.
 * Review the SETTINGS list and plugin ID before running.
 */
final class MigrateSwitchSettings extends SystemUpgrade
{
    /**
     * Setting names stored as 'yes'/'no' strings that must be migrated to bool.
     *
     * @var array<string>
     */
    private const SETTINGS = [
{$settingsPhp}    ];

    /**
     * The plugin ID whose settings are being migrated.
     */
    private const PLUGIN_ID = '{$escapedPluginId}';

    public function getVersion(): int
    {
        return 2024010101;
    }

    public function needsIncrementOffset(): bool
    {
        return false;
    }

    public function shouldBeSkipped(): bool
    {
        return false;
    }

    public function countItems(): int
    {
        return count(self::SETTINGS);
    }

    public function run(): bool
    {
        \$plugin = elgg_get_plugin_from_id(self::PLUGIN_ID);
        if (!\$plugin) {
            return false;
        }

        foreach (self::SETTINGS as \$name) {
            \$value = \$plugin->getSetting(\$name);
            if (\$value === null) {
                continue;
            }
            \$plugin->setSetting(\$name, \$value === 'yes');
        }

        return true;
    }
}
PHP;
    }

    /**
     * Derive a lowercase plugin ID from a namespace string.
     *
     * e.g. 'HypeWidget' → 'hypewidget', 'Foo\\Bar' → 'foobar'
     */
    private function pluginIdFromNamespace(string $namespace): string
    {
        $parts = explode('\\', $namespace);
        return strtolower($parts[0]);
    }
}
