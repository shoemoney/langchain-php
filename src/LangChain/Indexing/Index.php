<?php

declare(strict_types=1);

namespace LangChain\Indexing;

use LangChain\DocumentLoaders\DocumentLoader;
use LangChain\Schema\Document;
use LangChain\VectorStores\VectorStore;

/**
 * Index documents into a vector store, using a record manager to skip,
 * update and clean up.
 *
 * Port of `index()` and its helpers from `@langchain/core/indexing`
 * (`_batch`, `_deduplicateInOrder`, `_getSourceIdAssigner`, `_isBaseDocumentLoader`).
 * Upstream's free functions are static methods because the port adds no
 * function files.
 *
 * Documents are identified by hash (content plus metadata), so re-indexing
 * unchanged input is a no-op that only refreshes the record's timestamp.
 * `cleanup` modes: `null` deletes nothing; `incremental` deletes stale records
 * sharing a source id with this batch, continuously; `full` deletes every
 * record not touched by this run, after all batches. (Upstream JS has exactly
 * these two modes; "scoped_full" is Python-only and is not ported.)
 *
 * The vector store must implement `delete(['ids' => ...])` and honour the
 * `ids` option of `addDocuments()` for cleanup to work.
 */
final class Index
{
    private function __construct()
    {
    }

    /**
     * @param array{
     *     docsSource: DocumentLoader|list<Document>,
     *     recordManager: RecordManagerInterface,
     *     vectorStore: VectorStore,
     *     options?: array{
     *         batchSize?: int,
     *         cleanup?: 'full'|'incremental'|null,
     *         sourceIdKey?: string|callable(Document): ?string|null,
     *         cleanupBatchSize?: int,
     *         forceUpdate?: bool,
     *     },
     * } $args
     *
     * @return array{numAdded: int, numDeleted: int, numUpdated: int, numSkipped: int}
     */
    public static function index(array $args): array
    {
        $docsSource = $args['docsSource'];
        $recordManager = $args['recordManager'];
        $vectorStore = $args['vectorStore'];
        $options = $args['options'] ?? [];

        $batchSize = $options['batchSize'] ?? 100;
        $cleanup = $options['cleanup'] ?? null;
        $sourceIdKey = $options['sourceIdKey'] ?? null;
        $cleanupBatchSize = $options['cleanupBatchSize'] ?? 1000;
        $forceUpdate = $options['forceUpdate'] ?? false;

        if ($cleanup === 'incremental' && ($sourceIdKey === null || $sourceIdKey === '')) {
            throw new \InvalidArgumentException(
                "sourceIdKey is required when cleanup mode is incremental. Please provide through 'options.sourceIdKey'.",
            );
        }

        $docs = self::isBaseDocumentLoader($docsSource) ? $docsSource->load() : $docsSource;

        $sourceIdAssigner = self::getSourceIdAssigner($sourceIdKey);

        $indexStartDt = $recordManager->getTime();
        $numAdded = 0;
        $numDeleted = 0;
        $numUpdated = 0;
        $numSkipped = 0;

        foreach (self::batch($batchSize, $docs) as $batch) {
            $hashedDocs = self::deduplicateInOrder(array_map(
                static fn (Document $doc): HashedDocument => HashedDocument::fromDocument($doc),
                $batch,
            ));

            $sourceIds = array_map(
                static fn (HashedDocument $doc): ?string => $sourceIdAssigner($doc->toDocument()),
                $hashedDocs,
            );

            if ($cleanup === 'incremental') {
                foreach ($sourceIds as $source) {
                    if ($source === null) {
                        throw new \InvalidArgumentException('sourceIdKey must be provided when cleanup is incremental');
                    }
                }
            }

            $batchExists = $recordManager->exists(array_map(
                static fn (HashedDocument $doc): string => $doc->uid,
                $hashedDocs,
            ));

            $uids = [];
            $docsToIndex = [];
            $docsToUpdate = [];
            $seenDocs = [];
            foreach ($hashedDocs as $i => $hashedDoc) {
                if ($batchExists[$i]) {
                    if ($forceUpdate) {
                        $seenDocs[$hashedDoc->uid] = true;
                    } else {
                        $docsToUpdate[] = $hashedDoc->uid;
                        continue;
                    }
                }
                $uids[] = $hashedDoc->uid;
                $docsToIndex[] = $hashedDoc->toDocument();
            }

            if ($docsToUpdate !== []) {
                $recordManager->update($docsToUpdate, ['timeAtLeast' => $indexStartDt]);
                $numSkipped += count($docsToUpdate);
            }

            if ($docsToIndex !== []) {
                $vectorStore->addDocuments($docsToIndex, ['ids' => $uids]);
                $numAdded += count($docsToIndex) - count($seenDocs);
                $numUpdated += count($seenDocs);
            }

            $recordManager->update(
                array_map(static fn (HashedDocument $doc): string => $doc->uid, $hashedDocs),
                ['timeAtLeast' => $indexStartDt, 'groupIds' => $sourceIds],
            );

            if ($cleanup === 'incremental') {
                foreach ($sourceIds as $sourceId) {
                    if ($sourceId === null || $sourceId === '' || $sourceId === '0') {
                        throw new \InvalidArgumentException('Source id cannot be null');
                    }
                }
                $uidsToDelete = $recordManager->listKeys(['before' => $indexStartDt, 'groupIds' => $sourceIds]);

                if ($uidsToDelete !== []) {
                    $vectorStore->delete(['ids' => $uidsToDelete]);
                    $recordManager->deleteKeys($uidsToDelete);
                    $numDeleted += count($uidsToDelete);
                }
            }
        }

        if ($cleanup === 'full') {
            $uidsToDelete = $recordManager->listKeys(['before' => $indexStartDt, 'limit' => $cleanupBatchSize]);
            while ($uidsToDelete !== []) {
                $vectorStore->delete(['ids' => $uidsToDelete]);
                $recordManager->deleteKeys($uidsToDelete);
                $numDeleted += count($uidsToDelete);
                $uidsToDelete = $recordManager->listKeys(['before' => $indexStartDt, 'limit' => $cleanupBatchSize]);
            }
        }

        return [
            'numAdded' => $numAdded,
            'numDeleted' => $numDeleted,
            'numUpdated' => $numUpdated,
            'numSkipped' => $numSkipped,
        ];
    }

    /**
     * Split into consecutive batches of `$size`.
     *
     * @template T
     *
     * @param list<T> $iterable
     *
     * @return list<list<T>>
     */
    public static function batch(int $size, array $iterable): array
    {
        $batches = [];
        $currentBatch = [];

        foreach ($iterable as $item) {
            $currentBatch[] = $item;

            if (count($currentBatch) >= $size) {
                $batches[] = $currentBatch;
                $currentBatch = [];
            }
        }

        if ($currentBatch !== []) {
            $batches[] = $currentBatch;
        }

        return $batches;
    }

    /**
     * Drop documents whose hash was already seen, keeping first occurrences.
     *
     * @param list<HashedDocument> $hashedDocuments
     *
     * @return list<HashedDocument>
     */
    public static function deduplicateInOrder(array $hashedDocuments): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($hashedDocuments as $hashedDoc) {
            if ($hashedDoc->hash_ === null || $hashedDoc->hash_ === '') {
                throw new \InvalidArgumentException('Hashed document does not have a hash');
            }

            if (!isset($seen[$hashedDoc->hash_])) {
                $seen[$hashedDoc->hash_] = true;
                $deduplicated[] = $hashedDoc;
            }
        }

        return $deduplicated;
    }

    /**
     * @param string|callable(Document): ?string|null $sourceIdKey
     *
     * @return callable(Document): ?string
     */
    public static function getSourceIdAssigner(string|callable|null $sourceIdKey): callable
    {
        if ($sourceIdKey === null) {
            return static fn (Document $doc): ?string => null;
        }

        if (is_string($sourceIdKey)) {
            return static function (Document $doc) use ($sourceIdKey): ?string {
                $value = $doc->metadata[$sourceIdKey] ?? null;

                return $value === null ? null : (string) $value;
            };
        }

        return $sourceIdKey;
    }

    /**
     * Whether the source is a loader rather than a list of documents.
     *
     * Upstream tests for `load` and `loadAndSplit` methods, but its own
     * `BaseDocumentLoader` no longer has `loadAndSplit`, so a core loader would
     * fail that test. The port checks the {@see DocumentLoader} contract.
     *
     * @phpstan-assert-if-true DocumentLoader $arg
     */
    public static function isBaseDocumentLoader(mixed $arg): bool
    {
        return $arg instanceof DocumentLoader;
    }
}
