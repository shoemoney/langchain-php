<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Converters;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Utils\Js;

/**
 * LangChain messages to the `input` array of an OpenAI Responses request.
 *
 * Port of the input side of `converters/responses.ts` from `@langchain/openai`:
 * `convertMessagesToResponsesInput`, `convertStandardContentMessageToResponsesInput`,
 * `convertReasoningSummaryToResponsesReasoningItem` and the annotation, image
 * and tool-output helpers they use. The output side (`convertResponsesMessageToAIMessage`
 * and the stream converter) is a separate unit.
 *
 * Items are plain arrays that must encode to the exact JSON the API reads. Two
 * PHP hazards follow from that and are handled in one place each:
 *
 *  - `undefined` members are absent from JSON, so a member that would be
 *    `undefined` upstream is simply not set here (never set to `null`, which
 *    the API reads as a value).
 *  - An empty map must encode as `{}` and an empty list as `[]`; PHP cannot
 *    tell them apart, so function-call arguments go through {@see self::encodeArguments()}.
 *
 * ## Known divergences from upstream
 *
 *  - `convertReasoningSummaryToResponsesReasoningItem` folds adjacent summary
 *    parts of equal `index`. Upstream seeds its reduce with a copy of the first
 *    part and then reduces over ALL parts including that first one, so the first
 *    part's text is appended to itself (`"a"`,`"b"` at one index becomes `"aab"`).
 *    This port skips the seed element and yields `"ab"`.
 *  - `toResponseInputItems` (from the OpenAI SDK, not in this repo) is
 *    reimplemented as "strip output-only fields": `created_by` on every item,
 *    `parsed_arguments` on function calls and `parsed` on output text parts.
 */
final class ResponsesInput
{
    public const FUNCTION_CALL_IDS_MAP_KEY = '__openai_function_call_ids__';
    public const CUSTOM_TOOL_CALL_IDS_MAP_KEY = '__openai_custom_tool_call_ids__';

    private const FALLTHROUGH_CALL_TYPES = [
        'computer_call',
        'mcp_call',
        'code_interpreter_call',
        'image_generation_call',
        'shell_call',
        'local_shell_call',
    ];

    private function __construct()
    {
    }

    /**
     * Convert a conversation to Responses API input items.
     *
     * @param list<BaseMessage> $messages
     * @param bool              $zdrEnabled Zero Data Retention: omit stored item ids, and
     *                                      replay reasoning only when it is encrypted.
     * @param string            $model      Decides whether `system` becomes `developer`.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException for a function message or an invalid computer output.
     */
    public static function convertMessagesToResponsesInput(array $messages, bool $zdrEnabled, string $model): array
    {
        $items = [];

        foreach ($messages as $message) {
            foreach (self::convertMessage($message, $zdrEnabled, $model) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function convertMessage(BaseMessage $message, bool $zdrEnabled, string $model): array
    {
        $responseMetadata = $message->response_metadata;

        if (($responseMetadata['output_version'] ?? null) === 'v1') {
            return self::convertStandardContentMessageToResponsesInput($message);
        }

        $role = Misc::messageToOpenAIRole($message);
        if ($role === 'system' && Misc::isReasoningModel($model)) {
            $role = 'developer';
        }

        if ($role === 'function') {
            throw new \InvalidArgumentException('Function messages are not supported in Responses API');
        }

        if ($role === 'tool') {
            return [self::convertToolMessage($message)];
        }

        if ($role === 'assistant') {
            return self::convertAssistantMessage($message, $zdrEnabled);
        }

        if ($role === 'user' || $role === 'system' || $role === 'developer') {
            return self::convertInstructionOrUserMessage($message, $role);
        }

        Misc::warn("Unsupported role found when converting to OpenAI Responses API: {$role}");

        return [];
    }

    // ---------------------------------------------------------------- tool role

    /**
     * @return array<string, mixed>
     */
    private static function convertToolMessage(BaseMessage $message): array
    {
        $callId = $message instanceof ToolMessage ? $message->toolCallId : '';
        $kwargs = $message->additional_kwargs;

        if (($kwargs['type'] ?? null) === 'computer_call_output') {
            return [
                'type' => 'computer_call_output',
                'output' => self::computerCallOutput($message->content),
                'call_id' => $callId,
            ];
        }

        if (Misc::truthy($kwargs['customTool'] ?? null)) {
            return [
                'type' => 'custom_tool_call_output',
                'call_id' => $callId,
                'output' => $message->content,
            ];
        }

        $content = $message->content;

        // Provider-native parts are forwarded as they are. `every` over an empty
        // list is true upstream, so an empty content list is "native" as well.
        $isProviderNative = is_array($content) && self::allMatch(
            $content,
            static fn (mixed $part): bool => is_array($part)
                && in_array($part['type'] ?? null, ['input_file', 'input_image', 'input_text'], true),
        );

        $attachmentOutput = $isProviderNative ? null : self::convertToolContentToResponsesOutput($message);

        if ($isProviderNative) {
            $output = $content;
        } elseif ($attachmentOutput !== null) {
            $output = $attachmentOutput;
        } else {
            $output = is_string($content) ? $content : Js::encode($content);
        }

        $item = ['type' => 'function_call_output', 'call_id' => $callId];
        if (is_string($message->id) && str_starts_with($message->id, 'fc_')) {
            $item['id'] = $message->id;
        }
        $item['output'] = $output;

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private static function computerCallOutput(mixed $content): array
    {
        if (is_string($content)) {
            return ['type' => 'input_image', 'image_url' => $content];
        }

        if (is_array($content)) {
            // computer-use-preview format first, then the legacy screenshot, then a LangChain image_url.
            foreach (['input_image', 'computer_screenshot'] as $type) {
                foreach ($content as $part) {
                    if (is_array($part) && ($part['type'] ?? null) === $type) {
                        return $part;
                    }
                }
            }

            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'image_url') {
                    $imageUrl = $part['image_url'] ?? null;

                    return [
                        'type' => 'input_image',
                        'image_url' => is_string($imageUrl) ? $imageUrl : ($imageUrl['url'] ?? null),
                    ];
                }
            }
        }

        throw new \InvalidArgumentException('Invalid computer call output');
    }

    /**
     * A tool result that contains an image becomes a native output list so the
     * model sees the picture; anything else is left to the caller to stringify.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function convertToolContentToResponsesOutput(BaseMessage $message): ?array
    {
        if (!is_array($message->content)) {
            return null;
        }

        $blocks = StandardContentBlocks::of($message);
        $hasImage = false;
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'image') {
                $hasImage = true;
                break;
            }
        }
        if (!$hasImage) {
            return null;
        }

        $out = [];
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $out[] = ['type' => 'input_text', 'text' => $block['text'] ?? ''];
                continue;
            }

            if (is_array($block) && ($block['type'] ?? null) === 'image') {
                $image = self::resolveImageItem($block);
                if ($image !== null) {
                    $out[] = $image;
                    continue;
                }
            }

            $out[] = ['type' => 'input_text', 'text' => Js::encode($block)];
        }

        return $out;
    }

    // ----------------------------------------------------------- assistant role

    /**
     * @return list<array<string, mixed>>
     */
    private static function convertAssistantMessage(BaseMessage $message, bool $zdrEnabled): array
    {
        $responseMetadata = $message->response_metadata;
        $kwargs = $message->additional_kwargs;

        // Reuse the original response items when we still have them. Under ZDR,
        // rebuilding reasoning and tool-call items independently loses every
        // reasoning payload but one and their interleaving.
        $output = $responseMetadata['output'] ?? null;
        if (is_array($output) && $output !== [] && self::allMatch(
            $output,
            static fn (mixed $item): bool => is_array($item) && is_string($item['type'] ?? null),
        )) {
            return $zdrEnabled ? self::toResponseInputItems(array_values($output)) : array_values($output);
        }

        $input = [];

        // Reasoning. Under ZDR only an encrypted payload can be replayed; with
        // ZDR off the stored item is referenced by id.
        $reasoning = $kwargs['reasoning'] ?? null;
        if (is_array($reasoning) && (!$zdrEnabled || Misc::truthy($reasoning['encrypted_content'] ?? null))) {
            $input[] = self::convertReasoningSummaryToResponsesReasoningItem($reasoning);
        }

        $content = $message->content;
        if (Misc::truthy($kwargs['refusal'] ?? null)) {
            if (is_string($content)) {
                $content = [['type' => 'output_text', 'text' => $content, 'annotations' => []]];
            }
            $content = [...$content, ['type' => 'refusal', 'refusal' => $kwargs['refusal']]];
        }

        if (is_string($content) || $content !== []) {
            $item = ['type' => 'message', 'role' => 'assistant'];
            if (is_string($message->id) && $message->id !== '' && !$zdrEnabled && str_starts_with($message->id, 'msg_')) {
                $item['id'] = $message->id;
            }
            $item['content'] = is_string($content) ? $content : self::assistantContentParts($content);

            if (is_array($content)) {
                foreach ($content as $part) {
                    if (is_array($part) && is_string($part['phase'] ?? null)) {
                        $item['phase'] = $part['phase'];
                        break;
                    }
                }
            }

            $input[] = $item;
        }

        $functionCallIds = is_array($kwargs[self::FUNCTION_CALL_IDS_MAP_KEY] ?? null)
            ? $kwargs[self::FUNCTION_CALL_IDS_MAP_KEY]
            : null;
        $customToolCallIds = is_array($kwargs[self::CUSTOM_TOOL_CALL_IDS_MAP_KEY] ?? null)
            ? $kwargs[self::CUSTOM_TOOL_CALL_IDS_MAP_KEY]
            : null;

        if ($message instanceof AIMessage && $message->toolCalls !== []) {
            foreach ($message->toolCalls as $toolCall) {
                $input[] = self::toolCallToItem($toolCall, $zdrEnabled, $functionCallIds, $customToolCallIds);
            }
        } elseif (is_array($kwargs['tool_calls'] ?? null)) {
            foreach ($kwargs['tool_calls'] as $toolCall) {
                $item = [
                    'type' => 'function_call',
                    'name' => $toolCall['function']['name'] ?? null,
                    'call_id' => $toolCall['id'] ?? null,
                    'arguments' => $toolCall['function']['arguments'] ?? null,
                ];
                if (!$zdrEnabled && isset($functionCallIds[$toolCall['id'] ?? ''])) {
                    $item['id'] = $functionCallIds[$toolCall['id']];
                }
                $input[] = $item;
            }
        }

        $toolOutputs = is_array($output) && $output !== [] ? $output : ($kwargs['tool_outputs'] ?? null);
        if (is_array($toolOutputs)) {
            foreach ($toolOutputs as $toolOutput) {
                if (is_array($toolOutput) && in_array($toolOutput['type'] ?? null, self::FALLTHROUGH_CALL_TYPES, true)) {
                    $input[] = $toolOutput;
                }
            }
        }

        return $input;
    }

    /**
     * Assistant content as `output_text` / `refusal` parts. Anything else
     * (a `tool_use` block from another provider, say) is dropped: it travels
     * as a `function_call` item, and echoing it as content is rejected.
     *
     * @param list<mixed> $content
     *
     * @return list<array<string, mixed>>
     */
    private static function assistantContentParts(array $content): array
    {
        $parts = [];

        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }

            if (($part['type'] ?? null) === 'text') {
                $annotations = is_array($part['annotations'] ?? null) ? $part['annotations'] : [];
                $parts[] = [
                    'type' => 'output_text',
                    'text' => $part['text'] ?? '',
                    'annotations' => array_map(
                        static fn (mixed $annotation): mixed => self::convertLangChainAnnotationToOpenAI($annotation),
                        array_values($annotations),
                    ),
                ];
                continue;
            }

            if (in_array($part['type'] ?? null, ['output_text', 'refusal'], true)) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * @param array<string, mixed>       $toolCall
     * @param array<string, string>|null $functionCallIds
     * @param array<string, string>|null $customToolCallIds
     *
     * @return array<string, mixed>
     */
    private static function toolCallToItem(array $toolCall, bool $zdrEnabled, ?array $functionCallIds, ?array $customToolCallIds): array
    {
        $id = $toolCall['id'] ?? null;
        $args = $toolCall['args'] ?? [];

        if (ResponsesTools::isCustomToolCall($toolCall, $customToolCallIds)) {
            return [
                'type' => 'custom_tool_call',
                'id' => is_string($toolCall['call_id'] ?? null)
                    ? $toolCall['call_id']
                    : ($customToolCallIds[$id ?? ''] ?? ''),
                'call_id' => $id ?? '',
                'input' => $args['input'] ?? null,
                'name' => $toolCall['name'] ?? null,
            ];
        }

        if (ResponsesTools::isComputerToolCall($toolCall)) {
            return [
                'type' => 'computer_call',
                'id' => $toolCall['call_id'] ?? null,
                'call_id' => $id ?? '',
                'action' => $args['action'] ?? null,
            ];
        }

        $item = [
            'type' => 'function_call',
            'name' => $toolCall['name'] ?? null,
            'arguments' => self::encodeArguments($args),
            'call_id' => $id,
        ];
        if (!$zdrEnabled && is_string($id) && isset($functionCallIds[$id])) {
            $item['id'] = $functionCallIds[$id];
        }

        return $item;
    }

    // ------------------------------------------------- user / system / developer

    /**
     * @return list<array<string, mixed>>
     */
    private static function convertInstructionOrUserMessage(BaseMessage $message, string $role): array
    {
        if (is_string($message->content)) {
            return [['type' => 'message', 'role' => $role, 'content' => $message->content]];
        }

        $items = [];
        $content = [];

        foreach ($message->content as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;

            if ($type === 'mcp_approval_response') {
                $items[] = [
                    'type' => 'mcp_approval_response',
                    'approval_request_id' => $block['approval_request_id'] ?? null,
                    'approve' => $block['approve'] ?? null,
                ];
            }

            if ($type === 'configuration_update') {
                // Hoisted to a top-level item that precedes the message.
                $items[] = ['type' => 'configuration_update', 'reasoning' => $block['reasoning'] ?? null];
            }

            if (StandardContentBlocks::isDataBlock($block)) {
                $content[] = self::dataBlockToPart($block);
                continue;
            }

            if ($type === 'text') {
                $content[] = Misc::applyPromptCacheBreakpoint($block, [
                    'type' => 'input_text',
                    'text' => $block['text'] ?? '',
                ]);
                continue;
            }

            if ($type === 'image_url') {
                $content[] = Misc::applyPromptCacheBreakpoint($block, self::imageUrlPart($block));
                continue;
            }

            if (in_array($type, ['input_text', 'input_image', 'input_file'], true)) {
                $content[] = $block;
            }
        }

        if ($content !== []) {
            $items[] = ['type' => 'message', 'role' => $role, 'content' => $content];
        }

        return $items;
    }

    /**
     * A v0 data block as a Responses input part.
     *
     * The Responses API takes file URLs natively but the Chat Completions
     * converter rejects them, so file blocks are mapped to `input_file` here
     * rather than routed through it.
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function dataBlockToPart(array $block): array
    {
        if (($block['type'] ?? null) === 'file') {
            $filename = Misc::getFilenameFromMetadata($block);
            $named = $filename !== null && $filename !== '' ? ['filename' => $filename] : [];

            if ($block['source_type'] === 'url') {
                return Misc::applyPromptCacheBreakpoint($block, ['type' => 'input_file', 'file_url' => $block['url']] + $named);
            }

            if ($block['source_type'] === 'id') {
                return Misc::applyPromptCacheBreakpoint($block, ['type' => 'input_file', 'file_id' => $block['id']] + $named);
            }

            if ($block['source_type'] === 'base64') {
                return Misc::applyPromptCacheBreakpoint($block, [
                    'type' => 'input_file',
                    'file_data' => 'data:' . ($block['mime_type'] ?? '') . ';base64,' . $block['data'],
                    'filename' => Misc::getRequiredFilenameFromMetadata($block),
                ]);
            }
        }

        return Misc::applyPromptCacheBreakpoint($block, CompletionsContentBlockConverter::convert($block));
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function imageUrlPart(array $block): array
    {
        $imageUrl = $block['image_url'] ?? null;
        $url = null;
        $detail = null;

        if (is_string($imageUrl)) {
            $url = $imageUrl;
            $detail = 'auto';
        } elseif (is_array($imageUrl)) {
            $url = $imageUrl['url'] ?? null;
            $detail = $imageUrl['detail'] ?? null;
        }

        $part = ['type' => 'input_image'];
        if ($url !== null) {
            $part['image_url'] = $url;
        }
        if ($detail !== null) {
            $part['detail'] = $detail;
        }

        return $part;
    }

    // ---------------------------------------------------------- standard content

    /**
     * Convert one message of the standard (v1) content-block format.
     *
     * @return list<array<string, mixed>>
     */
    public static function convertStandardContentMessageToResponsesInput(BaseMessage $message): array
    {
        $isResponsesMessage = $message instanceof AIMessage
            && ($message->response_metadata['model_provider'] ?? null) === 'openai';

        try {
            $role = Misc::messageToOpenAIRole($message);
            $messageRole = in_array($role, ['system', 'developer', 'assistant', 'user'], true) ? $role : 'assistant';
        } catch (\Throwable) {
            $messageRole = 'assistant';
        }

        $out = [];
        /** @var array<string, mixed>|null $current */
        $current = null;
        $functionCallIdsWithBlocks = [];
        $serverFunctionCallIdsWithBlocks = [];
        /** @var array<string, array{name: ?string, args: list<string>}> $pendingFunctionChunks */
        $pendingFunctionChunks = [];
        /** @var array<string, array{name: ?string, args: list<string>}> $pendingServerFunctionChunks */
        $pendingServerFunctionChunks = [];

        // Assistant text is `output_text`; the API rejects `input_text` there.
        $makeTextPart = static fn (string $text): array => $messageRole === 'assistant'
            ? ['type' => 'output_text', 'text' => $text, 'annotations' => []]
            : ['type' => 'input_text', 'text' => $text];

        $withBreakpoint = static fn (array $block, array $part): array => $messageRole === 'assistant'
            ? $part
            : Misc::applyPromptCacheBreakpoint($block, $part);

        $flush = static function () use (&$current, &$out): void {
            if ($current === null) {
                return;
            }
            if ($current['content'] !== []) {
                $out[] = $current;
            }
            $current = null;
        };

        $push = static function (array $parts, mixed $phase = null) use (&$current, $messageRole): void {
            if ($current === null) {
                $current = ['type' => 'message', 'role' => $messageRole, 'content' => []];
                if (Misc::truthy($phase)) {
                    $current['phase'] = $phase;
                }
            }
            foreach ($parts as $part) {
                $current['content'][] = $part;
            }
        };

        foreach (StandardContentBlocks::of($message) as $block) {
            if (!is_array($block)) {
                continue;
            }

            switch ($block['type'] ?? null) {
                case 'text':
                    $phase = is_array($block['extras'] ?? null) ? ($block['extras']['phase'] ?? null) : null;
                    $push([$withBreakpoint($block, $makeTextPart((string) ($block['text'] ?? '')))], $phase);
                    break;

                case 'reasoning':
                    $flush();
                    $out[] = self::convertReasoningBlock($block);
                    break;

                case 'tool_call':
                    $flush();
                    $id = (string) ($block['id'] ?? '');
                    if ($id !== '') {
                        $functionCallIdsWithBlocks[$id] = true;
                        unset($pendingFunctionChunks[$id]);
                    }
                    $out[] = self::convertFunctionCall($block);
                    break;

                case 'tool_call_chunk':
                    self::accumulateChunk($pendingFunctionChunks, $block);
                    break;

                case 'server_tool_call':
                    $flush();
                    $id = (string) ($block['id'] ?? '');
                    if ($id !== '') {
                        $serverFunctionCallIdsWithBlocks[$id] = true;
                        unset($pendingServerFunctionChunks[$id]);
                    }
                    $out[] = self::convertFunctionCall($block);
                    break;

                case 'server_tool_call_chunk':
                    self::accumulateChunk($pendingServerFunctionChunks, $block);
                    break;

                case 'server_tool_call_result':
                    $flush();
                    $out[] = self::convertFunctionCallOutput($block);
                    break;

                case 'file':
                    $file = self::resolveFileItem($block);
                    if ($file !== null) {
                        $push([$withBreakpoint($block, $file)]);
                    }
                    break;

                case 'image':
                    $image = self::resolveImageItem($block);
                    if ($image !== null) {
                        $push([$withBreakpoint($block, $image)]);
                    }
                    break;

                case 'video':
                    $video = self::resolveFileItem($block);
                    if ($video !== null) {
                        $push([$withBreakpoint($block, $video)]);
                    }
                    break;

                case 'text-plain':
                    if (Misc::truthy($block['text'] ?? null)) {
                        $push([$withBreakpoint($block, $makeTextPart((string) $block['text']))]);
                    }
                    break;

                case 'non_standard':
                    if ($isResponsesMessage) {
                        $flush();
                        $out[] = $block['value'];
                    }
                    break;

                    // invalid_tool_call and audio are deliberately dropped.
            }
        }
        $flush();

        foreach ([[$pendingFunctionChunks, $functionCallIdsWithBlocks], [$pendingServerFunctionChunks, $serverFunctionCallIdsWithBlocks]] as [$pending, $seen]) {
            foreach ($pending as $id => $chunk) {
                $id = (string) $id;
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $args = implode('', $chunk['args']);
                if (!Misc::truthy($chunk['name']) && $args === '') {
                    continue;
                }
                $out[] = [
                    'type' => 'function_call',
                    'call_id' => $id,
                    'name' => $chunk['name'] ?? '',
                    'arguments' => $args,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, array{name: ?string, args: list<string>}> $pending
     * @param array<string, mixed>                                   $block
     */
    private static function accumulateChunk(array &$pending, array $block): void
    {
        if (!Misc::truthy($block['id'] ?? null)) {
            return;
        }

        $id = (string) $block['id'];
        $existing = $pending[$id] ?? ['name' => $block['name'] ?? null, 'args' => []];
        if (Misc::truthy($block['name'] ?? null)) {
            $existing['name'] = $block['name'];
        }
        if (Misc::truthy($block['args'] ?? null)) {
            $existing['args'][] = (string) $block['args'];
        }
        $pending[$id] = $existing;
    }

    /**
     * A reasoning block as a reasoning input item.
     *
     * `id` is set only when there is one: a block reassembled from streaming
     * never carries it, and `id: ""` is a 400. `content` is never forwarded,
     * because the API rejects a populated `content` on a reasoning input item;
     * the text is already in `summary`. `encrypted_content` is forwarded, as it
     * is what makes the item replayable under ZDR.
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function convertReasoningBlock(array $block): array
    {
        $entries = [];

        if (is_array($block['summary'] ?? null)) {
            foreach ($block['summary'] as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $entries[] = $part['text'];
                }
            }
        }

        if ($entries === [] && Misc::truthy($block['reasoning'] ?? null)) {
            $entries = [$block['reasoning']];
        }

        $summary = $entries === []
            ? [['type' => 'summary_text', 'text' => '']]
            : array_map(static fn (mixed $text): array => ['type' => 'summary_text', 'text' => $text], $entries);

        $item = ['type' => 'reasoning'];
        if (Misc::truthy($block['id'] ?? null)) {
            $item['id'] = $block['id'];
        }
        $item['summary'] = $summary;
        if (is_string($block['encrypted_content'] ?? null)) {
            $item['encrypted_content'] = $block['encrypted_content'];
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function convertFunctionCall(array $block): array
    {
        return [
            'type' => 'function_call',
            'name' => $block['name'] ?? '',
            'call_id' => $block['id'] ?? '',
            'arguments' => self::toJsonString($block['args'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function convertFunctionCallOutput(array $block): array
    {
        $item = [
            'type' => 'function_call_output',
            'call_id' => $block['toolCallId'] ?? '',
            'output' => self::toJsonString($block['output'] ?? null),
        ];

        $status = match ($block['status'] ?? null) {
            'success' => 'completed',
            'error' => 'incomplete',
            default => null,
        };
        if ($status !== null) {
            $item['status'] = $status;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>|null
     */
    private static function resolveFileItem(array $block): ?array
    {
        if (Misc::truthy($block['fileId'] ?? null)) {
            $filename = Misc::getFilenameFromMetadata($block);

            return ['type' => 'input_file', 'file_id' => $block['fileId']]
                + (Misc::truthy($filename) ? ['filename' => $filename] : []);
        }

        if (Misc::truthy($block['url'] ?? null)) {
            $filename = Misc::getFilenameFromMetadata($block);

            return ['type' => 'input_file', 'file_url' => $block['url']]
                + (Misc::truthy($filename) ? ['filename' => $filename] : []);
        }

        if (Misc::truthy($block['data'] ?? null)) {
            return [
                'type' => 'input_file',
                'file_data' => 'data:' . ($block['mimeType'] ?? 'application/octet-stream') . ';base64,' . $block['data'],
                'filename' => Misc::getRequiredFilenameFromMetadata($block),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>|null
     */
    private static function resolveImageItem(array $block): ?array
    {
        $raw = is_array($block['metadata'] ?? null) ? ($block['metadata']['detail'] ?? null) : null;
        $detail = in_array($raw, ['low', 'high', 'auto'], true) ? $raw : 'auto';

        if (Misc::truthy($block['fileId'] ?? null)) {
            return ['type' => 'input_image', 'detail' => $detail, 'file_id' => $block['fileId']];
        }

        if (Misc::truthy($block['url'] ?? null)) {
            return ['type' => 'input_image', 'detail' => $detail, 'image_url' => $block['url']];
        }

        if (Misc::truthy($block['data'] ?? null)) {
            return [
                'type' => 'input_image',
                'detail' => $detail,
                'image_url' => 'data:' . ($block['mimeType'] ?? 'image/png') . ';base64,' . $block['data'],
            ];
        }

        return null;
    }

    // ------------------------------------------------------------------ reasoning

    /**
     * A stored reasoning summary as a reasoning input item.
     *
     * Streamed summaries arrive as several parts that share an `index`; those
     * are folded into one part and the `index` is removed, since the API does
     * not accept it.
     *
     * @param array<string, mixed> $reasoning
     *
     * @return array<string, mixed>
     */
    public static function convertReasoningSummaryToResponsesReasoningItem(array $reasoning): array
    {
        $parts = array_values(is_array($reasoning['summary'] ?? null) ? $reasoning['summary'] : []);

        if (count($parts) > 1) {
            $folded = [$parts[0]];
            foreach (array_slice($parts, 1) as $part) {
                $lastKey = array_key_last($folded);
                if (($folded[$lastKey]['index'] ?? null) === ($part['index'] ?? null)) {
                    $folded[$lastKey]['text'] = ($folded[$lastKey]['text'] ?? '') . ($part['text'] ?? '');
                } else {
                    $folded[] = $part;
                }
            }
            $parts = $folded;
        }

        $reasoning['summary'] = array_map(
            static fn (array $part): array => array_diff_key($part, ['index' => true]),
            $parts,
        );

        return $reasoning;
    }

    /**
     * Strip the output-only fields from stored response items so they can be
     * sent back as input.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function toResponseInputItems(array $items): array
    {
        return array_map(static function (array $item): array {
            unset($item['created_by']);

            if (($item['type'] ?? null) === 'function_call') {
                unset($item['parsed_arguments']);
            }

            if (is_array($item['content'] ?? null)) {
                $item['content'] = array_map(static function (mixed $part): mixed {
                    if (is_array($part)) {
                        unset($part['parsed']);
                    }

                    return $part;
                }, $item['content']);
            }

            return $item;
        }, $items);
    }

    // ---------------------------------------------------------------- annotations

    /**
     * A LangChain citation (or an OpenAI annotation already) as an OpenAI annotation.
     *
     * @param mixed $annotation
     *
     * @return mixed
     */
    public static function convertLangChainAnnotationToOpenAI(mixed $annotation): mixed
    {
        if (!is_array($annotation)) {
            return $annotation;
        }

        $type = $annotation['type'] ?? null;

        if (in_array($type, ['url_citation', 'file_citation', 'container_file_citation', 'file_path'], true)) {
            return $annotation;
        }

        if ($type === 'citation') {
            $startIndex = $annotation['startIndex'] ?? 0;
            $endIndex = $annotation['endIndex'] ?? 0;

            switch ($annotation['source'] ?? null) {
                case 'url_citation':
                    return [
                        'type' => 'url_citation',
                        'url' => $annotation['url'] ?? '',
                        'title' => $annotation['title'] ?? '',
                        'start_index' => $startIndex,
                        'end_index' => $endIndex,
                    ];
                case 'file_citation':
                    return [
                        'type' => 'file_citation',
                        'file_id' => $annotation['file_id'] ?? '',
                        'filename' => $annotation['title'] ?? '',
                        'index' => $startIndex,
                    ];
                case 'container_file_citation':
                    return [
                        'type' => 'container_file_citation',
                        'file_id' => $annotation['file_id'] ?? '',
                        'filename' => $annotation['title'] ?? '',
                        'container_id' => $annotation['container_id'] ?? '',
                        'start_index' => $startIndex,
                        'end_index' => $endIndex,
                    ];
                case 'file_path':
                    return [
                        'type' => 'file_path',
                        'file_id' => $annotation['file_id'] ?? '',
                        'index' => $startIndex,
                    ];
            }
        }

        if ($type === 'non_standard') {
            return $annotation['value'] ?? null;
        }

        return $annotation;
    }

    // -------------------------------------------------------------------- helpers

    /**
     * `JSON.stringify(value ?? {})` with a string passed through.
     *
     * An empty array is read as an empty argument MAP, because that is what an
     * empty `args` is; a PHP list cannot be told from it.
     */
    private static function toJsonString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value === null) {
            return '{}';
        }

        return self::encodeArguments($value);
    }

    /**
     * Tool-call arguments as the JSON string the API expects.
     *
     * `{}` for an empty map, not `[]`: PHP has one array type, and `[]` would
     * present a no-argument tool as one taking a positional list.
     */
    private static function encodeArguments(mixed $args): string
    {
        return Js::encode(is_array($args) && $args !== [] && Js::isList($args) ? $args : (object) $args);
    }

    /**
     * @param array<mixed>        $values
     * @param callable(mixed): bool $predicate
     */
    private static function allMatch(array $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if (!$predicate($value)) {
                return false;
            }
        }

        return true;
    }
}
