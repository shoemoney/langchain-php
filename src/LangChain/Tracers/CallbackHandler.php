<?php

declare(strict_types=1);

namespace LangChain\Tracers;

use LangChain\LanguageModels\Outputs\LLMResult;

/**
 * A callback handler assembled from a bag of callables.
 *
 * Port of the anonymous `Handler` class the TypeScript `fromMethods` helpers
 * build. It exists so a caller can pass callbacks inline without declaring a
 * named class first — which is what every ported test that asserts on callback
 * traffic does.
 *
 * Each hook routes through {@see BaseCallbackHandler::fire()}, so only the
 * keys present in the bag are invoked.
 */
final class CallbackHandler extends BaseCallbackHandler
{
    /** @var array<string, callable> */
    public array $methods;

    /**
     * @param array<string, callable> $methods
     * @param array<string, mixed>    $fields  The `ignore*`/`raiseError` flags.
     */
    public function __construct(array $methods = [], array $fields = [])
    {
        $this->methods = $methods;
        $this->name = is_string($fields['name'] ?? null) ? $fields['name'] : 'callback_handler';

        parent::__construct($fields + ['__methods' => $methods]);
    }

    public function handleLLMStart(
        Serialized $llm,
        array $prompts,
        string $runId,
        ?string $parentRunId = null,
        array $extraParams = [],
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
    ): void {
        $this->fire('handleLLMStart', [$llm, $prompts, $runId, $parentRunId, $extraParams, $tags, $metadata, $runName]);
    }

    public function handleChatModelStart(
        Serialized $llm,
        array $messages,
        string $runId,
        ?string $parentRunId = null,
        array $extraParams = [],
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
    ): void {
        $this->fire('handleChatModelStart', [$llm, $messages, $runId, $parentRunId, $extraParams, $tags, $metadata, $runName]);
    }

    public function handleLLMNewToken(
        string $token,
        array $idx,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $fields = [],
    ): void {
        $this->fire('handleLLMNewToken', [$token, $idx, $runId, $parentRunId, $tags, $fields]);
    }

    public function handleChatModelStreamEvent(
        array $event,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $this->fire('handleChatModelStreamEvent', [$event, $runId, $parentRunId, $tags]);
    }

    public function handleLLMError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $extraParams = [],
    ): void {
        $this->fire('handleLLMError', [$error, $runId, $parentRunId, $tags, $extraParams]);
    }

    public function handleLLMEnd(
        LLMResult $output,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $extraParams = [],
    ): void {
        $this->fire('handleLLMEnd', [$output, $runId, $parentRunId, $tags, $extraParams]);
    }

    public function handleChainStart(
        Serialized $chain,
        array $inputs,
        string $runId,
        ?string $runType = null,
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
        ?string $parentRunId = null,
        array $extra = [],
    ): void {
        $this->fire('handleChainStart', [$chain, $inputs, $runId, $runType, $tags, $metadata, $runName, $parentRunId, $extra]);
    }

    public function handleChainError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $kwargs = [],
    ): void {
        $this->fire('handleChainError', [$error, $runId, $parentRunId, $tags, $kwargs]);
    }

    public function handleChainEnd(
        array $outputs,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $kwargs = [],
    ): void {
        $this->fire('handleChainEnd', [$outputs, $runId, $parentRunId, $tags, $kwargs]);
    }

    public function handleToolStart(
        Serialized $tool,
        array|string $input,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
        ?string $toolCallId = null,
    ): void {
        $this->fire('handleToolStart', [$tool, $input, $runId, $parentRunId, $tags, $metadata, $runName, $toolCallId]);
    }

    public function handleToolError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $this->fire('handleToolError', [$error, $runId, $parentRunId, $tags]);
    }

    public function handleToolEnd(
        mixed $output,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $this->fire('handleToolEnd', [$output, $runId, $parentRunId, $tags]);
    }

    public function handleToolEvent(
        mixed $chunk,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $this->fire('handleToolEvent', [$chunk, $runId, $parentRunId, $tags]);
    }

    public function handleText(
        string $text,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $this->fire('handleText', [$text, $runId, $parentRunId, $tags]);
    }

    public function handleAgentAction(
        array $action,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $this->fire('handleAgentAction', [$action, $runId, $parentRunId, $tags]);
    }

    public function handleAgentEnd(
        array $action,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $this->fire('handleAgentEnd', [$action, $runId, $parentRunId, $tags]);
    }

    public function handleRetrieverStart(
        Serialized $retriever,
        string $query,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $metadata = [],
        ?string $name = null,
    ): void {
        $this->fire('handleRetrieverStart', [$retriever, $query, $runId, $parentRunId, $tags, $metadata, $name]);
    }

    public function handleRetrieverEnd(
        array $documents,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $this->fire('handleRetrieverEnd', [$documents, $runId, $parentRunId, $tags]);
    }

    public function handleRetrieverError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $this->fire('handleRetrieverError', [$error, $runId, $parentRunId, $tags]);
    }

    public function handleCustomEvent(
        string $eventName,
        mixed $data,
        string $runId,
        array $tags = [],
        array $metadata = [],
    ): void {
        $this->fire('handleCustomEvent', [$eventName, $data, $runId, $tags, $metadata]);
    }
}
