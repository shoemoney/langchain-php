<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI\Converters;

/**
 * The xAI Responses API wire shapes, as static-analysis type aliases.
 *
 * Port of `chat_models/responses-types.ts`, which upstream is types only (1469
 * lines of interfaces, no runtime code). PHP has no structural types, so the
 * shapes live here as `@phpstan-type` aliases and the converters import them
 * with `@phpstan-import-type`. Nothing in this class runs.
 *
 * Every shape is a plain decoded-JSON array with the snake_case keys the API
 * uses. The aliases are deliberately open (`array<string, mixed>` tails): xAI
 * adds members to responses without notice, and a closed shape would reject
 * the response rather than ignore the member.
 *
 * @phpstan-type XAIResponsesInputTextItem array{type: 'input_text', text: string}
 * @phpstan-type XAIResponsesInputImageItem array{type: 'input_image', image_url: string, detail?: 'auto'|'low'|'high'}
 * @phpstan-type XAIResponsesInputFileItem array{type: 'input_file', file_id?: string, file_url?: string}
 * @phpstan-type XAIResponsesInputContentItem XAIResponsesInputTextItem|XAIResponsesInputImageItem|XAIResponsesInputFileItem
 * @phpstan-type XAIResponsesMessageRole 'user'|'assistant'|'system'|'developer'
 * @phpstan-type XAIResponsesMessage array{role: XAIResponsesMessageRole, content: string|list<XAIResponsesInputContentItem>}
 * @phpstan-type XAIResponsesInputItem array<string, mixed>
 * @phpstan-type XAIResponsesReasoningEffort 'low'|'medium'|'high'
 * @phpstan-type XAIResponsesReasoningSummary 'auto'|'concise'|'detailed'
 * @phpstan-type XAIResponsesReasoning array{effort?: XAIResponsesReasoningEffort, summary?: XAIResponsesReasoningSummary}
 * @phpstan-type XAIResponsesSearchParameters array{mode?: 'auto'|'on'|'off', max_search_results?: int, from_date?: string, to_date?: string, return_citations?: bool, sources?: list<array<string, mixed>>}
 * @phpstan-type XAIResponsesText array{format?: array<string, mixed>}
 * @phpstan-type XAIResponsesTool array{type: string, ...}
 * @phpstan-type XAIResponsesToolChoice 'none'|'auto'|'required'|array{type: 'function', name: string}
 * @phpstan-type XAIResponsesInclude 'reasoning.encrypted_content'
 * @phpstan-type XAIResponsesStatus 'completed'|'in_progress'|'incomplete'
 * @phpstan-type XAIResponsesOutputTextContent array{type: 'output_text', text: string, annotations?: list<array<string, mixed>>}
 * @phpstan-type XAIResponsesOutputRefusalContent array{type: 'refusal', refusal: string}
 * @phpstan-type XAIResponsesOutputContent XAIResponsesOutputTextContent|XAIResponsesOutputRefusalContent
 * @phpstan-type XAIResponsesOutputItem array{type: string, role?: string, content?: list<XAIResponsesOutputContent>, id?: string, name?: string, arguments?: string, ...}
 * @phpstan-type XAIResponsesUsage array{input_tokens: int, output_tokens: int, total_tokens: int, input_tokens_details?: array{cached_tokens?: int}, output_tokens_details?: array{reasoning_tokens?: int}}
 * @phpstan-type XAIResponsesIncompleteDetails array{reason: string}
 * @phpstan-type XAIResponse array{id: string, object: 'response', created_at: int, model: string, status: XAIResponsesStatus, output: list<XAIResponsesOutputItem>, usage?: XAIResponsesUsage|null, incomplete_details?: XAIResponsesIncompleteDetails|null, reasoning?: XAIResponsesReasoning|null, ...}
 * @phpstan-type XAIResponsesStreamEvent array{type: string, ...}
 * @phpstan-type XAIResponsesCreateParams array{input: string|list<XAIResponsesInputItem>, model: string, stream?: bool, temperature?: float, top_p?: float, max_output_tokens?: int, store?: bool, user?: string, previous_response_id?: string, include?: list<XAIResponsesInclude>, text?: XAIResponsesText, search_parameters?: XAIResponsesSearchParameters, reasoning?: XAIResponsesReasoning, tools?: list<XAIResponsesTool>, tool_choice?: XAIResponsesToolChoice, parallel_tool_calls?: bool}
 * @phpstan-type ChatXAIResponsesInvocationParams array<string, mixed>
 */
final class XAIResponsesTypes
{
    private function __construct()
    {
    }
}
