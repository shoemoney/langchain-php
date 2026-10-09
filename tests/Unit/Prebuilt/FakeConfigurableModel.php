<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangGraph\Prebuilt\ConfigurableModelInterface;

/**
 * Port of `FakeConfigurableModel` from `langgraph-core/src/tests/utils.models.ts`: a chat model that is only a
 * wrapper, resolving to the model it holds through `getModelInstance()` (what `initChatModel` returns upstream).
 */
final class FakeConfigurableModel extends BaseChatModel implements ConfigurableModelInterface
{
    /** @var array<string, mixed> */
    private array $queuedMethodOperations = [];

    private RunnableInterface $chatModel;

    /** @param array{model: RunnableInterface}&array<string, mixed> $fields */
    public function __construct(array $fields)
    {
        parent::__construct($fields);
        $this->chatModel = $fields['model'];
    }

    public function llmType(): string
    {
        return 'fake_configurable';
    }

    /**
     * @param list<\LangChain\Messages\BaseMessage> $messages
     * @param array<string, mixed>                  $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        throw new \Exception('Not implemented');
    }

    public function getQueuedMethodOperations(): array
    {
        return $this->queuedMethodOperations;
    }

    public function getModelInstance(?RunnableConfig $config = null): RunnableInterface
    {
        return $this->chatModel;
    }

    /** The upstream spelling (`_queuedMethodOperations`), kept for the ported tests that read it. */
    public function queuedMethodOperations(): array
    {
        return $this->getQueuedMethodOperations();
    }

    /** The upstream spelling (`_model`), kept for the ported tests that read it. */
    public function model(): RunnableInterface
    {
        return $this->getModelInstance();
    }

    public function bindTools(array $tools, array $kwargs = []): static
    {
        $inner = $this->chatModel;
        if (!$inner instanceof BaseChatModel) {
            throw new \Exception('The wrapped model cannot bind tools.');
        }

        $modelWithTools = new self(['model' => $inner->bindTools($tools)]);
        $modelWithTools->queuedMethodOperations['bindTools'] = $tools;

        return $modelWithTools;
    }
}
