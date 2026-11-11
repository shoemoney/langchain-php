<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Utils;

use LangChain\LanguageModels\Chat\Anthropic\Utils\Params;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Params::class)]
final class ParamsTest extends TestCase
{
    public static function models(): iterable
    {
        foreach (['claude-opus-4-7', 'claude-opus-4-8', 'claude-opus-5', 'claude-sonnet-5', 'claude-fable-5', 'claude-fable-5-1', 'claude-mythos-5', 'claude-mythos-preview'] as $model) {
            yield $model => [$model . '-20261001'];
        }
    }

    #[DataProvider('models')]
    public function testAdaptiveOnlyPrefixesStripDefaultSampling(string $model): void
    {
        self::assertTrue(Params::isAdaptiveOnlyModel($model));
        $fields = ['model' => $model, 'thinking' => ['type' => 'disabled'], 'temperature' => 1, 'topP' => 1];
        Params::validateInvocationParamCompatibility($fields);
        self::assertSame([], Params::getSamplingParams($fields));
    }

    public static function invalid(): iterable
    {
        foreach (self::models() as $label => [$model]) {
            yield "$label enabled" => [['model' => $model, 'thinking' => ['type' => 'enabled']], "thinking.type=\"enabled\" is not supported for {$model}; use thinking.type=\"adaptive\" instead"];
            yield "$label budget" => [['model' => $model, 'thinking' => ['type' => 'adaptive', 'budget_tokens' => null]], "thinking.budget_tokens is not supported for {$model}; use outputConfig.effort instead"];
            yield "$label topK" => [['model' => $model, 'topK' => 0], "topK is not supported for {$model}; omit topK/topP/temperature or use model prompting instead"];
            foreach (['topP', 'temperature'] as $key) {
                yield "$label $key" => [['model' => $model, $key => 0], "{$key} is not supported for {$model} when set to non-default values"];
            }
        }
        foreach (['xhigh', 'max'] as $effort) {
            yield "opus5 $effort" => [['model' => 'claude-opus-5', 'outputConfig' => ['effort' => $effort]], "thinking.type=\"disabled\" is not supported for claude-opus-5 with outputConfig.effort=\"{$effort}\"; use thinking.type=\"adaptive\" or omit thinking instead"];
        }
        yield 'fable explicit disabled' => [['model' => 'claude-fable-5-1', 'thinkingExplicitlySet' => true], 'thinking.type="disabled" is not supported for claude-fable-5-1; omit thinking to use adaptive thinking instead'];
        foreach (['enabled', 'adaptive'] as $type) {
            foreach (['topK' => 0, 'topP' => 1, 'temperature' => 0.5] as $key => $value) {
                yield "generic $type $key" => [['thinking' => ['type' => $type], $key => $value], "{$key} is not supported when thinking is enabled"];
            }
        }
    }

    #[DataProvider('invalid')]
    public function testCompatibilityErrorsMatchUpstream(array $fields, string $error): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($error);
        Params::validateInvocationParamCompatibility($fields);
    }

    public function testOrdinaryModelSamplingAndThinkingDefaults(): void
    {
        self::assertFalse(Params::isAdaptiveOnlyModel('claude-opus-4-6'));
        self::assertFalse(Params::isAdaptiveOnlyModel());
        self::assertSame(['temperature' => 0, 'top_k' => 10, 'top_p' => 0.9], Params::getSamplingParams(['temperature' => 0, 'topK' => 10, 'topP' => 0.9]));
        self::assertSame([], Params::getSamplingParams([]));
        foreach (['enabled', 'adaptive'] as $type) {
            $fields = ['thinking' => ['type' => $type], 'temperature' => 1];
            Params::validateInvocationParamCompatibility($fields);
            self::assertSame([], Params::getSamplingParams($fields));
        }
        // Fable permits default topP with explicitly adaptive thinking; other adaptive models do not.
        Params::validateInvocationParamCompatibility(['model' => 'claude-fable-5', 'thinking' => ['type' => 'adaptive'], 'topP' => 1]);
    }

    public function testTaskBudgetBetaIsLimitedToOpus47AndNonNullBudget(): void
    {
        self::assertTrue(Params::isOpus47Model('claude-opus-4-7-20261001'));
        self::assertFalse(Params::isOpus47Model());
        foreach ([[], ['task_budget' => null]] as $config) {
            self::assertSame([], Params::getTaskBudgetBetas('claude-opus-4-7', $config));
        }
        foreach (['claude-opus-4-8', 'claude-opus-5', null] as $model) {
            self::assertSame([], Params::getTaskBudgetBetas($model, ['task_budget' => ['type' => 'tokens', 'total' => 0]]));
        }
        self::assertSame(['task-budgets-2026-03-13'], Params::getTaskBudgetBetas('claude-opus-4-7', ['task_budget' => 0]));
    }
}
