<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Reads a version manifest and executes migration rules in priority order.
 */
final class RuleRunner
{
    /**
     * Load a manifest.json and return the parsed structure.
     *
     * @return array{from: string, to: string, rules: array<array{id: string, class?: string, automated: bool, priority: int}>}
     */
    public function loadManifest(string $manifestPath): array
    {
        if (!is_file($manifestPath)) {
            throw new \RuntimeException("Cannot read manifest: {$manifestPath}");
        }

        $content = file_get_contents($manifestPath);
        if ($content === false) {
            throw new \RuntimeException("Cannot read manifest: {$manifestPath}");
        }

        $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        // Sort rules by priority
        usort($manifest['rules'], fn(array $a, array $b) => $a['priority'] <=> $b['priority']);

        return $manifest;
    }

    /**
     * Analyze a plugin against all rules in a manifest.
     *
     * @return array<RuleAnalysis>
     */
    public function analyzeAll(string $manifestPath, string $pluginPath): array
    {
        $manifest = $this->loadManifest($manifestPath);
        $results = [];

        foreach ($manifest['rules'] as $ruleConfig) {
            if (!($ruleConfig['automated'] ?? false)) {
                continue;
            }

            $rule = $this->instantiateRule($ruleConfig);
            $results[] = $rule->analyze($pluginPath);
        }

        return $results;
    }

    /**
     * Apply all automated rules from a manifest to a plugin.
     *
     * @return array<RuleResult>
     */
    public function applyAll(string $manifestPath, string $pluginPath): array
    {
        $manifest = $this->loadManifest($manifestPath);
        $results = [];

        foreach ($manifest['rules'] as $ruleConfig) {
            if (!($ruleConfig['automated'] ?? false)) {
                continue;
            }

            $rule = $this->instantiateRule($ruleConfig);

            $analysis = $rule->analyze($pluginPath);
            if (!$analysis->applicable) {
                continue;
            }

            $results[] = $rule->apply($pluginPath);
        }

        return $results;
    }

    /**
     * Get LLM instructions for all non-automated rules.
     *
     * @return array<array{id: string, name: string, instructions: string}>
     */
    public function getLlmInstructions(string $manifestPath): array
    {
        $manifest = $this->loadManifest($manifestPath);
        $instructions = [];

        foreach ($manifest['rules'] as $ruleConfig) {
            if ($ruleConfig['automated'] ?? false) {
                continue;
            }

            $instructions[] = [
                'id' => $ruleConfig['id'],
                'name' => $ruleConfig['name'],
                'instructions' => $ruleConfig['llm_instructions'] ?? '',
            ];
        }

        return $instructions;
    }

    private function instantiateRule(array $ruleConfig): MigrationRule
    {
        $class = $ruleConfig['class'] ?? null;
        if ($class === null) {
            throw new \RuntimeException("Rule {$ruleConfig['id']} has no class defined");
        }

        if (!class_exists($class)) {
            throw new \RuntimeException("Rule class not found: {$class}");
        }

        $rule = new $class();
        if (!$rule instanceof MigrationRule) {
            throw new \RuntimeException("Class {$class} does not implement MigrationRule");
        }

        return $rule;
    }
}
