<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Support;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangGraph\Agents\ConfigurableModelInterface;

/**
 * Port of `FakeConfigurableModel` from `langchain/src/agents/tests/utils.ts`: a chat model that defers to
 * another and records the method calls queued on it, the way `initChatModel`'s `ConfigurableModel` does.
 */
final class FakeConfigurableModel extends BaseChatModel implements ConfigurableModelInterface
{
    /** @var array<string, mixed> */
    public array $queuedMethodOperations = [];

    public BaseChatModel $chatModel;

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
        $this->chatModel = $fields['model'];
    }

    public function llmType(): string
    {
        return 'fake_configurable';
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        throw new \Exception('Not implemented');
    }

    public function getQueuedMethodOperations(): array
    {
        return $this->queuedMethodOperations;
    }

    public function getModelInstance(): BaseChatModel
    {
        return $this->chatModel;
    }

    public function bindTools(array $tools, array $kwargs = []): static
    {
        $withTools = new self(['model' => $this->chatModel->bindTools($tools)]);
        $withTools->queuedMethodOperations['bindTools'] = $tools;

        return $withTools;
    }
}
