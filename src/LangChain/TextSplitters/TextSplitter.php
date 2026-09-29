<?php

declare(strict_types=1);

namespace LangChain\TextSplitters;

use LangChain\Schema\Document;

/**
 * Base class for splitting text into chunks.
 *
 * Port of `TextSplitter` from `@langchain/textsplitters`.
 *
 * The class is abstract but almost all of the interesting behaviour lives here,
 * because every splitter in the family reduces to the same two steps:
 *
 *  1. **cut** the text on a separator into a list of small pieces
 *     ({@see self::splitOnSeparator()}), and
 *  2. **merge** those pieces back up into chunks that are as large as allowed
 *     without exceeding `chunkSize`, overlapping by `chunkOverlap`
 *     ({@see self::mergeSplits()}).
 *
 * Subclasses choose *where* to cut — a literal separator, a priority list of
 * separators tried in order, or nothing at all — and inherit step 2 unchanged.
 * That is why `mergeSplits()` is written the way it is: it is the piece that is
 * subtle enough to break silently, because a splitter that produces
 * overlapping-but-wrong chunks still looks plausible to the eye.
 *
 * @template T of list<string>
 */
abstract class TextSplitter extends BaseDocumentTransformer
{
    /** Default maximum chunk size, in whatever unit `lengthFunction` measures. */
    public const DEFAULT_CHUNK_SIZE = 1000;

    /** Default overlap between adjacent chunks, in the same unit. */
    public const DEFAULT_CHUNK_OVERLAP = 200;

    /**
     * The maximum size of a chunk.
     *
     * Measured by {@see self::$lengthFunction}, not by byte count, so a
     * token-based splitter can swap in a token counter here without touching
     * the merging logic.
     */
    public int $chunkSize = self::DEFAULT_CHUNK_SIZE;

    /**
     * The overlap kept between the end of one chunk and the start of the next.
     *
     * Overlap is what keeps a sentence that straddles a boundary retrievable
     * from *either* side; it is not padding. It must be strictly less than
     * {@see self::$chunkSize} or merging could never make progress, which is
     * why the constructor rejects that configuration.
     */
    public int $chunkOverlap = self::DEFAULT_CHUNK_OVERLAP;

    /**
     * Whether the separator stays attached to the piece that follows it.
     *
     * With `keepSeparator = false` the separator is consumed and re-emitted by
     * the merge step only when it joins two pieces back together — so a
     * separator that fell on a chunk boundary disappears. With
     * `keepSeparator = true` the separator is glued to the front of the next
     * piece at cut time and survives even across boundaries, which keeps
     * document structure (a heading, an HTML tag) attached to the text it
     * introduces.
     */
    public bool $keepSeparator = false;

    /**
     * Measures the size of a piece of text.
     *
     * Defaults to {@see TextLength::utf16CodeUnits()}, which reproduces the
     * JS `text => text.length` default exactly — including counting an emoji
     * as two rather than as one.
     *
     * The TS original also accepts an `async` length function; PHP has no
     * `await`, so a length function here is always synchronous and must return
     * an int.
     *
     * @var callable(string): int
     */
    public $lengthFunction;

    /**
     * @param int|null    $chunkSize      Defaults to 1000.
     * @param int|null    $chunkOverlap   Defaults to 200. Must be < `$chunkSize`.
     * @param bool|null   $keepSeparator  Defaults to false.
     * @param (callable(string): int)|null $lengthFunction Defaults to counting characters.
     *
     * @throws \InvalidArgumentException if `$chunkOverlap >= $chunkSize`.
     */
    public function __construct(
        ?int $chunkSize = null,
        ?int $chunkOverlap = null,
        ?bool $keepSeparator = null,
        ?callable $lengthFunction = null,
    ) {
        $this->chunkSize = $chunkSize ?? self::DEFAULT_CHUNK_SIZE;
        $this->chunkOverlap = $chunkOverlap ?? self::DEFAULT_CHUNK_OVERLAP;
        $this->keepSeparator = $keepSeparator ?? $this->keepSeparator;
        $this->lengthFunction = $lengthFunction
            ?? static fn (string $text): int => TextLength::utf16CodeUnits($text);

        if ($this->chunkOverlap >= $this->chunkSize) {
            throw new \InvalidArgumentException('Cannot have chunkOverlap >= chunkSize');
        }
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain', 'document_transformers', 'text_splitters'];
    }

    /**
     * Split incoming text into chunks.
     *
     * @return T a list of chunk strings.
     */
    abstract public function splitText(string $text): array;

    /**
     * Measure a piece of text with {@see self::$lengthFunction}.
     */
    protected function lengthOf(string $text): int
    {
        return ($this->lengthFunction)($text);
    }

    /**
     * Cut `$text` on `$separator`, dropping the empty pieces.
     *
     * @return list<string>
     */
    protected function splitOnSeparator(string $text, string $separator): array
    {
        if ($separator !== '') {
            if ($this->keepSeparator) {
                // A zero-width lookahead: cut *before* every occurrence instead
                // of after, so the separator stays welded to the following piece.
                // preg_quote is the PHP spelling of the JS character-class escape.
                $splits = preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text);
            } else {
                $splits = explode($separator, $text);
            }
        } else {
            // An empty separator means "no structure left, cut anywhere": the
            // text is reduced to single characters and left to mergeSplits()
            // to reassemble up to chunkSize.
            $splits = $text === '' ? [] : mb_str_split($text, 1, 'UTF-8');
        }

        if ($splits === false) {
            // A malformed UTF-8 subject makes preg_split() bail out. Falling
            // back to a literal cut keeps the splitter usable on text that
            // survived a lossy transport, rather than silently returning [].
            $splits = explode($separator, $text);
        }

        return array_values(array_filter($splits, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Merge small splits into chunks no larger than `chunkSize`, overlapping by
     * `chunkOverlap`.
     *
     * This is the heart of every splitter, and the reason chunks come out
     * overlapping rather than merely adjacent. The inner `while` loop is
     * subtler than it looks: once a chunk is emitted, the walk repeatedly drops
     * splits off the *front* of the pending window until that window is small
     * enough that the incoming split both fits inside `chunkSize` and has
     * shrunk past `chunkOverlap`. Those two conditions are deliberately
     * separate — the first keeps the chunk legal, the second keeps overlap
     * bounded — and a rewrite that merged them into one `if` would silently
     * change chunk boundaries everywhere.
     *
     * @param list<string> $splits
     * @return list<string>
     */
    public function mergeSplits(array $splits, string $separator): array
    {
        /** @var list<string> $docs */
        $docs = [];
        /** @var list<string> $currentDoc */
        $currentDoc = [];
        $total = 0;

        foreach ($splits as $d) {
            $len = $this->lengthOf($d);

            if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize) {
                if ($total > $this->chunkSize) {
                    // A single split longer than chunkSize, with no smaller
                    // separator left to fall back on. Warn rather than drop it.
                    $this->warnOversizedChunk($total);
                }

                if ($currentDoc !== []) {
                    $doc = self::joinDocs($currentDoc, $separator);
                    if ($doc !== null) {
                        $docs[] = $doc;
                    }

                    // Keep on popping if:
                    // - we have a larger chunk than in the chunk overlap
                    // - or if we still have any chunks and the length is long
                    while (
                        $total > $this->chunkOverlap
                        || (
                            $total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize
                            && $total > 0
                        )
                    ) {
                        $total -= $this->lengthOf($currentDoc[0]);
                        array_shift($currentDoc);
                    }
                }
            }

            $currentDoc[] = $d;
            $total += $len;
        }

        $doc = self::joinDocs($currentDoc, $separator);
        if ($doc !== null) {
            $docs[] = $doc;
        }

        return $docs;
    }

    /**
     * Join splits with a separator and trim; `null` when nothing is left.
     *
     * Returning null rather than an empty string is deliberate: a chunk that
     * is only whitespace is not a chunk, and every caller has to handle the
     * "produced nothing" case anyway, since text can be entirely separators.
     *
     * @param list<string> $docs
     */
    protected static function joinDocs(array $docs, string $separator): ?string
    {
        $text = trim(implode($separator, $docs));

        return $text === '' ? null : $text;
    }

    /**
     * Report a chunk that exceeded `chunkSize`.
     *
     * Routed through `trigger_error()` so the condition is observable by
     * anything reading the error log, and so a test suite can assert on it
     * rather than on captured stdout.
     */
    protected function warnOversizedChunk(int $size): void
    {
        trigger_error(
            "Created a chunk of size {$size}, which is longer than the specified {$this->chunkSize}",
            E_USER_WARNING,
        );
    }

    /**
     * Create documents from a list of texts, with optional metadata.
     *
     * Every chunk also gets a `loc.lines` metadata entry naming the 1-indexed
     * line range it came from, computed by walking the source text and
     * counting the newlines that splitting removed. That counter is why the
     * chunk's position in the *original* text has to be recovered with
     * `indexOf` rather than tracked incrementally: with overlap, the same
     * region of the source is visited more than once, and the walk moves
     * backwards as often as forwards.
     *
     * @param list<string>                  $texts
     * @param list<array<string, mixed>>    $metadatas
     * @param TextSplitterChunkHeaderOptions|null $options
     * @return list<Document>
     */
    public function createDocuments(
        array $texts,
        array $metadatas = [],
        ?TextSplitterChunkHeaderOptions $options = null
    ): array {
        // if no metadata is provided, we create an empty one for each text
        if ($metadatas === []) {
            $metadatas = array_fill(0, count($texts), []);
        }

        $options ??= new TextSplitterChunkHeaderOptions();

        /** @var list<Document> $documents */
        $documents = [];

        foreach ($texts as $i => $text) {
            $lineCounterIndex = 1;
            $prevChunk = null;
            $indexPrevChunk = -1;

            foreach ($this->splitText($text) as $chunk) {
                $pageContent = $options->chunkHeader;

                // we need to count the \n that are in the text before getting removed by the splitting
                $indexChunk = self::indexOf($text, $chunk, $indexPrevChunk + 1);

                if ($prevChunk === null) {
                    $newLinesBeforeFirstChunk = self::numberOfNewLines($text, 0, $indexChunk);
                    $lineCounterIndex += $newLinesBeforeFirstChunk;
                } else {
                    $indexEndPrevChunk = $indexPrevChunk + mb_strlen($prevChunk, 'UTF-8');
                    if ($indexEndPrevChunk < $indexChunk) {
                        $numberOfIntermediateNewLines = self::numberOfNewLines(
                            $text,
                            $indexEndPrevChunk,
                            $indexChunk,
                        );
                        $lineCounterIndex += $numberOfIntermediateNewLines;
                    } elseif ($indexEndPrevChunk > $indexChunk) {
                        // Overlapping chunks: the walk is going backwards
                        // through the source, so the line count has to come
                        // back down with it.
                        $numberOfIntermediateNewLines = self::numberOfNewLines(
                            $text,
                            $indexChunk,
                            $indexEndPrevChunk,
                        );
                        $lineCounterIndex -= $numberOfIntermediateNewLines;
                    }

                    if ($options->appendChunkOverlapHeader) {
                        $pageContent .= $options->chunkOverlapHeader;
                    }
                }

                $newLinesCount = self::numberOfNewLines($chunk);

                $metadata = $metadatas[$i] ?? [];
                $loc = isset($metadata['loc']) && is_array($metadata['loc'])
                    ? $metadata['loc']
                    : [];
                $loc['lines'] = [
                    'from' => $lineCounterIndex,
                    'to' => $lineCounterIndex + $newLinesCount,
                ];
                $metadata['loc'] = $loc;

                $pageContent .= $chunk;
                $documents[] = new Document($pageContent, $metadata);

                $lineCounterIndex += $newLinesCount;
                $prevChunk = $chunk;
                $indexPrevChunk = $indexChunk;
            }
        }

        return $documents;
    }

    /**
     * Split a list of documents into a list of smaller documents.
     *
     * @param list<Document>                $documents
     * @param TextSplitterChunkHeaderOptions|null $options
     * @return list<Document>
     */
    public function splitDocuments(
        array $documents,
        ?TextSplitterChunkHeaderOptions $options = null
    ): array {
        // The TS original drops documents whose `pageContent` is `undefined`.
        // `$pageContent` is a non-nullable string here, so that filter is a
        // no-op by construction; only the re-indexing matters, since the
        // metadata list is zipped against the text list by position.
        $selectedDocuments = array_values($documents);

        $texts = array_map(
            static fn (Document $doc): string => $doc->pageContent,
            $selectedDocuments,
        );
        $metadatas = array_map(
            static fn (Document $doc): array => $doc->metadata,
            $selectedDocuments,
        );

        return $this->createDocuments($texts, $metadatas, $options);
    }

    /**
     * @param list<Document>                $documents
     * @param TextSplitterChunkHeaderOptions|null $options
     * @return list<Document>
     */
    public function transformDocuments(
        array $documents,
        ?TextSplitterChunkHeaderOptions $options = null
    ): array {
        return $this->splitDocuments($documents, $options);
    }

    /**
     * Count the newlines in `$text` between two offsets.
     *
     * A negative `$end` means "that far from the end", matching the JS
     * `String.prototype.slice` convention — which matters because
     * {@see self::createDocuments()} reaches here with `$indexChunk === -1` when
     * a chunk cannot be located in its source text.
     */
    protected static function numberOfNewLines(string $text, int $start = 0, ?int $end = null): int
    {
        $textSection = self::slice($text, $start, $end);

        return $textSection === '' ? 0 : substr_count($textSection, "\n");
    }

    /**
     * `String.prototype.slice` semantics: negative offsets count from the end,
     * and a reversed pair yields the empty string.
     */
    protected static function slice(string $text, int $start, ?int $end = null): string
    {
        $length = mb_strlen($text, 'UTF-8');
        $end ??= $length;

        if ($start < 0) {
            $start = max(0, $length + $start);
        }
        if ($end < 0) {
            $end = max(0, $length + $end);
        }
        if ($end <= $start) {
            return '';
        }

        return mb_substr($text, $start, $end - $start, 'UTF-8');
    }

    /**
     * `String.prototype.indexOf` with a from-offset, in code points.
     *
     * Returns -1 when the needle is absent, which callers here treat as a real
     * value rather than as "not found" — see {@see self::numberOfNewLines()}.
     */
    protected static function indexOf(string $haystack, string $needle, int $from = 0): int
    {
        if ($needle === '') {
            return 0;
        }

        $offset = max(0, $from);
        $position = mb_strpos($haystack, $needle, $offset, 'UTF-8');

        return $position === false ? -1 : $position;
    }
}
