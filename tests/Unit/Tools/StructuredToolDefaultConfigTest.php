<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Tools\DynamicStructuredTool;
use LangChain\Runnables\RunnableConfig;
use PHPUnit\Framework\TestCase;

/**
 * Upstream `libs/langchain-core/src/tools/index.ts:187-188` does
 * `ensureConfig(mergeConfigs(this.defaultConfig, config))` — a generic merge with per-key rules
 * (metadata/configurable SPREAD, tags UNION-dedup, signal combined, everything else later-wins).
 * The port instead used a hand-rolled 7-field "if per-call value is falsy, use the default" chain.
 *
 * These assertions pin the four behaviours where the two disagree. `testTheSevenHandPickedKeysStillInherit`
 * is the positive control: without it, a broken merge that drops EVERYTHING would satisfy the others.
 */
final class StructuredToolDefaultConfigTest extends TestCase
{
    private function tool(array $defaultConfig): DynamicStructuredTool
    {
        return new DynamicStructuredTool(
            [
                'name' => 'probe',
                'description' => 'merge probe',
                'schema' => ['type' => 'object', 'properties' => new \stdClass()],
                'defaultConfig' => $defaultConfig,
            ],
            static fn (array $in): string => 'ok'
        );
    }

    private function merge(?array $defaultConfig, ?RunnableConfig $perCall): RunnableConfig
    {
        $method = new \ReflectionMethod(DynamicStructuredTool::class, 'mergeConfig');

        return $method->invoke($this->tool($defaultConfig ?? []), $perCall);
    }

    /** RED today: per-call metadata REPLACES the default instead of spreading with it. */
    public function testMetadataSpreadsSoBothDefaultAndPerCallKeysSurvive(): void
    {
        $perCall = new RunnableConfig(metadata: ['b' => 2]);

        $merged = $this->merge(['metadata' => ['a' => 1]], $perCall);

        self::assertSame(['a' => 1, 'b' => 2], $merged->metadata);
    }

    /** RED today: per-call tags REPLACE the default instead of unioning with it. */
    public function testTagsUnionWithTheDefaultAndDeduplicate(): void
    {
        $perCall = new RunnableConfig(tags: ['x', 'y']);

        $merged = $this->merge(['tags' => ['x', 'z']], $perCall);

        // upstream: [...new Set(baseKeys.concat(options[key]))] with defaultConfig first
        self::assertSame(['x', 'z', 'y'], $merged->tags);
    }

    /** RED today: runId is not in the hand-rolled list at all, so the default is dropped. */
    public function testRunIdIsInheritedWhenNoPerCallConfigIsGiven(): void
    {
        $merged = $this->merge(['runId' => ['default-run']], null);

        self::assertSame(['default-run'], $merged->runId);
    }

    /** RED today: same defect as metadata, on a second spread key. */
    public function testConfigurableSpreadsSoBothDefaultAndPerCallKeysSurvive(): void
    {
        $perCall = new RunnableConfig(configurable: ['b' => 2]);

        $merged = $this->merge(['configurable' => ['a' => 1]], $perCall);

        self::assertSame(['a' => 1, 'b' => 2], $merged->configurable);
    }

    /** GREEN today and after: the positive control. If this fails, the merge is broken wholesale. */
    public function testTheSevenHandPickedKeysStillInheritWhenPerCallIsAbsent(): void
    {
        $merged = $this->merge([
            'tags' => ['t'],
            'context' => ['tenant' => 'acme'],
            'configurable' => ['k' => 'v'],
            'runName' => 'from-default',
        ], null);

        self::assertSame(['t'], $merged->tags);
        self::assertSame(['tenant' => 'acme'], $merged->context);
        self::assertSame(['k' => 'v'], $merged->configurable);
        self::assertSame('from-default', $merged->runName);
    }
}
