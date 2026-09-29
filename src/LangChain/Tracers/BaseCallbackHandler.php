<?php

declare(strict_types=1);

namespace LangChain\Tracers;

use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\GenerationChunk;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Messages\BaseMessage;
use LangChain\Schema\Document;

/**
 * The base class every callback handler extends.
 *
 * Port of `BaseCallbackHandler` from `@langchain/core/callbacks/base`.
 *
 * In TypeScript every hook is optional (`handleLLMStart?`), so a handler
 * declares only what it cares about and the manager calls through the
 * optional-chaining operator. PHP has no optional methods, so every hook here
 * is a concrete no-op and a subclass overrides the ones it needs. Observable
 * behaviour is identical: an unoverridden hook does nothing.
 *
 * The `ignore*` flags are the other half of that contract. A handler that
 * wants tool events but not LLM events sets `ignoreLLM = true`, and the manager
 * skips it without the handler having to write a single guard.
 */
abstract class BaseCallbackHandler
{
    /** The handler's identifier, matched by name in trace configuration. */
    public string $name = '';

    /** Skip LLM/chat-model events. */
    public bool $ignoreLLM = false;

    /** Skip chain events. */
    public bool $ignoreChain = false;

    /** Skip agent and tool events. */
    public bool $ignoreAgent = false;

    /** Skip retriever events. */
    public bool $ignoreRetriever = false;

    /** Skip custom events. */
    public bool $ignoreCustomEvent = false;

    /**
     * Let exceptions out of a hook instead of swallowing them.
     *
     * The default is to swallow-and-warn: a broken observer must not break the
     * application it is observing. A handler that IS the assertion (a test
     * double, a hard invariant check) turns this on so its failures surface.
     */
    public bool $raiseError = false;

    /**
     * Whether hooks are awaited inline.
     *
     * In the TypeScript original this selects between fire-and-forget
     * background dispatch and awaiting the handler. PHP callbacks are
     * synchronous, so there is nothing to background and this is always true;
     * it is retained because the ported configuration logic reads it.
     */
    public bool $awaitHandlers = true;

    /**
     * This handler would rather receive streamed chunks than a complete result.
     *
     * A language model checks this on its run managers: when set, it routes
     * through `_streamResponseChunks` and aggregates, so a token-by-token
     * consumer still sees every token even though it called `invoke()`.
     */
    public bool $preferStreaming = false;

    /**
     * Prefer the content-block event protocol over legacy token callbacks.
     */
    public bool $preferChatModelStreamEvents = false;

    /** @var array<string, mixed> The constructor arguments, for serialization. */
    public array $kwargs = [];

    public function __construct(array $fields = [])
    {
        $this->kwargs = $fields;

        if (isset($fields['ignoreLLM'])) {
            $this->ignoreLLM = (bool) $fields['ignoreLLM'];
        }
        if (isset($fields['ignoreChain'])) {
            $this->ignoreChain = (bool) $fields['ignoreChain'];
        }
        if (isset($fields['ignoreAgent'])) {
            $this->ignoreAgent = (bool) $fields['ignoreAgent'];
        }
        if (isset($fields['ignoreRetriever'])) {
            $this->ignoreRetriever = (bool) $fields['ignoreRetriever'];
        }
        if (isset($fields['ignoreCustomEvent'])) {
            $this->ignoreCustomEvent = (bool) $fields['ignoreCustomEvent'];
        }
        if (isset($fields['raiseError'])) {
            $this->raiseError = (bool) $fields['raiseError'];
        }
        if ($this->raiseError) {
            $this->awaitHandlers = true;
        } elseif (isset($fields['awaitHandlers'])) {
            $this->awaitHandlers = (bool) $fields['awaitHandlers'];
        }
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'callbacks', 'BaseCallbackHandler'];
    }

    public function toJson(): array
    {
        return [
            'lc' => 1,
            'type' => 'constructor',
            'id' => static::lcId(),
            'kwargs' => $this->kwargs,
        ];
    }

    /**
     * A detached copy of this handler.
     *
     * PHP objects are handle-based, so "copy" is identity unless a subclass
     * genuinely holds per-use state. The method exists because the callback
     * manager calls it when folding handlers into child managers, and a
     * subclass that does accumulate state must override it.
     */
    public function copy(): static
    {
        return $this;
    }

    /**
     * Build a handler from a bag of callables.
     *
     * Port of `BaseCallbackHandler.fromMethods`. This is the PHP answer to the
     * TypeScript idiom of passing an object literal as a callback:
     *
     * ```php
     * CallbackHandler::fromMethods([
     *     'handleLLMNewToken' => fn (string $token) => $acc .= $token,
     * ]);
     * ```
     *
     * @param array<string, callable> $methods
     */
    public static function fromMethods(array $methods, array $fields = []): CallbackHandler
    {
        return new CallbackHandler($methods, $fields);
    }

    // ---- hooks ------------------------------------------------------------

    /**
     * An LLM run began, with its raw prompt strings.
     *
     * @param list<string>         $prompts
     * @param array<string, mixed> $extraParams
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     */
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
    }

    /**
     * A chat-model run began, with its structured messages.
     *
     * @param list<list<BaseMessage>> $messages
     * @param array<string, mixed>    $extraParams
     * @param list<string>            $tags
     * @param array<string, mixed>    $metadata
     */
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
    }

    /**
     * A streaming LLM produced one token or chunk.
     *
     * @param array{prompt?: int, completion?: int} $idx
     * @param list<string>                          $tags
     * @param array{chunk?: GenerationChunk|ChatGenerationChunk} $fields
     */
    public function handleLLMNewToken(
        string $token,
        array $idx,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $fields = [],
    ): void {
    }

    /**
     * A streaming chat model emitted a content-block lifecycle event.
     *
     * @param list<string> $tags
     */
    public function handleChatModelStreamEvent(
        array $event,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
    }

    /**
     * An LLM run failed.
     *
     * @param list<string>         $tags
     * @param array<string, mixed> $extraParams
     */
    public function handleLLMError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $extraParams = [],
    ): void {
    }

    /**
     * An LLM run finished.
     *
     * @param list<string>         $tags
     * @param array<string, mixed> $extraParams
     */
    public function handleLLMEnd(
        LLMResult $output,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $extraParams = [],
    ): void {
    }

    /**
     * A chain run began.
     *
     * @param array<string, mixed> $inputs
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $extra
     */
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
    }

    /**
     * A chain run failed.
     *
     * @param list<string>         $tags
     * @param array{inputs?: array<string, mixed>} $kwargs
     */
    public function handleChainError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $kwargs = [],
    ): void {
    }

    /**
     * A chain run finished.
     *
     * @param array<string, mixed> $outputs
     * @param list<string>         $tags
     * @param array{inputs?: array<string, mixed>} $kwargs
     */
    public function handleChainEnd(
        array $outputs,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $kwargs = [],
    ): void {
    }

    /**
     * A tool run began.
     *
     * @param array<string, mixed> $input
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     */
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
    }

    /**
     * A tool run failed.
     *
     * @param list<string> $tags
     */
    public function handleToolError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
    }

    /**
     * A tool run finished.
     *
     * @param list<string> $tags
     */
    public function handleToolEnd(
        mixed $output,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
    }

    /**
     * A streaming tool yielded an intermediate value.
     *
     * @param list<string> $tags
     */
    public function handleToolEvent(
        mixed $chunk,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
    }

    /**
     * Arbitrary text within a chain run.
     *
     * @param list<string> $tags
     */
    public function handleText(
        string $text,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
    }

    /**
     * An agent chose an action.
     *
     * @param list<string> $tags
     */
    public function handleAgentAction(
        array $action,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
    }

    /**
     * An agent finished.
     *
     * @param list<string> $tags
     */
    public function handleAgentEnd(
        array $action,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
    }

    /**
     * A retriever run began.
     *
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     */
    public function handleRetrieverStart(
        Serialized $retriever,
        string $query,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $metadata = [],
        ?string $name = null,
    ): void {
    }

    /**
     * A retriever run finished.
     *
     * @param list<Document> $documents
     * @param list<string>   $tags
     */
    public function handleRetrieverEnd(
        array $documents,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
    }

    /**
     * A retriever run failed.
     *
     * @param list<string> $tags
     */
    public function handleRetrieverError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
    }

    /**
     * A component emitted an application-defined event.
     *
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     */
    public function handleCustomEvent(
        string $eventName,
        mixed $data,
        string $runId,
        array $tags = [],
        array $metadata = [],
    ): void {
    }

    /**
     * Dispatch to a method-bag entry, or do nothing.
     *
     * {@see CallbackHandler} overrides every hook to route through here, which
     * is how a bag-of-callables handler gets the same "only what you define"
     * behaviour as a subclass in the TypeScript original.
     *
     * @param array<int, mixed> $arguments
     */
    protected function fire(string $method, array $arguments): void
    {
        $methods = $this->kwargs['__methods'] ?? null;
        if (is_array($methods) && isset($methods[$method]) && is_callable($methods[$method])) {
            ($methods[$method])(...$arguments);
        }
    }

    /**
     * Whether this handler actually implements the named hook.
     *
     * The callback manager consults this where the TypeScript source branches
     * on `'handleChatModelStart' in handler` — most visibly when falling back
     * from a chat-model hook to the legacy LLM one.
     */
    public function implements(string $method): bool
    {
        if (str_starts_with($method, 'handle')) {
            // A method-bag handler answers from its bag, not from its class
            // hierarchy. Both are consulted, and the bag is checked first
            // because a handler built from `fromMethods()` overrides every hook
            // on the class while implementing none of them.
            $methods = $this->kwargs['__methods'] ?? null;
            if (is_array($methods)) {
                return isset($methods[$method]);
            }

            return (new \ReflectionMethod($this, $method))->getDeclaringClass()->getName() !== self::class;
        }

        return method_exists($this, $method);
    }
}
