<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Support;

use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Testing\FakeToolCallingChatModel;

/**
 * A scripted chat model that reports a chosen `getName()` (the provider-detection hook the provider middleware
 * reads) and records the tools and call options every `bindTools()` call received. The record is shared with the copies
 * `bindTools()` makes, so a bind made by an agent is visible on the model the test holds.
 */
final class BindRecordingModel extends FakeToolCallingChatModel
{
    /** @var \ArrayObject<int, list<mixed>> */
    public \ArrayObject $boundTools;

    /** @var \ArrayObject<int, array<string, mixed>> the call options each bind was given */
    public \ArrayObject $boundKwargs;

    /** @var \ArrayObject<int, list<\LangChain\Messages\BaseMessage>> the messages each generation was given */
    public \ArrayObject $generatedInputs;

    /** @param array<string, mixed> $fields */
    public function __construct(private readonly string $reportedName, array $fields = [])
    {
        parent::__construct($fields);
        $this->boundTools = new \ArrayObject();
        $this->boundKwargs = new \ArrayObject();
        $this->generatedInputs = new \ArrayObject();
    }

    public function getName(): string
    {
        return $this->reportedName;
    }

    public function bindTools(array $tools, array $kwargs = []): static
    {
        $this->boundTools->append($tools);
        $this->boundKwargs->append($kwargs);

        return parent::bindTools($tools, $kwargs);
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $this->generatedInputs->append($messages);

        return parent::generate($messages, $options, $runManager);
    }

    /** @return list<mixed> the tools of the first bind */
    public function firstBoundTools(): array
    {
        return $this->boundTools[0] ?? [];
    }
}
