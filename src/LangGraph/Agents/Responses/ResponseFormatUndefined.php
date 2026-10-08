<?php

declare(strict_types=1);

namespace LangGraph\Agents\Responses;

/**
 * Special value to indicate that no response format is provided.
 *
 * Port of `ResponseFormatUndefined` from `langchain/src/agents/responses.ts` (`{ __responseFormatUndefined: true }`).
 * When it is used, the `structuredResponse` property should not be present in the result. The plain array form
 * `['__responseFormatUndefined' => true]` is accepted wherever this class is.
 */
final class ResponseFormatUndefined
{
    public bool $__responseFormatUndefined = true;
}
