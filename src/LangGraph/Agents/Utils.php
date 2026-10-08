<?php

declare(strict_types=1);

namespace LangGraph\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableSequence;
use LangGraph\Agents\Errors\MiddlewareError;
use LangGraph\Agents\Errors\MultipleToolsBoundError;
use LangGraph\Pregel\Command;
use LangGraph\State\AnnotationRoot;

/**
 * Helpers shared by the agent's nodes.
 *
 * Port of `langchain/src/agents/utils.ts`.
 *
 * A middleware is an array with a `name` (and optionally `stateSchema`, `wrapToolCall`) or an object
 * with those properties, since `createAgent`'s middleware type belongs to a later work package.
 * A state schema is an {@see AnnotationRoot} or a JSON Schema array (`properties`/`required`); upstream's
 * Zod and `StateSchema` forms have no port.
 *
 * Tool-call requests (upstream `ToolCallRequest`) are arrays `['toolCall' => …, 'tool' => …, 'state' => …,
 * 'runtime' => Runtime]`, so upstream's `{ ...request, state }` spread is `array_merge`.
 *
 * `LangChain\LanguageModels` cannot be imported here (layering guard), so chat models are named inline.
 */
final class Utils
{
    public const AGENT_NAME_MODE_INLINE = 'inline';

    private const NAME_PATTERN = '~<name>(.*?)</name>~s';
    private const CONTENT_PATTERN = '~<content>(.*?)</content>~s';

    /**
     * Static LangGraph config keys propagated from ReactAgent defaults onto the compiled inner graph.
     */
    private const GRAPH_DEFAULT_CONFIG_KEYS = [
        'callbacks',
        'tags',
        'metadata',
        'runName',
        'maxConcurrency',
        'recursionLimit',
        'configurable',
    ];

    private function __construct()
    {
    }

    // ---- middleware accessors -----------------------------------------------------------------

    /** @param array<string, mixed>|object $middleware */
    public static function middlewareName(array|object $middleware): string
    {
        return (string) self::middlewareValue($middleware, 'name');
    }

    /** @param array<string, mixed>|object $middleware */
    public static function middlewareValue(array|object $middleware, string $key): mixed
    {
        if (\is_array($middleware)) {
            return $middleware[$key] ?? null;
        }

        return $middleware->{$key} ?? null;
    }

    // ---- state schema -------------------------------------------------------------------------

    /**
     * Parse middleware state from the full agent state based on the middleware's state schema.
     *
     * An {@see AnnotationRoot} yields only the keys it declares; a JSON Schema yields the keys it lists
     * under `properties` (upstream: `interopParse`, which also strips unknown keys). Defaults are not
     * applied here, and required properties are not enforced: that is `initializeMiddlewareStates`'s job.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function parseMiddlewareState(mixed $stateSchema, array $state): array
    {
        $keys = self::schemaKeys($stateSchema);
        if ($keys === null) {
            throw new \Exception('Invalid state schema type: ' . get_debug_type($stateSchema));
        }

        $result = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $state)) {
                $result[$key] = $state[$key];
            }
        }

        return $result;
    }

    /**
     * The property names a state schema declares, or null when the value is not a schema.
     *
     * @return list<string>|null
     */
    public static function schemaKeys(mixed $schema): ?array
    {
        if (AnnotationRoot::isInstance($schema)) {
            return array_map('strval', array_keys($schema->spec));
        }
        if (\is_array($schema) && \is_array($schema['properties'] ?? null)) {
            return array_map('strval', array_keys($schema['properties']));
        }

        return null;
    }

    // ---- inline agent name --------------------------------------------------------------------

    /**
     * Attach the agent name to an AI message's content using XML-style tags.
     *
     * Port of `_addInlineAgentName`. Anything that is not a finished, named AI message passes through
     * (a streamed chunk included). The rebuilt message keeps every constructor field except `name`.
     *
     * @template T
     * @param T $message
     * @return T|AIMessage
     */
    public static function addInlineAgentName(mixed $message): mixed
    {
        if (!$message instanceof AIMessage) {
            return $message;
        }

        if ($message->name === null || $message->name === '') {
            return $message;
        }

        $name = $message->name;

        if (\is_string($message->content)) {
            return self::rebuildAi($message, '<name>' . $name . '</name><content>' . $message->content . '</content>', null);
        }

        $updated = [];
        $textBlocks = 0;
        foreach ($message->content as $block) {
            if (\is_string($block)) {
                $textBlocks++;
                $updated[] = '<name>' . $name . '</name><content>' . $block . '</content>';
            } elseif (\is_array($block) && ($block['type'] ?? null) === 'text') {
                $textBlocks++;
                $block['text'] = '<name>' . $name . '</name><content>' . (string) ($block['text'] ?? '') . '</content>';
                $updated[] = $block;
            } else {
                $updated[] = $block;
            }
        }

        if ($textBlocks === 0) {
            array_unshift($updated, ['type' => 'text', 'text' => '<name>' . $name . '</name><content></content>']);
        }

        return self::rebuildAi($message, $updated, null);
    }

    /**
     * Remove explicit name and content XML tags from the AI message content.
     *
     * Port of `_removeInlineAgentName`.
     *
     * @template T of BaseMessage
     * @param T $message
     * @return T|AIMessage
     */
    public static function removeInlineAgentName(BaseMessage $message): BaseMessage
    {
        if (!($message instanceof AIMessage || $message instanceof AIMessageChunk)) {
            return $message;
        }
        if ($message->content === '') {
            return $message;
        }

        $updatedName = null;

        if (\is_array($message->content)) {
            $kept = [];
            foreach ($message->content as $block) {
                if (self::isTextBlock($block)) {
                    $nameMatch = preg_match(self::NAME_PATTERN, $block['text'], $nm) === 1;
                    $contentMatch = preg_match(self::CONTENT_PATTERN, $block['text'], $cm) === 1;
                    // Drop empty content blocks that were added only because there was no text block to modify.
                    if ($nameMatch && (!$contentMatch || $cm[1] === '')) {
                        $updatedName = $nm[1];
                        continue;
                    }
                }
                $kept[] = $block;
            }

            $updatedContent = [];
            foreach ($kept as $block) {
                if (self::isTextBlock($block)) {
                    $nameMatch = preg_match(self::NAME_PATTERN, $block['text'], $nm) === 1;
                    $contentMatch = preg_match(self::CONTENT_PATTERN, $block['text'], $cm) === 1;
                    if ($nameMatch && $contentMatch) {
                        $updatedName = $nm[1];
                        $block['text'] = $cm[1];
                    }
                }
                $updatedContent[] = $block;
            }

            return self::rebuildAi($message, $updatedContent, $updatedName);
        }

        if (preg_match(self::NAME_PATTERN, $message->content, $nm) !== 1 || preg_match(self::CONTENT_PATTERN, $message->content, $cm) !== 1) {
            return $message;
        }

        return self::rebuildAi($message, $cm[1], $nm[1]);
    }

    /**
     * @phpstan-assert-if-true array{type: 'text', text: string} $block
     */
    private static function isTextBlock(mixed $block): bool
    {
        return \is_array($block) && ($block['type'] ?? null) === 'text' && \is_string($block['text'] ?? null);
    }

    /**
     * `new AIMessage({ ...message.lc_kwargs, content, name })`.
     *
     * @param string|list<mixed> $content
     */
    private static function rebuildAi(AIMessage|AIMessageChunk $message, string|array $content, ?string $name): AIMessage
    {
        $source = $message instanceof AIMessageChunk ? $message->toMessage() : $message;
        $fields = $source->kwargs();
        $fields['content'] = $content;
        unset($fields['name']);
        if ($name !== null) {
            $fields['name'] = $name;
        }

        return new AIMessage($fields);
    }

    // ---- tools & models -----------------------------------------------------------------------

    /**
     * Upstream: `Runnable.isRunnable(tool)`.
     */
    public static function isClientTool(mixed $tool): bool
    {
        return $tool instanceof RunnableInterface;
    }

    /**
     * Check if the LLM already has bound tools and throw if it does.
     *
     * A model passed as a callable (not a runnable) cannot be validated until runtime and is skipped.
     * Where upstream checks a `tools` property on the model, a chat model's bound `kwargs()['tools']`
     * (how this port's providers record `bindTools`) counts too.
     *
     * @throws MultipleToolsBoundError
     */
    public static function validateLLMHasNoBoundTools(mixed $llm): void
    {
        if (!\is_object($llm) || (\is_callable($llm) && !$llm instanceof RunnableInterface)) {
            return;
        }

        $model = $llm;

        if ($model instanceof RunnableSequence) {
            $binding = null;
            foreach ($model->steps as $step) {
                if ($step instanceof RunnableBinding) {
                    $binding = $step;
                    break;
                }
            }
            $model = $binding ?? $model;
        }

        // The underlying model is resolved lazily; it cannot be validated up front.
        if (Model::isConfigurableModel($model)) {
            return;
        }

        if ($model instanceof RunnableBinding) {
            $configTools = $model->config['tools'] ?? ($model->config['options']['tools'] ?? null);
            if (self::nonEmptyList($model->kwargs['tools'] ?? null) || self::nonEmptyList($configTools)) {
                throw new MultipleToolsBoundError();
            }
        }

        if (property_exists($model, 'tools') && self::nonEmptyList($model->tools)) {
            throw new MultipleToolsBoundError();
        }

        if (Model::isBaseChatModel($model) && self::nonEmptyList($model->kwargs()['tools'] ?? null)) {
            throw new MultipleToolsBoundError();
        }
    }

    private static function nonEmptyList(mixed $value): bool
    {
        return \is_array($value) && $value !== [];
    }

    /**
     * Whether the message is an AI message carrying tool calls.
     */
    public static function hasToolCalls(?BaseMessage $message = null): bool
    {
        return $message instanceof AIMessage && $message->toolCalls !== [];
    }

    /**
     * Normalizes a system prompt to a SystemMessage.
     *
     * A SystemMessage is returned as-is; a string becomes one text block; null becomes an empty message
     * so it is easier to append to later.
     */
    public static function normalizeSystemPrompt(string|SystemMessage|null $systemPrompt = null): SystemMessage
    {
        if ($systemPrompt === null) {
            return new SystemMessage('');
        }
        if ($systemPrompt instanceof SystemMessage) {
            return $systemPrompt;
        }

        return new SystemMessage(['content' => [['type' => 'text', 'text' => $systemPrompt]]]);
    }

    /**
     * Bind tools to a language model.
     *
     * Port of `bindTools`. Upstream is async only because a configurable model resolves its instance
     * asynchronously; here that resolution is synchronous.
     *
     * @param list<mixed>          $toolClasses
     * @param array<string, mixed> $options
     */
    public static function bindTools(RunnableInterface $llm, array $toolClasses, array $options = []): RunnableInterface
    {
        $model = self::simpleBindTools($llm, $toolClasses, $options);
        if ($model !== null) {
            return $model;
        }

        if ($llm instanceof ConfigurableModelInterface) {
            $model = self::simpleBindTools($llm->getModelInstance(), $toolClasses, $options);
            if ($model !== null) {
                return $model;
            }
        }

        if ($llm instanceof RunnableSequence) {
            $modelStep = null;
            foreach ($llm->steps as $index => $step) {
                if ($step instanceof RunnableBinding || Model::isBaseChatModel($step) || Model::isConfigurableModel($step)) {
                    $modelStep = $index;
                    break;
                }
            }

            if ($modelStep !== null) {
                $model = self::simpleBindTools($llm->steps[$modelStep], $toolClasses, $options);
                if ($model !== null) {
                    $nextSteps = $llm->steps;
                    $nextSteps[$modelStep] = $model;

                    return RunnableSequence::from($nextSteps);
                }
            }
        }

        throw new \Exception(sprintf('llm %s must define bindTools method.', $llm->getName()));
    }

    /**
     * @param list<mixed>          $toolClasses
     * @param array<string, mixed> $options
     */
    private static function simpleBindTools(RunnableInterface $llm, array $toolClasses, array $options): ?RunnableInterface
    {
        if (self::isChatModelWithBindTools($llm)) {
            return $llm->bindTools($toolClasses, $options);
        }

        if ($llm instanceof RunnableBinding && self::isChatModelWithBindTools($llm->bound)) {
            $newBound = $llm->bound->bindTools($toolClasses, $options);

            if ($newBound instanceof RunnableBinding) {
                return new RunnableBinding(
                    bound: $newBound->bound,
                    kwargs: [...$llm->kwargs, ...$newBound->kwargs],
                    config: self::mergeBindingConfig($llm->config, $newBound->config),
                );
            }

            return new RunnableBinding(bound: $newBound, kwargs: $llm->kwargs, config: $llm->config);
        }

        // Upstream's `withConfig` on a binding folds into ONE binding; this port's `Runnable::withConfig`
        // nests a binding inside a binding, so look through the outer layers to the model.
        if ($llm instanceof RunnableBinding && $llm->bound instanceof RunnableBinding) {
            $inner = self::simpleBindTools($llm->bound, $toolClasses, $options);

            return $inner === null ? null : new RunnableBinding(bound: $inner, kwargs: $llm->kwargs, config: $llm->config);
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $outer
     * @param array<string, mixed>|null $inner
     * @return array<string, mixed>|null
     */
    private static function mergeBindingConfig(?array $outer, ?array $inner): ?array
    {
        return $outer === null && $inner === null ? null : [...($outer ?? []), ...($inner ?? [])];
    }

    /**
     * @phpstan-assert-if-true \LangChain\LanguageModels\BaseChatModel $llm
     */
    private static function isChatModelWithBindTools(mixed $llm): bool
    {
        return Model::isBaseChatModel($llm) && $llm->supportsToolBinding();
    }

    // ---- wrapToolCall -------------------------------------------------------------------------

    /**
     * Compose several `wrapToolCall` handlers into one middleware stack.
     *
     * Port of `chainToolCallHandlers`: the first handler is the outermost layer. Each handler is
     * `fn(array $request, callable $handler): ToolMessage|Command`.
     *
     * @param list<callable> $handlers
     */
    public static function chainToolCallHandlers(array $handlers): ?callable
    {
        if ($handlers === []) {
            return null;
        }

        if (\count($handlers) === 1) {
            return $handlers[0];
        }

        // outer(inner(innermost(handler))): fold from the right.
        $result = $handlers[\count($handlers) - 1];
        for ($i = \count($handlers) - 2; $i >= 0; $i--) {
            $result = self::composeTwo($handlers[$i], $result);
        }

        return $result;
    }

    private static function composeTwo(callable $outer, callable $inner): callable
    {
        return static function (array $request, callable $handler) use ($outer, $inner): mixed {
            // The inner layer receives the request the outer one passed (possibly modified) and the base handler.
            $innerHandler = static fn (array $passedRequest): mixed => $inner($passedRequest, $handler);

            return $outer($request, $innerHandler);
        };
    }

    /**
     * Combine every middleware's `wrapToolCall` into a single wrapper, injecting the middleware name
     * into errors.
     *
     * Port of `wrapToolCall`. Each middleware sees the full ORIGINAL state parsed through its own state
     * schema, however earlier layers modified the request. A result that is neither a ToolMessage nor a
     * Command is rejected, and a thrown error is wrapped in a {@see MiddlewareError} unless the
     * downstream handler threw that same error (then it propagates unchanged).
     *
     * @param iterable<array<string, mixed>|object> $middleware
     */
    public static function wrapToolCall(iterable $middleware): ?callable
    {
        $withHook = [];
        foreach ($middleware as $m) {
            if (self::middlewareValue($m, 'wrapToolCall') !== null) {
                $withHook[] = $m;
            }
        }

        if ($withHook === []) {
            return null;
        }

        $wrapped = [];
        foreach ($withHook as $m) {
            $name = self::middlewareName($m);
            /** @var callable $originalHandler */
            $originalHandler = self::middlewareValue($m, 'wrapToolCall');
            $stateSchema = self::middlewareValue($m, 'stateSchema');

            $wrapped[] = static function (array $request, callable $handler) use ($name, $originalHandler, $stateSchema): mixed {
                $originalState = (array) ($request['state'] ?? []);

                // Exact values thrown downstream, so unchanged propagation is not misclassified as a
                // failure in this middleware.
                /** @var \WeakMap<\Throwable, true> $downstreamErrors */
                $downstreamErrors = new \WeakMap();

                $wrappedInnerHandler = static function (array $passedRequest) use ($handler, $originalState, $downstreamErrors): mixed {
                    $mergedState = [...$originalState, ...(array) ($passedRequest['state'] ?? [])];
                    try {
                        return $handler([...$passedRequest, 'state' => $mergedState]);
                    } catch (\Throwable $error) {
                        $downstreamErrors[$error] = true;

                        throw $error;
                    }
                };

                try {
                    $result = $originalHandler(
                        [
                            ...$request,
                            'state' => [
                                'messages' => $originalState['messages'] ?? null,
                                ...($stateSchema !== null ? self::parseMiddlewareState($stateSchema, $originalState) : []),
                            ],
                        ],
                        $wrappedInnerHandler,
                    );

                    if (!$result instanceof ToolMessage && !Command::isCommand($result)) {
                        throw new \Exception(sprintf(
                            'Invalid response from "wrapToolCall" in middleware "%s": expected ToolMessage or Command, got %s',
                            $name,
                            get_debug_type($result),
                        ));
                    }

                    return $result;
                } catch (\Throwable $error) {
                    if (isset($downstreamErrors[$error])) {
                        throw $error;
                    }

                    throw MiddlewareError::wrap($error, $name);
                }
            };
        }

        return self::chainToolCallHandlers($wrapped);
    }

    // ---- graph config -------------------------------------------------------------------------

    /**
     * The static LangGraph config propagated from ReactAgent defaults onto the compiled inner graph.
     *
     * Port of `toGraphDefaultConfig`; keys left unset are omitted.
     *
     * @return array<string, mixed>
     */
    public static function toGraphDefaultConfig(RunnableConfig $config): array
    {
        $defaults = new RunnableConfig();
        $result = [];
        foreach (self::GRAPH_DEFAULT_CONFIG_KEYS as $key) {
            $value = $config->{$key};
            // Upstream drops `undefined`; PHP's config carries empty defaults instead, so an unchanged
            // default is the equivalent of "not set".
            if ($value !== null && $value !== $defaults->{$key}) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
