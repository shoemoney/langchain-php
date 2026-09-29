<?php

declare(strict_types=1);

namespace LangChain\LanguageModels;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\MessageUtils;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\ChatPromptValue;
use LangChain\Schema\PromptValue;
use LangChain\Schema\StringPromptValue;
use LangChain\Tracers\CallbackManager;

/**
 * The shared base for every LangChain component that is not a plain runnable.
 *
 * Port of `BaseLangChain` from `@langchain/core/language_models/base`.
 *
 * Models, tools, and chains all carry the same four settings — `verbose`,
 * `callbacks`, `tags`, `metadata` — and they mean the same thing in each: who is
 * watching, what to label the run, and what extra facts to record. Collecting
 * them here is what makes `CallbackManager::configure()` able to treat a tool
 * and a chat model identically.
 *
 * It also carries the serialization surface (`lcId`, `kwargs`,
 * `toSerializedConstructor`) that the `Serializable` base provides elsewhere.
 * That duplication is deliberate: PHP has single inheritance, and
 * {@see Runnable} is the base that actually matters here — it is what makes a
 * model pipeable — so the serialization methods are re-declared rather than
 * inherited, keeping the runnable contract primary.
 *
 * @template TInput  What this component accepts.
 * @template TOutput What it returns.
 * @extends Runnable<TInput, TOutput>
 */
abstract class BaseLangChain extends Runnable implements \JsonSerializable
{
    /** The kwargs this component was constructed with. */
    protected array $kwargs = [];
    /** Whether to print response text. */
    public bool $verbose = false;

    /**
     * Handlers attached at construction.
     *
     * @var list<object>
     */
    public array $callbacks = [];

    /**
     * Trace labels, e.g. `['llm', 'openai']`.
     *
     * @var list<string>
     */
    public array $tags = [];

    /**
     * Arbitrary values surfaced in traces.
     *
     * @var array<string, mixed>
     */
    public array $metadata = [];

    /** @param array<string, mixed> $params */
    public function __construct(array $params = [])
    {
        $this->verbose = (bool) ($params['verbose'] ?? false);
        $this->callbacks = array_values((array) ($params['callbacks'] ?? []));
        $this->tags = array_values((array) ($params['tags'] ?? []));
        $this->metadata = (array) ($params['metadata'] ?? []);

        // Only the configuration a model can be reconstructed from is recorded.
        // Callbacks, tags, and verbose are runtime wiring: a model rebuilt from
        // a payload should not inherit the observer that happened to be attached
        // when the original was written to disk.
        $this->kwargs = array_filter([
            'verbose' => $params['verbose'] ?? null,
            'tags' => $this->tags === [] ? null : $this->tags,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * The constructor kwargs, used to build the serialized payload.
     *
     * @return array<string, mixed>
     */
    public function kwargs(): array
    {
        return $this->kwargs;
    }

    /**
     * The `SerializedConstructor` payload shape, byte-compatible with the JS one.
     *
     * @return array{id: list<string>, kwargs: array<string, mixed>, lc: int, type: string}
     */
    public function toSerializedConstructor(): array
    {
        return [
            'lc' => 1,
            'type' => 'constructor',
            'id' => static::lcId(),
            'kwargs' => $this->kwargs(),
        ];
    }

    /** @return array{lc: int, type: string, id: list<string>, kwargs: array<string, mixed>} */
    public function toJson(): array
    {
        return $this->toSerializedConstructor();
    }

    public function jsonSerialize(): mixed
    {
        return $this->toJson();
    }

    /**
     * Fields excluded from the serialized payload.
     *
     * Callbacks and verbosity are runtime wiring, not configuration: a
     * reconstructed model should not inherit the observer that happened to be
     * attached when the original was written to disk.
     *
     * @return array<string, string>
     */
    public static function lcAttributes(): array
    {
        return ['callbacks' => 'undefined', 'verbose' => 'undefined'];
    }

    /** @return list<string> */
    abstract public static function lcNamespace(): array;

    /**
     * The full serialization id: the namespace path plus this class's name.
     *
     * @return list<string>
     */
    public static function lcId(): array
    {
        return array_merge(static::lcNamespace(), [static::lcName()]);
    }

    /**
     * This class's bare name, as it appears in a trace.
     *
     * A method rather than a property so a subclass can override it without
     * having to also override the inherited `lcId()`, which would otherwise pin
     * the namespace path to the parent's.
     */
    public static function lcName(): string
    {
        $parts = explode('\\', static::class);

        return (string) end($parts);
    }

    public function getName(): string
    {
        return static::lcName();
    }

    /**
     * The serialized component, as passed to `handle*Start` on every handler.
     *
     * @return array<string, mixed>
     */
    public function toSerialized(): array
    {
        return $this->toSerializedConstructor();
    }

    /**
     * The callback manager for one invocation.
     *
     * Merges, in order: what the caller passed on the config (inherited), what
     * was set at construction (local), the call's tags, and the call's metadata.
     * Returns null when nothing wants to observe — which is the common case, and
     * the reason the return type is nullable.
     *
     * @param list<string>|null $tagsOverride
     */
    protected function callbackManagerFor(
        ?RunnableConfig $config,
        ?array $tagsOverride = null,
        ?array $metadataOverride = null,
    ): ?CallbackManager {
        return CallbackManager::configure(
            $config?->callbacks ?? null,
            $this->callbacks === [] ? null : $this->callbacks,
            $tagsOverride ?? $config?->tags ?? $this->tags,
            $tagsOverride !== null ? null : $this->tags,
            $metadataOverride ?? $config?->metadata ?? $this->metadata,
            $metadataOverride !== null ? null : $this->metadata,
            ['verbose' => $this->verbose],
        );
    }

    /**
     * Normalise any model input to a {@see PromptValue}.
     *
     * A model accepts three shapes — a bare string, a list of messages, or an
     * already-rendered prompt value — and every one of them has to be a prompt
     * value before the model can decide whether it wants `.toString()` or
     * `.toChatMessages()`. Doing it once here is what lets one chain feed both a
     * completion model and a chat model.
     */
    public static function convertInputToPromptValue(mixed $input): PromptValue
    {
        if ($input instanceof PromptValue) {
            return $input;
        }
        if (is_string($input)) {
            return new StringPromptValue($input);
        }
        if (is_array($input)) {
            return new ChatPromptValue(MessageUtils::convertToMessages($input));
        }

        throw new \InvalidArgumentException(
            'Expected a string, a list of messages, or a PromptValue; got ' . get_debug_type($input) . '.',
        );
    }

    /**
     * Coerce a list of message-likes to real messages.
     *
     * @param list<mixed> $messages
     * @return list<BaseMessage>
     */
    public static function coerceMessages(array $messages): array
    {
        return MessageUtils::convertToMessages($messages);
    }
}
