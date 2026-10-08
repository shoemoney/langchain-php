<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\OutputParsers\OpenAITools\JsonOutputToolsParser;
use LangChain\Utils\Js;

/**
 * The OUTPUT half of `converters/responses.ts`: Responses API payloads in,
 * LangChain messages out.
 *
 * Port of `convertResponsesUsageToUsageMetadata`,
 * `convertResponsesMessageToAIMessage`, `convertResponsesDeltaToChatGenerationChunk`
 * and `convertOpenAIAnnotationToLangChain`. The input half is
 * {@see ResponsesInput}; the inverse annotation conversion lives there too.
 *
 * Payloads are plain arrays (the decoded JSON), keyed exactly as the API spells them.
 * `usage_metadata` has no property of its own on a port message, so it rides in
 * `response_metadata['usage_metadata']` like every other provider in this SDK
 * (see `BaseChatModel::llmOutputFromUsage()`).
 */
final class ResponsesOutput
{
    public const FUNCTION_CALL_IDS_MAP_KEY = ResponsesInput::FUNCTION_CALL_IDS_MAP_KEY;
    public const CUSTOM_TOOL_CALL_IDS_MAP_KEY = ResponsesInput::CUSTOM_TOOL_CALL_IDS_MAP_KEY;

    /** Output items that finish as plain `tool_outputs` entries when streamed. */
    private const TOOL_OUTPUT_ITEM_TYPES = [
        'web_search_call',
        'file_search_call',
        'code_interpreter_call',
        'shell_call',
        'local_shell_call',
        'mcp_call',
        'mcp_list_tools',
        'mcp_approval_request',
        'custom_tool_call',
        'tool_search_call',
        'tool_search_output',
    ];

    /** Progress events surfaced through `generationInfo.tool_outputs`. */
    private const PROGRESS_EVENT_TYPES = [
        'response.web_search_call.in_progress',
        'response.web_search_call.searching',
        'response.web_search_call.completed',
        'response.file_search_call.in_progress',
        'response.file_search_call.searching',
        'response.file_search_call.completed',
        'response.image_generation_call.in_progress',
        'response.image_generation_call.generating',
        'response.image_generation_call.completed',
    ];

    private function __construct()
    {
    }

    // ------------------------------------------------------------------ usage

    /**
     * Responses usage as LangChain `UsageMetadata`.
     *
     * Detail members are present only when the API reported them; counts
     * default to 0.
     *
     * @param array<string, mixed>|null $usage
     *
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int, input_token_details: array<string, int>, output_token_details: array<string, int>}
     */
    public static function convertResponsesUsageToUsageMetadata(?array $usage): array
    {
        $inputDetails = [];
        $cached = $usage['input_tokens_details']['cached_tokens'] ?? null;
        if ($cached !== null) {
            $inputDetails['cache_read'] = (int) $cached;
        }
        $written = $usage['input_tokens_details']['cache_write_tokens'] ?? null;
        if ($written !== null) {
            $inputDetails['cache_creation'] = (int) $written;
        }

        $outputDetails = [];
        $reasoning = $usage['output_tokens_details']['reasoning_tokens'] ?? null;
        if ($reasoning !== null) {
            $outputDetails['reasoning'] = (int) $reasoning;
        }

        return [
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'input_token_details' => $inputDetails,
            'output_token_details' => $outputDetails,
        ];
    }

    // ------------------------------------------------------------ annotations

    /**
     * An OpenAI annotation as a LangChain citation; an unknown kind is kept
     * whole as a `non_standard` block.
     *
     * @param array<string, mixed> $annotation
     *
     * @return array<string, mixed>
     */
    public static function convertOpenAIAnnotationToLangChain(array $annotation): array
    {
        switch ($annotation['type'] ?? null) {
            case 'url_citation':
                return [
                    'type' => 'citation',
                    'source' => 'url_citation',
                    'url' => $annotation['url'] ?? null,
                    'title' => $annotation['title'] ?? null,
                    'startIndex' => $annotation['start_index'] ?? null,
                    'endIndex' => $annotation['end_index'] ?? null,
                ];
            case 'file_citation':
                return [
                    'type' => 'citation',
                    'source' => 'file_citation',
                    'title' => $annotation['filename'] ?? null,
                    'startIndex' => $annotation['index'] ?? null,
                    'file_id' => $annotation['file_id'] ?? null,
                ];
            case 'container_file_citation':
                return [
                    'type' => 'citation',
                    'source' => 'container_file_citation',
                    'title' => $annotation['filename'] ?? null,
                    'startIndex' => $annotation['start_index'] ?? null,
                    'endIndex' => $annotation['end_index'] ?? null,
                    'file_id' => $annotation['file_id'] ?? null,
                    'container_id' => $annotation['container_id'] ?? null,
                ];
            case 'file_path':
                return [
                    'type' => 'citation',
                    'source' => 'file_path',
                    'startIndex' => $annotation['index'] ?? null,
                    'file_id' => $annotation['file_id'] ?? null,
                ];
            default:
                return ['type' => 'non_standard', 'value' => $annotation];
        }
    }

    // ------------------------------------------------------- whole response

    /**
     * A whole Responses API response (`create` or `parse`) as an `AIMessage`.
     *
     * A tool call whose arguments do not parse lands in `invalid_tool_calls`
     * rather than raising; an `error` object on the response does raise.
     *
     * @param array<string, mixed> $response
     *
     * @throws OpenAIException when the response carries an `error`.
     */
    public static function convertResponsesMessageToAIMessage(array $response): AIMessage
    {
        if (is_array($response['error'] ?? null)) {
            $error = $response['error'];
            $code = is_string($error['code'] ?? null) ? $error['code'] : '';
            $message = (string) ($error['message'] ?? 'The Responses API returned an error.');

            throw new OpenAIException($message . ($code !== '' ? ' (code: ' . $code . ')' : ''), 0, Js::encode(['error' => $error]), $error);
        }

        $output = is_array($response['output'] ?? null) ? array_values($response['output']) : [];

        // The SDK injects `parsed_arguments` into function_call items under
        // `responses.parse()`, and the API rejects it when sent back as input.
        // These cleaned items are what the input converter replays verbatim.
        $cleanedOutput = array_map(static function (mixed $item): mixed {
            if (is_array($item) && ($item['type'] ?? null) === 'function_call') {
                unset($item['parsed_arguments']);
            }

            return $item;
        }, $output);

        $responseMetadata = [
            'model_provider' => 'openai',
            'model' => $response['model'] ?? null,
            'created_at' => $response['created_at'] ?? null,
            'id' => $response['id'] ?? null,
            'incomplete_details' => $response['incomplete_details'] ?? null,
            'metadata' => $response['metadata'] ?? null,
            'object' => $response['object'] ?? null,
            'output' => $cleanedOutput,
            'status' => $response['status'] ?? null,
            'user' => $response['user'] ?? null,
            'service_tier' => $response['service_tier'] ?? null,
            // for compatibility with chat completion calls.
            'model_name' => $response['model'] ?? null,
        ];

        $content = [];
        $toolCalls = [];
        $invalidToolCalls = [];
        $additionalKwargs = [];

        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }

            switch ($item['type'] ?? null) {
                case 'message':
                    foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                        if (!is_array($part)) {
                            $content[] = $part;
                            continue;
                        }

                        if (($part['type'] ?? null) === 'output_text') {
                            if (($part['parsed'] ?? null) !== null) {
                                $additionalKwargs['parsed'] = $part['parsed'];
                            }
                            $block = [
                                'type' => 'text',
                                'text' => $part['text'] ?? '',
                                'annotations' => array_map(
                                    static fn (mixed $a): array => self::convertOpenAIAnnotationToLangChain(is_array($a) ? $a : []),
                                    array_values(is_array($part['annotations'] ?? null) ? $part['annotations'] : []),
                                ),
                            ];
                            if (($item['phase'] ?? null) !== null) {
                                $block['phase'] = $item['phase'];
                            }
                            $content[] = $block;
                            continue;
                        }

                        if (($part['type'] ?? null) === 'refusal') {
                            $additionalKwargs['refusal'] = $part['refusal'] ?? null;
                            continue;
                        }

                        $content[] = $part;
                    }
                    break;

                case 'function_call':
                    $adapter = [
                        'function' => ['name' => $item['name'] ?? '', 'arguments' => $item['arguments'] ?? null],
                        'id' => $item['call_id'] ?? null,
                    ];

                    try {
                        $parsed = JsonOutputToolsParser::parseToolCall($adapter, true, false);
                        if ($parsed === null) {
                            $invalidToolCalls[] = self::makeInvalidToolCall($adapter, null);
                        } else {
                            $parsed['type'] = 'tool_call';
                            $toolCalls[] = $parsed;
                        }
                    } catch (\Throwable $e) {
                        $invalidToolCalls[] = self::makeInvalidToolCall($adapter, $e->getMessage());
                    }

                    $additionalKwargs[self::FUNCTION_CALL_IDS_MAP_KEY] ??= [];
                    if (!empty($item['id'])) {
                        $additionalKwargs[self::FUNCTION_CALL_IDS_MAP_KEY][$item['call_id'] ?? ''] = $item['id'];
                    }
                    break;

                case 'reasoning':
                    $additionalKwargs['reasoning'] = $item;
                    // Also elevate reasoning to content for UI rendering.
                    $reasoningText = self::summaryText($item);
                    if ($reasoningText !== '') {
                        $content[] = ['type' => 'reasoning', 'reasoning' => $reasoningText];
                    }
                    break;

                case 'custom_tool_call':
                    $parsed = ResponsesTools::parseCustomToolCall($item);
                    if ($parsed !== null) {
                        $toolCalls[] = $parsed;
                        $additionalKwargs[self::CUSTOM_TOOL_CALL_IDS_MAP_KEY] ??= [];
                        if (!empty($item['id']) && !empty($item['call_id'])) {
                            $additionalKwargs[self::CUSTOM_TOOL_CALL_IDS_MAP_KEY][$item['call_id']] = $item['id'];
                        }
                    } else {
                        $invalidToolCalls[] = self::makeInvalidToolCall($item, 'Malformed custom tool call');
                    }
                    break;

                case 'computer_call':
                    $parsed = ResponsesTools::parseComputerCall($item);
                    if ($parsed !== null) {
                        $toolCalls[] = $parsed;
                    } else {
                        $invalidToolCalls[] = self::makeInvalidToolCall($item, 'Malformed computer call');
                    }
                    break;

                case 'image_generation_call':
                    // Add the image as a proper content block if a result is available.
                    if (!empty($item['result'])) {
                        $content[] = self::imageBlock($item);
                    }
                    // Also kept in tool_outputs for backwards compatibility and
                    // multi-turn editing (which needs the id).
                    $additionalKwargs['tool_outputs'][] = $item;
                    break;

                default:
                    $additionalKwargs['tool_outputs'][] = $item;
                    break;
            }
        }

        $responseMetadata['usage_metadata'] = self::convertResponsesUsageToUsageMetadata(
            is_array($response['usage'] ?? null) ? $response['usage'] : null,
        );

        return new AIMessage([
            'id' => $response['id'] ?? null,
            'content' => $content,
            'tool_calls' => $toolCalls,
            'invalid_tool_calls' => $invalidToolCalls,
            'additional_kwargs' => $additionalKwargs,
            'response_metadata' => $responseMetadata,
        ]);
    }

    // ------------------------------------------------------------- streaming

    /**
     * One streamed Responses event as a chunk, or null for an event that
     * contributes nothing (partial images, unrecognised types).
     *
     * `output_index` rides on tool-call chunks and `content_index` /
     * `summary_index` on content blocks, so folding by `index` keeps parallel
     * items apart.
     *
     * @param array<string, mixed> $event
     */
    public static function convertResponsesDeltaToChatGenerationChunk(array $event): ?ChatGenerationChunk
    {
        $content = [];
        $generationInfo = [];
        $toolCallChunks = [];
        $responseMetadata = ['model_provider' => 'openai'];
        $additionalKwargs = [];
        $id = null;

        $type = $event['type'] ?? null;
        $item = is_array($event['item'] ?? null) ? $event['item'] : [];
        $itemType = $item['type'] ?? null;
        $outputIndex = $event['output_index'] ?? null;

        if ($type === 'response.output_text.delta') {
            $content[] = ['type' => 'text', 'text' => $event['delta'] ?? '', 'index' => $event['content_index'] ?? null];
        } elseif ($type === 'response.output_text.annotation.added') {
            $content[] = [
                'type' => 'text',
                'text' => '',
                'annotations' => [self::convertOpenAIAnnotationToLangChain(is_array($event['annotation'] ?? null) ? $event['annotation'] : [])],
                'index' => $event['content_index'] ?? null,
            ];
        } elseif ($type === 'response.output_item.added' && $itemType === 'message') {
            $phase = $item['phase'] ?? null;
            if ($phase) {
                $content[] = ['type' => 'text', 'text' => '', 'phase' => $phase, 'index' => 0];
            }
        } elseif ($type === 'response.output_item.added' && $itemType === 'function_call') {
            $toolCallChunks[] = [
                'type' => 'tool_call_chunk',
                'name' => $item['name'] ?? null,
                'args' => $item['arguments'] ?? null,
                'id' => $item['call_id'] ?? null,
                'index' => $outputIndex,
            ];
            $additionalKwargs[self::FUNCTION_CALL_IDS_MAP_KEY] = [($item['call_id'] ?? '') => $item['id'] ?? null];
        } elseif ($type === 'response.output_item.added' && $itemType === 'custom_tool_call') {
            $toolCallChunks[] = [
                'type' => 'tool_call_chunk',
                'isCustomTool' => true,
                'name' => $item['name'] ?? null,
                'args' => $item['input'] ?? null,
                'id' => $item['call_id'] ?? null,
                'index' => $outputIndex,
            ];
            $additionalKwargs[self::CUSTOM_TOOL_CALL_IDS_MAP_KEY] = [($item['call_id'] ?? '') => $item['id'] ?? null];
        } elseif ($type === 'response.output_item.done' && $itemType === 'reasoning' && !empty($item['encrypted_content'])) {
            // Encrypted reasoning is only complete on the done event. Emitted
            // on its own so it merges with the id and summary from output_item.added.
            $additionalKwargs['reasoning'] = ['encrypted_content' => $item['encrypted_content']];
        } elseif ($type === 'response.output_item.done' && $itemType === 'computer_call') {
            // A computer_call is a tool call so ToolNode can process it.
            $toolCallChunks[] = [
                'type' => 'tool_call_chunk',
                'name' => 'computer_use',
                'args' => Js::encode(['action' => $item['action'] ?? null]),
                'id' => $item['call_id'] ?? null,
                'index' => $outputIndex,
            ];
            // The raw item is kept for pending_safety_checks and friends.
            $additionalKwargs['tool_outputs'] = [$item];
        } elseif ($type === 'response.output_item.done' && $itemType === 'image_generation_call') {
            if (!empty($item['result'])) {
                $content[] = self::imageBlock($item);
            }
            $additionalKwargs['tool_outputs'] = [$item];
        } elseif ($type === 'response.output_item.done' && in_array($itemType, self::TOOL_OUTPUT_ITEM_TYPES, true)) {
            $additionalKwargs['tool_outputs'] = [$item];
        } elseif ($type === 'response.created') {
            $response = is_array($event['response'] ?? null) ? $event['response'] : [];
            $id = $response['id'] ?? null;
            $responseMetadata['id'] = $response['id'] ?? null;
            $responseMetadata['model_name'] = $response['model'] ?? null;
            $responseMetadata['model'] = $response['model'] ?? null;
        } elseif ($type === 'response.completed' || $type === 'response.incomplete') {
            $response = is_array($event['response'] ?? null) ? $event['response'] : [];
            $id = $response['id'] ?? null;
            $message = self::convertResponsesMessageToAIMessage($response);

            $responseMetadata['usage_metadata'] = self::convertResponsesUsageToUsageMetadata(
                is_array($response['usage'] ?? null) ? $response['usage'] : null,
            );

            $text = self::textOf($message->content);
            if (($response['text']['format']['type'] ?? null) === 'json_schema' && $text !== '') {
                // Some models intermittently emit trailing characters after a
                // valid JSON object. Leave `parsed` unset rather than killing
                // the stream; the caller can fall back to the text or retry.
                $decoded = json_decode($text, true);
                if (json_last_error() === \JSON_ERROR_NONE && $decoded !== null) {
                    $additionalKwargs['parsed'] ??= $decoded;
                }
            }

            foreach ($response as $key => $value) {
                if ($key === 'id') {
                    continue;
                }
                // The cleaned output, so SDK-only fields like
                // parsed_arguments are not persisted.
                $responseMetadata[$key] = $key === 'output' ? ($message->response_metadata['output'] ?? $value) : $value;
            }
        } elseif ($type === 'response.function_call_arguments.delta' || $type === 'response.custom_tool_call_input.delta') {
            $toolCallChunks[] = [
                'type' => 'tool_call_chunk',
                'args' => $event['delta'] ?? null,
                'index' => $outputIndex,
                ...($type === 'response.custom_tool_call_input.delta' ? ['isCustomTool' => true] : []),
            ];
        } elseif (is_string($type) && in_array($type, self::PROGRESS_EVENT_TYPES, true)) {
            preg_match('/^response\.(.*)\.([^.]+)$/', $type, $m);
            $generationInfo = ['tool_outputs' => [
                'id' => $event['item_id'] ?? null,
                'type' => $m[1] ?? '',
                'status' => $m[2] ?? '',
            ]];
        } elseif ($type === 'response.refusal.done') {
            $additionalKwargs['refusal'] = $event['refusal'] ?? null;
        } elseif ($type === 'response.output_item.added' && $itemType === 'reasoning') {
            $summary = null;
            if (is_array($item['summary'] ?? null)) {
                $summary = [];
                foreach (array_values($item['summary']) as $index => $s) {
                    $summary[] = [...(is_array($s) ? $s : []), 'index' => $index];
                }
            }

            // The id is captured on the first event only, or the concatenated
            // result would repeat it once per event.
            $additionalKwargs['reasoning'] = [
                'id' => $item['id'] ?? null,
                'type' => $itemType,
                ...($summary !== null ? ['summary' => $summary] : []),
            ];

            $reasoningText = self::summaryText($item);
            if ($reasoningText !== '') {
                $content[] = ['type' => 'reasoning', 'reasoning' => $reasoningText];
            }
        } elseif ($type === 'response.reasoning_summary_part.added') {
            $part = is_array($event['part'] ?? null) ? $event['part'] : [];
            $additionalKwargs['reasoning'] = [
                'type' => 'reasoning',
                'summary' => [[...$part, 'index' => $event['summary_index'] ?? null]],
            ];

            if (!empty($part['text'])) {
                $content[] = ['type' => 'reasoning', 'reasoning' => $part['text'], 'index' => $event['summary_index'] ?? null];
            }
        } elseif ($type === 'response.reasoning_summary_text.delta') {
            $additionalKwargs['reasoning'] = [
                'type' => 'reasoning',
                'summary' => [[
                    'text' => $event['delta'] ?? '',
                    'type' => 'summary_text',
                    'index' => $event['summary_index'] ?? null,
                ]],
            ];

            if (!empty($event['delta'])) {
                $content[] = ['type' => 'reasoning', 'reasoning' => $event['delta'], 'index' => $event['summary_index'] ?? null];
            }
        } else {
            // Includes response.image_generation_call.partial_image: retaining
            // partial images in a chunk would keep every one of them in history.
            return null;
        }

        $chunkFields = [
            'content' => $content,
            'additional_kwargs' => $additionalKwargs,
            'response_metadata' => $responseMetadata,
        ];
        if ($id !== null) {
            $chunkFields['id'] = $id;
        }
        if ($toolCallChunks !== []) {
            // An absent member is absent, not null: a null `name` would later
            // mask the real one when chunks fold.
            $chunkFields['tool_call_chunks'] = array_map(
                static fn (array $c): array => array_filter($c, static fn (mixed $v): bool => $v !== null),
                $toolCallChunks,
            );
        }

        return new ChatGenerationChunk(
            new AIMessageChunk($chunkFields),
            // Legacy reasons: `handleLLMNewToken` pulls this out.
            implode('', array_map(static fn (array $block): string => is_string($block['text'] ?? null) ? $block['text'] : '', $content)),
            $generationInfo,
        );
    }

    // --------------------------------------------------------------- helpers

    /**
     * Concatenated text of a message's content (`AIMessage.text`).
     */
    public static function textOf(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $block) {
            if (is_string($block)) {
                $text .= $block;
            } elseif (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $reasoningItem
     */
    private static function summaryText(array $reasoningItem): string
    {
        $text = '';
        foreach (is_array($reasoningItem['summary'] ?? null) ? $reasoningItem['summary'] : [] as $part) {
            if (is_array($part) && !empty($part['text'])) {
                $text .= (string) $part['text'];
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $item An `image_generation_call` with a result.
     *
     * @return array<string, mixed>
     */
    private static function imageBlock(array $item): array
    {
        return [
            'type' => 'image',
            'mimeType' => 'image/png',
            'data' => $item['result'],
            'id' => $item['id'] ?? null,
            'metadata' => ['status' => $item['status'] ?? null],
        ];
    }

    /**
     * `makeInvalidToolCall`: name, args and id read from a function-call shape.
     *
     * @param array<string, mixed> $rawToolCall
     *
     * @return array<string, mixed>
     */
    private static function makeInvalidToolCall(array $rawToolCall, ?string $error): array
    {
        return array_filter([
            'name' => $rawToolCall['function']['name'] ?? null,
            'args' => $rawToolCall['function']['arguments'] ?? null,
            'id' => $rawToolCall['id'] ?? null,
            'error' => $error,
            'type' => 'invalid_tool_call',
        ], static fn (mixed $v): bool => $v !== null);
    }
}
