<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;

final class FieldConfigApi extends AbstractRule
{
    // Maps elgg_get_config() key → [type, subtype] for elgg()->fields->get()
    private const CONFIG_MAP = [
        'pages'          => ['object', 'page'],
        'group'          => ['group', 'group'],
        'profile_fields' => ['user', 'user'],
    ];

    public function getId(): string
    {
        return 'field-config-api-4x';
    }

    public function getDescription(): string
    {
        return 'Replace elgg_get_config() field lookups with elgg()->fields->get()';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];
        $keys = array_keys(self::CONFIG_MAP);

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $calls = $this->findFunctionCalls($ast, ['elgg_get_config']);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $key = $this->getStringArg($call, 0);
                if ($key === null || !in_array($key, $keys, true)) continue;

                [$type, $subtype] = self::CONFIG_MAP[$key];
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "elgg_get_config('{$key}') removed: use elgg()->fields->get('{$type}', '{$subtype}')",
                    code: $printer->prettyPrintExpr($call),
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d elgg_get_config field lookup(s) to replace', count($findings))
                : 'No elgg_get_config field lookups found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $replacements = $this->buildReplacements();

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $original = $code;
            foreach ($replacements as $old => $new) {
                $code = str_replace($old, $new, $code);
            }

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced elgg_get_config() field lookups with elgg()->fields->get()',
                );
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }

    /** @return array<string,string> */
    private function buildReplacements(): array
    {
        $map = [];
        foreach (self::CONFIG_MAP as $key => [$type, $subtype]) {
            $replacement = "elgg()->fields->get('{$type}', '{$subtype}')";
            $map["elgg_get_config('{$key}')"] = $replacement;
            $map["elgg_get_config(\"{$key}\")"] = $replacement;
        }
        return $map;
    }

    private function getStringArg(Node\Expr\FuncCall $call, int $index): ?string
    {
        if (!isset($call->args[$index])) return null;
        $value = $call->args[$index]->value;
        return $value instanceof Node\Scalar\String_ ? $value->value : null;
    }
}
