<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Utils;

use LangChain\Utils\Js;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;

/**
 * Translation between LangChain messages and the Anthropic Messages format.
 *
 * Port of `utils/message_inputs.ts` from `@langchain/anthropic`.
 *
 * Anthropic's format is not OpenAI's with different key names, and three of the
 * differences change what a correct client has to do:
 *
 *  - **There is no `system` role.** A system message is a top-level request
 *    parameter, not a turn. The *leading run* of system messages is hoisted into
 *    it; a system message later in the conversation keeps its position and is
 *    sent as a `system` turn, which the provider applies from that point on.
 *
 *  - **Tool results are content, not messages.** A `ToolMessage` becomes a
 *    `tool_result` *block* inside a `user` turn.
 *
 *  - **Runs of tool results must merge.** A model can emit several tool calls in
 *    one turn, and the API rejects two consecutive `user` turns. So consecutive
 *    tool messages fold into one user message holding several `tool_result`
 *    blocks. This is not a nicety: an agent loop with two tools in flight
 *    produces exactly that sequence, and skipping the fold makes the second
 *    request fail.
 */
final class MessageInputs
{
    /**
     * Build the request body from a message list.
     *
     * @param list<BaseMessage> $messages
     *
     * @return array{system?: string|list<array<string, mixed>>, messages: list<array<string, mixed>>}
     */
    public static function convert(array $messages): array
    {
        $merged = self::foldToolMessages($messages);

        $leading = 0;
        while ($leading < count($merged) && $merged[$leading] instanceof \LangChain\Messages\SystemMessage) {
            $leading++;
        }

        $payload = [];

        if ($leading > 0) {
            $systemMessages = array_slice($merged, 0, $leading);

            // One system message stays a string; several become a flat list of
            // blocks. Both branches must preserve existing blocks — routing
            // them through `stringify()` JSON-encoded them into a text block,
            // so a prompt built with per-block `cache_control` was sent as
            // literal `[{"type":"text",...}]` text and prompt caching silently
            // did nothing.
            $payload['system'] = count($systemMessages) === 1
                ? self::formatContent($systemMessages[0]->content)
                : self::flattenToBlocks($systemMessages);
        }

        $payload['messages'] = array_map(
            static fn (BaseMessage $m): array => self::convertMessage($m),
            array_slice($merged, $leading),
        );

        return $payload;
    }

    /**
     * Fold consecutive tool messages into one user turn.
     *
     * @param list<BaseMessage> $messages
     *
     * @return list<BaseMessage>
     */
    public static function foldToolMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            if (!$message instanceof ToolMessage) {
                $out[] = $message;
                continue;
            }

            $block = [
                'type' => 'tool_result',
                'tool_use_id' => $message->toolCallId,
                'content' => $message->content,
            ];

            // Upstream's `ToolMessage` carries a first-class `status` field that
            // becomes `is_error` here. This port's `ToolMessage` predates it, so
            // the status is read from `additional_kwargs` — the same
            // provider-agnostic extras bag the rest of the port uses for
            // fields a message does not model.
            $status = $message->additional_kwargs['status'] ?? null;
            if (is_string($status) && $status !== '' && $status !== 'success') {
                $block['is_error'] = true;
            }

            $previous = $out === [] ? null : $out[count($out) - 1];

            if ($previous instanceof HumanMessage
                && is_array($previous->content)
                && ($previous->content[0]['type'] ?? null) === 'tool_result'
            ) {
                // Rebuild rather than append in place. Appending mutated the
                // caller's own message object: after one call its content had
                // silently grown a tool_result block it never held, which then
                // travelled into any later request built from the same history
                // — including a different provider, where an Anthropic-shaped
                // block is simply invalid.
                $out[count($out) - 1] = new HumanMessage([
                    'content' => [...$previous->content, $block],
                ]);
                continue;
            }

            $out[] = new HumanMessage(['content' => [$block]]);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function convertMessage(BaseMessage $message): array
    {
        $role = match ($message->type) {
            'human', 'tool' => 'user',
            'ai' => 'assistant',
            'system' => 'system',
            default => throw new \InvalidArgumentException(
                'Message type "' . $message->type . '" is not supported by the Anthropic API.'
            ),
        };

        // Opt-in: only a message that was built as standard (v1) content takes this path.
        if ($message instanceof AIMessage && ($message->response_metadata['output_version'] ?? null) === 'v1') {
            return ['role' => $role, 'content' => Standard::formatStandardContent($message)];
        }

        if ($message instanceof AIMessage && $message->toolCalls !== []) {
            return [
                'role' => 'assistant',
                'content' => array_merge(
                    self::textBlocks($message->content),
                    array_map(
                        static function (array $call): array {
                            // Anthropic types `input` as an OBJECT. `$call['args'] ?? []` left an empty
                            // PHP array, and PHP encodes an empty array as `[]` — a JSON array where an
                            // object is required. Measured before this cast:
                            //   {"type":"tool_use","id":"call_1","name":"lookup","input":[]}
                            //
                            // This is the same "empty object encodes as [] instead of {}" defect the
                            // project's hard rules name as having broken a real release, where it is
                            // recorded as guarded — the guard covers the OpenAI path, where
                            // `Completions::toolCallToWire()` already applies exactly this expression.
                            // The sibling provider was left unfixed, so the same cast is applied here
                            // rather than a different one.
                            $args = $call['args'] ?? [];

                            return [
                                'type' => 'tool_use',
                                'id' => $call['id'] ?? '',
                                'name' => $call['name'] ?? '',
                                'input' => is_array($args) && $args !== [] && Js::isList($args)
                                    ? $args
                                    : (object) $args,
                            ];
                        },
                        $message->toolCalls,
                    ),
                ),
            ];
        }

        return ['role' => $role, 'content' => self::formatContent($message->content)];
    }

    /**
     * A tool in the provider's format.
     *
     * The schema key is `input_schema`, not `parameters`. Passing `parameters`
     * is not a validation error — the model simply never sees the argument
     * shape, and calls the tool with nothing.
     *
     * @return array<string, mixed>
     */
    public static function convertTool(mixed $tool, ?bool $strict = null): array
    {
        // A tool that carries its own provider definition (bash, computer, text editor, memory) is
        // sent as that definition, not as a generated function schema.
        if ($tool instanceof \LangChain\Tools\StructuredTool
            && is_array($tool->extras['providerToolDefinition'] ?? null)
        ) {
            return $tool->extras['providerToolDefinition'];
        }

        // Server and built-in tools (`web_search_20250305`, `mcp_toolset`, ...) pass through untouched.
        // This sits BEFORE the schema branch and does not loosen it: it only fires when there is no
        // `input_schema` and the `type` is a dated built-in name, so the OpenAI-shape guard below is
        // unaffected.
        if (self::isServerTool($tool)) {
            return $tool;
        }

        if ($tool instanceof \LangChain\Tools\StructuredTool) {
            $name = $tool->name;
            $description = $tool->description;
            $schema = $tool->schema->toJsonSchema();
        } elseif ($tool instanceof \LangChain\Utils\Testing\StructuredToolSpec) {
            $name = $tool->name;
            $description = (string) ($tool->description ?? '');
            $schema = $tool->schema->toJsonSchema();
        } elseif (is_array($tool)) {
            // Already provider-shaped — but ONLY when it really is. This used to short-circuit on
            // `isset($tool['input_schema']) || isset($tool['name'])`, and the `||` meant the FLATTENED
            // OpenAI shape `['name' => ..., 'parameters' => ...]` returned unchanged. Everything below
            // this point was therefore skipped for that shape: the `function`-envelope unwrap, the
            // empty-name guard, and the lift of `parameters` into `input_schema`.
            //
            // The tool still got sent, still looked well-formed, and the model was simply never told
            // what arguments it takes — the same failure as the outer-`parameters` bug recorded in
            // HANDOFF, reached through a different door. A non-empty `name` alone is not evidence of
            // provider shape, so both parts are required.
            if (isset($tool['input_schema']) && isset($tool['name']) && $tool['name'] !== '') {
                return $tool;
            }

            // The OpenAI envelope. `BaseChatModel::withStructuredOutput()` builds
            // its tool in the OpenAI shape and hands it to `bindTools()`, so
            // every provider has to understand it — and Anthropic's
            // `input_schema` is nested under `function`, next to `parameters`.
            // Reading `parameters` from the *outer* level silently yields an
            // empty schema: the tool is still well-formed, still gets sent, and
            // the model is simply never told what arguments the tool takes.
            if (($tool['type'] ?? null) === 'function' && is_array($tool['function'] ?? null)) {
                $function = $tool['function'];
                $tool = $function + ['input_schema' => $function['parameters'] ?? $function['input_schema'] ?? null];
                unset($tool['parameters']);
            }

            $name = (string) ($tool['name'] ?? '');
            $description = (string) ($tool['description'] ?? '');
            $schema = $tool['input_schema'] ?? $tool['parameters'] ?? $tool['schema'] ?? ['type' => 'object', 'properties' => []];
        } else {
            throw new \InvalidArgumentException(
                'Cannot bind ' . get_debug_type($tool) . ' as a tool. Pass a StructuredTool,'
                . ' a StructuredToolSpec, or a provider-shaped array.'
            );
        }

        if ($name === '') {
            throw new \InvalidArgumentException('A bound tool must have a name.');
        }

        $converted = [
            'name' => $name,
            'description' => $description,
            'input_schema' => $schema,
        ];

        if ($strict !== null) {
            $converted['strict'] = $strict;
        }

        return $converted;
    }

    /**
     * Whether a tool is an Anthropic server/built-in tool: no `input_schema`, and a `type` that is
     * a dated tool name (`*_20YYMMDD`) or `mcp_toolset`.
     */
    public static function isServerTool(mixed $tool): bool
    {
        if (!is_array($tool) || isset($tool['input_schema'])) {
            return false;
        }

        $type = $tool['type'] ?? null;

        return is_string($type) && ($type === 'mcp_toolset' || preg_match('/_20\d{6}$/', $type) === 1);
    }

    /**
     * A tool choice in the provider's format.
     *
     * @param string|array<string, mixed> $toolChoice
     *
     * @return array<string, mixed>|null
     */
    public static function formatToolChoice(string|array $toolChoice): ?array
    {
        if (is_array($toolChoice)) {
            return $toolChoice;
        }

        return match ($toolChoice) {
            'auto' => ['type' => 'auto'],
            'any' => ['type' => 'any'],
            'none' => ['type' => 'none'],
            default => ['type' => 'tool', 'name' => $toolChoice],
        };
    }

    /**
     * Several messages' content as one flat list of blocks.
     *
     * Upstream's `flatMap`: string content becomes a single text block, and
     * block content is spread in as-is. Preserving the blocks is the whole
     * point — `cache_control` and `citations` live on them, and flattening to
     * text drops both.
     *
     * @param list<BaseMessage> $messages
     *
     * @return list<array<string, mixed>>
     */
    private static function flattenToBlocks(array $messages): array
    {
        $blocks = [];

        foreach ($messages as $message) {
            if (is_array($message->content)) {
                foreach ($message->content as $block) {
                    if (is_array($block)) {
                        $blocks[] = $block;
                    }
                }

                continue;
            }

            $blocks[] = ['type' => 'text', 'text' => self::stringify($message->content)];
        }

        return $blocks;
    }

    /**
     * The content of an assistant turn, as blocks, for merging ahead of the
     * tool calls.
     *
     * Block content is passed through AS-IS. Handing it to `textBlocks()`, which
     * only recognised a string, deleted every word the model wrote alongside a
     * tool call: the turn arrived as a bare `tool_use` block, so the provider saw
     * a function call with no surrounding reasoning and the conversation lost
     * the "let me look that up" that made the call legible.
     *
     * @return list<array<string, mixed>>
     */
    private static function textBlocks(mixed $content): array
    {
        if (is_array($content)) {
            return array_values(array_filter($content, 'is_array'));
        }

        return is_string($content) && $content !== ''
            ? [['type' => 'text', 'text' => $content]]
            : [];
    }

    /**
     * Content as the API wants it: a string, or a list of blocks.
     */
    private static function formatContent(mixed $content): string|array
    {
        return is_array($content) ? $content : self::stringify($content);
    }

    private static function stringify(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if ($content === null) {
            return '';
        }

        // `Completions::encode()` does not exist — that class uses `Js::encode`
        // internally and never exposed such a method — so this line named a
        // function that would fatal if it were ever reached.
        //
        // It is NOT reachable today, and that is the trap. `BaseMessage::$content`
        // coerces to string, so the two branches above cover every value a real
        // message can hold: forcing `42` through the property by reflection still
        // stored `'42'`. So the suite was green and the landmine sat under a
        // branch nothing could reach.
        //
        // Widening `$content`'s type, or a subclass that bypasses the
        // constructor, is exactly when this would fire — on the Anthropic path,
        // at send time, as a fatal. `Js::encode` is what the OpenAI client uses
        // for the same job, so this is also the consistent choice.
        return \LangChain\Utils\Js::encode($content);
    }
}
