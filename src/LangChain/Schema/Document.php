<?php

declare(strict_types=1);

namespace LangChain\Schema;

use LangChain\Load\Serializable;

/**
 * A piece of source text plus its metadata.
 *
 * Port of `Document` from `@langchain/core/documents/document`.
 *
 * `pageContent` is the text; `metadata` is a free-form bag that travels with it
 * through splitting, embedding, storage, and retrieval. The split between the
 * two is the whole contract of the RAG half of the framework: a splitter must
 * preserve every key of the metadata map across the pieces it produces, or
 * provenance is lost.
 */
class Document extends Serializable
{
    public string $pageContent;

    /** @var array<string, mixed> */
    public array $metadata;

    /** Optional collection identifier; uniqueness and UUID format are not enforced. */
    public ?string $id;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(string $pageContent = '', array $metadata = [], ?string $id = null)
    {
        $this->pageContent = $pageContent;
        $this->metadata = $metadata;
        $this->id = $id;
        $this->kwargs = ['page_content' => $pageContent, 'metadata' => $metadata];
    }

    /** Serialize the current identity alongside the existing constructor fields. */
    public function kwargs(): array
    {
        return [
            ...parent::kwargs(),
            ...($this->id !== null ? ['id' => $this->id] : []),
        ];
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'documents', 'Document'];
    }

    /**
     * Copy this document with different text, leaving metadata intact.
     */
    public function withPageContent(string $pageContent): self
    {
        return new self($pageContent, $this->metadata, $this->id);
    }

    /**
     * Copy this document with an extra metadata key.
     */
    public function withMetadata(string $key, mixed $value): self
    {
        $meta = $this->metadata;
        $meta[$key] = $value;

        return new self($this->pageContent, $meta, $this->id);
    }

    public function __toString(): string
    {
        $json = json_encode($this->toJson(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "page_content: \"{$this->pageContent}\"\n" . ($json === false ? '' : $json);
    }
}
