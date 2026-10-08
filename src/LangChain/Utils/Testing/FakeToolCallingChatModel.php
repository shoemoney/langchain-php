<?php

declare(strict_types=1);

namespace LangChain\Utils\Testing;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\BaseMessage;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tools\StructuredTool;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * A chat model that replays scripted responses and supports `bindTools()` / `withStructuredOutput()`.
 *
 * Port of `FakeToolCallingChatModel` from `langgraph-core/src/tests/utils.models.ts`.
 *
 * `responses` are returned in order and cycle; with none scripted the model echoes its input messages the
 * same way (`messages[idx % count]`). `sleep` (milliseconds, default 50 as upstream) is spent before each
 * reply. `toolStyle` picks the shape `bindTools()` records: OpenAI nests under `function`, Anthropic and
 * Google use a bare `name`, Bedrock wraps in `toolSpec`, and Google additionally wraps the lot in
 * `functionDeclarations`. Only the NAME is recorded, as upstream: "a simplified tool spec for testing
 * purposes only". A tool with no name (a provider-side "server" tool such as
 * `['type' => 'web_search_preview']`) is passed through untouched, after the named ones.
 *
 * Differences from upstream, both forced by PHP rather than chosen:
 *
 *  - `bindTools()` returns a clone of this model with the specs under `kwargs()['tools']`, where upstream
 *    returns `withConfig({tools})` (a `RunnableBinding`). `BaseChatModel::bindTools()` is declared to
 *    return `static`, and every provider client in this port records bound tools the same way. The clone
 *    SHARES `idx` and `structuredOutputMessages` with its origin by reference, so a bound model and the
 *    model it came from advance one script, as a binding does upstream.
 *  - The class is not `final`, so a test can subclass it to spy on `generate()` / `invoke()` /
 *    `withStructuredOutput()` the way upstream uses `vi.spyOn`.
 */
class FakeToolCallingChatModel extends BaseChatModel
{
    /** Milliseconds to wait before replying. */
    public ?int $sleep = 50;

    /** @var list<BaseMessage>|null */
    public ?array $responses = null;

    public ?string $thrownErrorString = null;

    public int $idx = 0;

    /** @var 'openai'|'anthropic'|'bedrock'|'google' */
    public string $toolStyle = 'openai';

    /** @var array<string, mixed>|null */
    public ?array $structuredResponse = null;

    /**
     * The messages each `withStructuredOutput()` runnable was invoked with.
     *
     * @var list<list<BaseMessage>>
     */
    public array $structuredOutputMessages = [];

    /**
     * @param array{sleep?: int|null, responses?: list<BaseMessage>|null, thrownErrorString?: string|null, toolStyle?: string, structuredResponse?: array<string, mixed>|null}&array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        $this->sleep = array_key_exists('sleep', $fields) ? ($fields['sleep'] === null ? null : (int) $fields['sleep']) : $this->sleep;
        $this->responses = isset($fields['responses']) ? array_values($fields['responses']) : null;
        $this->thrownErrorString = isset($fields['thrownErrorString']) ? (string) $fields['thrownErrorString'] : null;
        $this->idx = 0;
        $this->toolStyle = (string) ($fields['toolStyle'] ?? $this->toolStyle);
        $this->structuredResponse = $fields['structuredResponse'] ?? null;
        $this->structuredOutputMessages = [];
    }

    public function llmType(): string
    {
        return 'fake';
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        if ($this->thrownErrorString !== null && $this->thrownErrorString !== '') {
            throw new \Exception($this->thrownErrorString);
        }
        if ($this->sleep !== null && $this->sleep > 0) {
            usleep($this->sleep * 1000);
        }

        $responses = $this->responses !== null && $this->responses !== [] ? $this->responses : $messages;
        $msg = $responses[$this->idx % count($responses)];
        $this->idx++;

        if (is_string($msg->content)) {
            $runManager?->handleLLMNewToken($msg->content);
        }

        return new ChatResult([new ChatGeneration($msg, '')]);
    }

    /**
     * @param list<mixed>          $tools
     * @param array<string, mixed> $kwargs
     */
    public function bindTools(array $tools, array $kwargs = []): static
    {
        $toolDicts = [];
        $serverTools = [];
        foreach ($tools as $tool) {
            $name = self::nameOf($tool);
            if ($name === null) {
                $serverTools[] = $tool;
                continue;
            }

            if ($this->toolStyle === 'openai') {
                $toolDicts[] = ['type' => 'function', 'function' => ['name' => $name]];
            } elseif ($this->toolStyle === 'anthropic' || $this->toolStyle === 'google') {
                $toolDicts[] = ['name' => $name];
            } elseif ($this->toolStyle === 'bedrock') {
                $toolDicts[] = ['toolSpec' => ['name' => $name]];
            }
        }

        $toolsToBind = $toolDicts;
        if ($this->toolStyle === 'google') {
            $toolsToBind = [['functionDeclarations' => $toolDicts]];
        }

        $next = clone $this;
        $next->idx = &$this->idx;
        $next->structuredOutputMessages = &$this->structuredOutputMessages;
        $next->kwargs['tools'] = [...$toolsToBind, ...$serverTools];
        foreach ($kwargs as $key => $value) {
            $next->kwargs[$key] = $value;
        }

        return $next;
    }

    /**
     * A runnable that records the messages it is given and answers with `structuredResponse`.
     *
     * The schema is ignored, as upstream ignores it.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $config
     */
    public function withStructuredOutput(array $schema, array $config = []): Runnable
    {
        if ($this->structuredResponse === null) {
            throw new \Exception('No structured response provided');
        }

        return RunnableLambda::from(function (mixed $messages): mixed {
            if ($this->sleep !== null && $this->sleep > 0) {
                usleep($this->sleep * 1000);
            }

            $this->structuredOutputMessages[] = array_values((array) $messages);

            return $this->structuredResponse;
        });
    }

    /** The name a bindable tool goes by, or null for a provider-side tool that has none. */
    private static function nameOf(mixed $tool): ?string
    {
        if ($tool instanceof StructuredTool) {
            return $tool->name;
        }
        if ($tool instanceof RunnableInterface) {
            return $tool->getName();
        }
        if (is_array($tool) && isset($tool['name']) && is_string($tool['name'])) {
            return $tool['name'];
        }

        return null;
    }
}
