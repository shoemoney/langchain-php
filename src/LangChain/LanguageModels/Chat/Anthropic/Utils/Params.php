<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Utils;

/** Model-specific request compatibility and sampling, ported from utils/params.ts. */
final class Params
{
    private const ADAPTIVE_ONLY_PREFIXES = [
        'claude-opus-4-7', 'claude-opus-4-8', 'claude-opus-5', 'claude-sonnet-5',
        'claude-fable-5', 'claude-mythos-5', 'claude-mythos-preview',
    ];

    public static function isOpus47Model(?string $model = null): bool
    {
        return $model !== null && str_starts_with($model, 'claude-opus-4-7');
    }

    public static function isAdaptiveOnlyModel(?string $model = null): bool
    {
        foreach (self::ADAPTIVE_ONLY_PREFIXES as $prefix) {
            if ($model !== null && str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $outputConfig
     * @return list<string>
     */
    public static function getTaskBudgetBetas(?string $model = null, ?array $outputConfig = null): array
    {
        return self::isOpus47Model($model) && isset($outputConfig['task_budget'])
            ? ['task-budgets-2026-03-13'] : [];
    }

    /** @param array<string, mixed> $fields */
    public static function validateInvocationParamCompatibility(array $fields): void
    {
        $model = $fields['model'] ?? null;
        $name = $model ?? 'this model';
        $thinking = $fields['thinking'] ?? ['type' => 'disabled'];
        $type = $thinking['type'] ?? null;
        $adaptive = self::isAdaptiveOnlyModel($model);
        $fable = $model !== null && str_starts_with($model, 'claude-fable-5');
        $effort = $fields['outputConfig']['effort'] ?? null;

        if ($fable && ($fields['thinkingExplicitlySet'] ?? false) && $type === 'disabled') {
            throw new \InvalidArgumentException("thinking.type=\"disabled\" is not supported for {$name}; omit thinking to use adaptive thinking instead");
        }
        if ($adaptive && $type === 'enabled') {
            throw new \InvalidArgumentException("thinking.type=\"enabled\" is not supported for {$name}; use thinking.type=\"adaptive\" instead");
        }
        if ($adaptive && array_key_exists('budget_tokens', $thinking)) {
            throw new \InvalidArgumentException("thinking.budget_tokens is not supported for {$name}; use outputConfig.effort instead");
        }
        if ($model !== null && str_starts_with($model, 'claude-opus-5') && $type === 'disabled' && in_array($effort, ['xhigh', 'max'], true)) {
            throw new \InvalidArgumentException("thinking.type=\"disabled\" is not supported for {$name} with outputConfig.effort=\"{$effort}\"; use thinking.type=\"adaptive\" or omit thinking instead");
        }
        if ($adaptive) {
            if (isset($fields['topK'])) {
                throw new \InvalidArgumentException("topK is not supported for {$name}; omit topK/topP/temperature or use model prompting instead");
            }
            foreach (['topP', 'temperature'] as $key) {
                if (isset($fields[$key]) && (float) $fields[$key] !== 1.0) {
                    throw new \InvalidArgumentException("{$key} is not supported for {$name} when set to non-default values");
                }
            }
        }
        if (!$fable && self::isThinkingEnabled($thinking)) {
            foreach (['topK', 'topP'] as $key) {
                if (isset($fields[$key])) {
                    throw new \InvalidArgumentException("{$key} is not supported when thinking is enabled");
                }
            }
            if (isset($fields['temperature']) && (float) $fields['temperature'] !== 1.0) {
                throw new \InvalidArgumentException('temperature is not supported when thinking is enabled');
            }
        }
    }

    /** @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function getSamplingParams(array $fields): array
    {
        if (self::isThinkingEnabled($fields['thinking'] ?? ['type' => 'disabled']) || self::isAdaptiveOnlyModel($fields['model'] ?? null)) {
            return [];
        }
        $output = [];
        foreach (['temperature' => 'temperature', 'topK' => 'top_k', 'topP' => 'top_p'] as $key => $wire) {
            if (isset($fields[$key])) {
                $output[$wire] = $fields[$key];
            }
        }

        return $output;
    }

    /** @param array<string, mixed> $thinking */
    private static function isThinkingEnabled(array $thinking): bool
    {
        return in_array($thinking['type'] ?? null, ['enabled', 'adaptive'], true);
    }
}
