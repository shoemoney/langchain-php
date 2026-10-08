<?php

declare(strict_types=1);

namespace LangChain\Caches;

use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\Generation;
use LangChain\Messages\MessageUtils;

/**
 * Base class for all caches.
 *
 * Port of `BaseCache` from `@langchain/core/caches`. The cache key is a hash of
 * the prompt AND the serialized model parameters (`llmKey`): without the second
 * half two differently configured models would share hits.
 *
 * @template T
 */
abstract class BaseCache
{
    /** @var \Closure(string...): string */
    protected \Closure $keyEncoder;

    public function __construct()
    {
        $this->keyEncoder = static fn (string ...$strings): string => self::getCacheKey(...$strings);
    }

    /**
     * The default key encoder: sha256 over the parts joined with `_`.
     * Upstream's `defaultHashKeyEncoder`.
     */
    public static function getCacheKey(string ...$strings): string
    {
        return hash('sha256', implode('_', $strings));
    }

    /**
     * Replace the key encoder. It receives the prompt and the LLM key.
     *
     * @param callable(string...): string $keyEncoderFn
     */
    public function makeDefaultKeyEncoder(callable $keyEncoderFn): void
    {
        $this->keyEncoder = \Closure::fromCallable($keyEncoderFn);
    }

    /**
     * @return T|null null when nothing is cached
     */
    abstract public function lookup(string $prompt, string $llmKey): mixed;

    /** @param T $value */
    abstract public function update(string $prompt, string $llmKey, mixed $value): void;

    /**
     * Upstream's `serializeGeneration`: `{text}` plus `message` (a stored
     * `{type, data}` dict) for a chat generation.
     *
     * @return array{text: string, message?: array<string, mixed>}
     */
    public static function serializeGeneration(Generation $generation): array
    {
        $serialized = ['text' => $generation->text];
        if ($generation instanceof ChatGeneration) {
            $serialized['message'] = $generation->message->toDict();
        }

        return $serialized;
    }

    /**
     * Upstream's `deserializeStoredGeneration`.
     *
     * @param array{text: string, message?: array<string, mixed>} $storedGeneration
     */
    public static function deserializeStoredGeneration(array $storedGeneration): Generation
    {
        if (isset($storedGeneration['message'])) {
            return new ChatGeneration(
                MessageUtils::mapStoredMessageToChatMessage($storedGeneration['message']),
                $storedGeneration['text'],
            );
        }

        return new Generation($storedGeneration['text']);
    }
}
