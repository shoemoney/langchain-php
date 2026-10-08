<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Testing\FakeToolCallingChatModel;

/**
 * Stands in for upstream's `vi.spyOn(llm, "invoke" | "_generate" | "withStructuredOutput")`: a
 * {@see FakeToolCallingChatModel} that records the calls made to it.
 *
 * `bindTools()` hands the recorders to the bound clone by reference, so a call made through the model the agent
 * bound is seen on the model the test holds, exactly as a spy on the unbound model sees it upstream.
 */
final class SpyingToolCallingChatModel extends FakeToolCallingChatModel
{
    /** @var list<array{0: mixed, 1: RunnableConfig|null}> */
    public array $invokeCalls = [];

    /** @var list<list<\LangChain\Messages\BaseMessage>> */
    public array $generateCalls = [];

    /** @var list<array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public array $structuredOutputCalls = [];

    /** @var list<list<mixed>> The tool lists `bindTools()` was called with, on this model or any model bound from it. */
    public array $bindToolsCalls = [];

    /** @var array{runnable: Runnable|null} */
    public array $structuredOutputOverride = ['runnable' => null];

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $this->invokeCalls[] = [$input, $config];

        return parent::invoke($input, $config);
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $this->generateCalls[] = $messages;

        return parent::generate($messages, $options, $runManager);
    }

    public function withStructuredOutput(array $schema, array $config = []): Runnable
    {
        $this->structuredOutputCalls[] = [$schema, $config];

        return $this->structuredOutputOverride['runnable'] ?? parent::withStructuredOutput($schema, $config);
    }

    public function bindTools(array $tools, array $kwargs = []): static
    {
        $this->bindToolsCalls[] = $tools;
        $next = parent::bindTools($tools, $kwargs);
        $next->bindToolsCalls = &$this->bindToolsCalls;
        $next->invokeCalls = &$this->invokeCalls;
        $next->generateCalls = &$this->generateCalls;
        $next->structuredOutputCalls = &$this->structuredOutputCalls;
        $next->structuredOutputOverride = &$this->structuredOutputOverride;

        return $next;
    }
}
