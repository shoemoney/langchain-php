<?php

declare(strict_types=1);

namespace LangChain\Tracers;

use LangChain\LanguageModels\Outputs\LLMResult;

/**
 * The per-run view for a completion or chat-model call.
 *
 * Port of `CallbackManagerForLLMRun` from `@langchain/core/callbacks/manager`.
 *
 * The `ignoreLLM` gate on every method here is the reason a handler can
 * subscribe to tool events without also receiving every token of every model
 * call that happens to be in the same chain.
 */
final class CallbackManagerForLLMRun extends BaseRunManager
{
    /**
     * One streamed token.
     *
     * @param array{prompt?: int, completion?: int} $idx Which prompt and which
     *        completion produced this token. Always 0/0 in a single-prompt call,
     *        but a batched model emits several interleaved.
     * @param array{chunk?: mixed} $fields
     */
    public function handleLLMNewToken(
        string $token,
        ?array $idx = null,
        array $fields = [],
    ): void {
        $idx ??= ['prompt' => 0, 'completion' => 0];
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreLLM) {
                continue;
            }
            $this->dispatch(
                $handler,
                'handleLLMNewToken',
                [$token, $idx, $this->runId, $this->parentRunId, $this->tags, $fields],
            );
        }
    }

    /**
     * One content-block lifecycle event from a chat model.
     *
     * @param list<string> $tags
     */
    public function handleChatModelStreamEvent(array $event, array $tags = []): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreLLM) {
                continue;
            }
            $this->dispatch($handler, 'handleChatModelStreamEvent', [$event, $this->runId, $this->parentRunId, $this->tags]);
        }
    }

    /**
     * The call failed.
     */
    public function handleLLMError(\Throwable $error, array $extraParams = []): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreLLM) {
                continue;
            }
            $this->dispatch($handler, 'handleLLMError', [$error, $this->runId, $this->parentRunId, $this->tags, $extraParams]);
        }
    }

    /**
     * The call succeeded.
     */
    public function handleLLMEnd(LLMResult $output, array $extraParams = []): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreLLM) {
                continue;
            }
            $this->dispatch($handler, 'handleLLMEnd', [$output, $this->runId, $this->parentRunId, $this->tags, $extraParams]);
        }
    }
}
