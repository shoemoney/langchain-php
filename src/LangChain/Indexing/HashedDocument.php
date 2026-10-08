<?php

declare(strict_types=1);

namespace LangChain\Indexing;

use LangChain\Schema\Document;
use LangChain\Utils\Uuid;

/**
 * A document with content and metadata hashes calculated, used for indexing.
 *
 * Port of `_HashedDocument` from `@langchain/core/indexing`.
 */
class HashedDocument
{
    public string $uid;

    public ?string $hash_ = null;

    public ?string $contentHash = null;

    public ?string $metadataHash = null;

    public string $pageContent;

    /** @var array<string, mixed> */
    public array $metadata;

    /** @var callable(string): string */
    private $keyEncoder;

    /**
     * @param array{pageContent: string, metadata: array<string, mixed>, uid?: string|null} $fields
     */
    public function __construct(array $fields)
    {
        $this->uid = (string) ($fields['uid'] ?? '');
        $this->pageContent = $fields['pageContent'];
        $this->metadata = $fields['metadata'];
        $this->keyEncoder = static fn (string $input): string => hash('sha256', $input);
    }

    /**
     * @param callable(string): string $keyEncoderFn
     */
    public function makeDefaultKeyEncoder(callable $keyEncoderFn): void
    {
        $this->keyEncoder = $keyEncoderFn;
    }

    public function calculateHashes(): void
    {
        $forbiddenKeys = ['hash_', 'content_hash', 'metadata_hash'];

        foreach ($forbiddenKeys as $key) {
            if (array_key_exists($key, $this->metadata)) {
                throw new \InvalidArgumentException(sprintf(
                    'Metadata cannot contain key %s as it is reserved for internal use. Restricted keys: [%s]',
                    $key,
                    implode(', ', $forbiddenKeys),
                ));
            }
        }

        $contentHash = $this->hashStringToUuid($this->pageContent);

        try {
            $metadataHash = $this->hashNestedDictToUuid($this->metadata);
            $this->contentHash = $contentHash;
            $this->metadataHash = $metadataHash;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(sprintf(
                'Failed to hash metadata: %s. Please use a dict that can be serialized using json.',
                $e->getMessage(),
            ), 0, $e);
        }

        $this->hash_ = $this->hashStringToUuid($this->contentHash . $this->metadataHash);

        if ($this->uid === '') {
            $this->uid = $this->hash_;
        }
    }

    public function toDocument(): Document
    {
        return new Document($this->pageContent, $this->metadata);
    }

    public static function fromDocument(Document $document, ?string $uid = null): self
    {
        $docUid = $document->uid ?? null;
        $doc = new self([
            'pageContent' => $document->pageContent,
            'metadata' => $document->metadata,
            'uid' => ($uid !== null && $uid !== '') ? $uid : (is_string($docUid) ? $docUid : ''),
        ]);
        $doc->calculateHashes();

        return $doc;
    }

    private function hashStringToUuid(string $inputString): string
    {
        return Uuid::v5(($this->keyEncoder)($inputString), RecordManagerInterface::UUIDV5_NAMESPACE);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hashNestedDictToUuid(array $data): string
    {
        $keys = array_map('strval', array_keys($data));
        sort($keys, SORT_STRING);

        return $this->hashStringToUuid(self::stringifyWithKeys($data, $keys, true));
    }

    /**
     * `JSON.stringify(value, keys)`: an array replacer keeps only properties
     * whose name is listed, at EVERY depth, and writes them in list order.
     *
     * @param list<string> $keys
     */
    private static function stringifyWithKeys(mixed $value, array $keys, bool $isObject = false): string
    {
        if (is_array($value)) {
            if (!$isObject && array_is_list($value)) {
                return '[' . implode(',', array_map(
                    static fn (mixed $v): string => self::stringifyWithKeys($v, $keys),
                    $value,
                )) . ']';
            }

            $parts = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $value)) {
                    $parts[] = json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        . ':' . self::stringifyWithKeys($value[$key], $keys);
                }
            }

            return '{' . implode(',', $parts) . '}';
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (int) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $encoded;
    }
}
