<?php

declare(strict_types=1);

namespace LangChain\Messages;

use LangChain\Load\Serializable;

/**
 * Base class for every message in a conversation.
 *
 * Port of `BaseMessage` from `@langchain/core/messages/base`.
 *
 * Carries `content` (a string, or a list of content blocks), an optional
 * `name`, and the two metadata bags that the whole framework round-trips:
 * `additional_kwargs` (provider-specific extras) and `response_metadata`
 * (token counts, model name, finish reason, …).
 *
 * The constructor accepts either a bare content value — `new HumanMessage('hi')`
 * — or a field map, exactly as the TypeScript original does, because prompts and
 * deserializers both rely on the shorthand.
 */
abstract class BaseMessage extends Serializable
{
    public const ROLE_HUMAN = 'human';
    public const ROLE_AI = 'ai';
    public const ROLE_SYSTEM = 'system';
    public const ROLE_TOOL = 'tool';
    public const ROLE_FUNCTION = 'function';
    public const ROLE_CHAT = 'chat';
    public const ROLE_REMOVE = 'remove';

    /** @var string The message role discriminator, e.g. 'human'. */
    public string $type;

    public ?string $id = null;

    public ?string $name = null;

    /** @var string|list<mixed> */
    public string|array $content = '';

    /** @var array<string, mixed> Provider-specific extras. */
    public array $additional_kwargs = [];

    /** @var array<string, mixed> Response-side metadata: usage, model, stop reason. */
    public array $response_metadata = [];

    /**
     * @param string|array<string, mixed>|list<mixed> $fields Either the content
     *        itself (string or list of blocks) or a field map.
     */
    public function __construct(string|array $fields = [])
    {
        $isBareContent = is_string($fields) || (is_array($fields) && !self::looksLikeFieldMap($fields));

        $f = $isBareContent
            ? ['content' => $fields]
            : $fields;

        if (isset($f['additional_kwargs']) && is_array($f['additional_kwargs'])) {
            $this->additional_kwargs = $f['additional_kwargs'];
        }
        if (isset($f['response_metadata']) && is_array($f['response_metadata'])) {
            $this->response_metadata = $f['response_metadata'];
        }
        $this->name = isset($f['name']) && is_string($f['name']) ? $f['name'] : null;
        $this->id = isset($f['id']) && is_string($f['id']) ? $f['id'] : null;

        if (array_key_exists('content', $f) && $f['content'] !== null) {
            $this->content = is_array($f['content']) ? array_values($f['content']) : (string) $f['content'];
        } elseif (array_key_exists('contentBlocks', $f) && is_array($f['contentBlocks'])) {
            // The v0 shape: a bare block list plus an output_version marker.
            $this->content = array_values($f['contentBlocks']);
            $this->response_metadata = ['output_version' => 'v1'] + $this->response_metadata;
        } elseif (! $isBareContent && self::carriesIdentityButNoContent($f)) {
            // A FIELD MAP with no content anywhere is not a message.
            //
            // `looksLikeFieldMap()` is satisfied by the key `type` alone, so
            // `new HumanMessage(['type' => 'text', 'text' => 'hi'])` - a single
            // content block - was read as a field map with no `content`, fell to
            // the default, and produced an EMPTY message. Measured: content became
            // `[]`, the text silently gone, no error anywhere.
            //
            // Upstream REFUSES this shape rather than emptying it: messages/utils.ts
            // destructures a two-element TUPLE for an array, requires `role` for a
            // field map, and otherwise reaches `_constructMessageFromParams`,
            // which throws for a type it does not know. Producing an empty
            // message is strictly worse than refusing the input.
            throw new \InvalidArgumentException(
                'Message fields must include content or contentBlocks; got keys: '
                . implode(', ', array_keys($f))
                . '. A single content block is BARE content, not a field map - '
                . 'pass it as a list of one block instead.'
            );
        } else {
            $this->content = [];
        }

        $this->kwargs = [
            'content' => $this->content,
            'additional_kwargs' => $this->additional_kwargs,
            'response_metadata' => $this->response_metadata,
        ];
        if ($this->id !== null) {
            $this->kwargs['id'] = $this->id;
        }
        if ($this->name !== null) {
            $this->kwargs['name'] = $this->name;
        }
    }

    /**
     * A field map has at least one known key; a content block list does not.
     *
     * @param array<mixed> $value
     */
    private static function looksLikeFieldMap(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        foreach (['content', 'contentBlocks', 'id', 'name', 'additional_kwargs', 'response_metadata', 'type'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether these keys give a message an identity but never say what it says.
     *
     * Keys that can identify a message (`type`, `role`, `id`, `name`) with no
     * `content` and no `contentBlocks`. A map with none of those is a bare block
     * bag, which the bare-content path already handles, so it is not an error.
     */
    private static function carriesIdentityButNoContent(array $value): bool
    {
        foreach (['type', 'role', 'id', 'name'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'BaseMessage'];
    }

    public static function lcAliases(): array
    {
        return [
            'additional_kwargs' => 'additional_kwargs',
            'response_metadata' => 'response_metadata',
        ];
    }

    /**
     * The message role. Kept as a method for parity with the TS `getType()`.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * All text blocks concatenated — the common "what did the model say" read.
     */
    public function text(): string
    {
        if (is_string($this->content)) {
            return $this->content;
        }

        return ContentBlock::textFrom($this->content);
    }

    public function __toString(): string
    {
        return $this->text();
    }

    /**
     * The content normalised to a list of blocks.
     *
     * @return list<mixed>
     */
    public function contentBlocks(): array
    {
        if (is_string($this->content)) {
            return $this->content === '' ? [] : [ContentBlock::text($this->content)];
        }

        return $this->content;
    }

    /**
     * Fields shown by `var_dump`/`print_r` and by the formatted-string writer.
     *
     * @return array<string, mixed>
     */
    public function printableFields(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'name' => $this->name,
            'additional_kwargs' => $this->additional_kwargs,
            'response_metadata' => $this->response_metadata,
        ];
    }

    /**
     * The stored `{type, data}` form, which is what a checkpointer writes.
     *
     * @return array{type: string, data: array<string, mixed>}
     */
    public function toDict(): array
    {
        return [
            'type' => $this->getType(),
            'data' => $this->toSerializedConstructor()['kwargs'],
        ];
    }

    /**
     * Human-readable rendering. `$format` is 'pretty' or 'raw' in the TS
     * original; PHP's sprintf-style width spec is supported too.
     */
    public function toFormattedString(string $format = 'pretty'): string
    {
        return MessageFormat::convert($this, $format);
    }

    /**
     * Update the id both on the instance and in the serialized kwargs, so a
     * runtime-assigned id survives a round-trip through a checkpointer.
     */
    public function updateId(?string $value): void
    {
        $this->id = $value;
        $this->kwargs['id'] = $value;
    }
}
