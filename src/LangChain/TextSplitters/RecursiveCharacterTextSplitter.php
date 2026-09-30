<?php

declare(strict_types=1);

namespace LangChain\TextSplitters;

/**
 * Implementation of splitter which recursively looks at characters.
 *
 * Port of `RecursiveCharacterTextSplitter` from `@langchain/textsplitters`.
 *
 * Splitting prose is a negotiation, because a chunk boundary is only good if
 * it lands where a reader would also land. So instead of committing to one
 * delimiter, this splitter keeps a *priority list* of separators ordered from
 * most to least semantically meaningful, and for any given piece of text picks
 * the first one that actually occurs in it. Paragraph breaks beat line breaks
 * beat spaces beat character-level splitting, and each level only falls back to
 * the next one for the fragments that are still too long — short siblings are
 * left alone and merged as they are.
 *
 * The recursion is what makes the output useful rather than merely small: a
 * fragment that is still over `chunkSize` after cutting at the best available
 * separator gets re-split with the *remaining, weaker* separators, so a single
 * enormous word degrades to word-sized, then character-sized pieces instead of
 * forcing the whole document into a uniform grid.
 *
 * Note that this splitter flips `keepSeparator` on by default, unlike its
 * parent. Keeping the separator is what preserves a heading from its body or a
 * `<p>` tag from its paragraph; the cost is that chunks begin with the marker.
 */
class RecursiveCharacterTextSplitter extends TextSplitter
{
    /**
     * Separators in descending order of preference.
     *
     * The trailing empty string is not a separator so much as a floor: it
     * matches any text, which ends the search and means "split anywhere".
     *
     * @var list<string>
     */
    public array $separators = ["\n\n", "\n", ' ', ''];

    /**
     * @param list<string>|null  $separators   Defaults to paragraphs → lines → words → characters.
     * @param int|null           $chunkSize
     * @param int|null           $chunkOverlap
     * @param bool|null          $keepSeparator Defaults to true, unlike the base class.
     * @param (callable(string): int)|null $lengthFunction
     */
    public function __construct(
        ?array $separators = null,
        ?int $chunkSize = null,
        ?int $chunkOverlap = null,
        ?bool $keepSeparator = null,
        ?callable $lengthFunction = null,
    ) {
        parent::__construct(
            chunkSize: $chunkSize,
            chunkOverlap: $chunkOverlap,
            keepSeparator: $keepSeparator,
            lengthFunction: $lengthFunction,
        );

        $this->separators = $separators ?? $this->separators;
        $this->keepSeparator = $keepSeparator ?? true;
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain', 'document_transformers', 'text_splitters', 'RecursiveCharacterTextSplitter'];
    }

    /**
     * The recursive walk.
     *
     * @param list<string> $separators The separator list still in play at this
     *                                  level — the tail of the caller's list
     *                                  from the chosen separator onwards.
     * @return list<string>
     */
    protected function splitTextRecursive(string $text, array $separators): array
    {
        /** @var list<string> $finalChunks */
        $finalChunks = [];

        // Get appropriate separator to use.
        //
        // Upstream is the same expression with no guard —
        // `separators[separators.length - 1]` (text_splitter.ts:301). In
        // JavaScript an empty list indexes to `undefined`, and
        // `String.split(undefined)` returns the whole string, so upstream
        // returns `[text]`.
        //
        // In PHP the same expression is a warning plus a fatal: an empty list
        // gives "Undefined array key -1" and then
        // `splitOnSeparator(): Argument #2 ($separator) must be of type string,
        // null given`. Measured, both, on a splitter constructed with
        // `separators: []`.
        //
        // So this resolves to '' — the floor separator — which reproduces
        // upstream's `[text]` result. A constructor guard would also stop the
        // fatal, but it would REJECT an input upstream accepts, and this is a
        // port: the language difference is the port's problem to absorb, not
        // the caller's.
        $separator = $separators === [] ? '' : $separators[count($separators) - 1];
        $newSeparators = null;
        foreach ($separators as $i => $s) {
            if ($s === '') {
                $separator = $s;
                break;
            }
            if (str_contains($text, $s)) {
                $separator = $s;
                $newSeparators = array_values(array_slice($separators, $i + 1));
                break;
            }
        }

        // Now that we have the separator, split the text
        $splits = $this->splitOnSeparator($text, $separator);

        // Now go merging things, recursively splitting longer texts.
        /** @var list<string> $goodSplits */
        $goodSplits = [];
        $mergeSeparator = $this->keepSeparator ? '' : $separator;
        foreach ($splits as $s) {
            if ($this->lengthOf($s) < $this->chunkSize) {
                $goodSplits[] = $s;
            } else {
                // Flush the short siblings before recursing, so they leave as
                // one chunk rather than being interleaved with the deep split.
                if ($goodSplits !== []) {
                    $mergedText = $this->mergeSplits($goodSplits, $mergeSeparator);
                    foreach ($mergedText as $m) {
                        $finalChunks[] = $m;
                    }
                    $goodSplits = [];
                }

                if ($newSeparators === null) {
                    // Out of separators: this fragment is as small as it gets,
                    // so emit it even though it is over budget.
                    $finalChunks[] = $s;
                } else {
                    $otherInfo = $this->splitTextRecursive($s, $newSeparators);
                    foreach ($otherInfo as $o) {
                        $finalChunks[] = $o;
                    }
                }
            }
        }

        if ($goodSplits !== []) {
            $mergedText = $this->mergeSplits($goodSplits, $mergeSeparator);
            foreach ($mergedText as $m) {
                $finalChunks[] = $m;
            }
        }

        return $finalChunks;
    }

    /**
     * Split incoming text into chunks.
     *
     * @return list<string>
     */
    public function splitText(string $text): array
    {
        return $this->splitTextRecursive($text, $this->separators);
    }

    /**
     * Build a splitter tuned for a programming or markup language.
     *
     * @throws \InvalidArgumentException if the language has no tuned hierarchy.
     */
    public static function fromLanguage(
        string $language,
        ?int $chunkSize = null,
        ?int $chunkOverlap = null,
        ?bool $keepSeparator = null,
        ?callable $lengthFunction = null,
    ): self {
        return new self(
            separators: self::getSeparatorsForLanguage($language),
            chunkSize: $chunkSize,
            chunkOverlap: $chunkOverlap,
            keepSeparator: $keepSeparator,
            lengthFunction: $lengthFunction,
        );
    }

    /**
     * The separator hierarchy for a language, ordered most to least meaningful.
     *
     * @return list<string>
     * @throws \InvalidArgumentException if the language has no tuned hierarchy.
     */
    public static function getSeparatorsForLanguage(string $language): array
    {
        return match ($language) {
            Language::CPP => [
                // Split along class definitions
                "\nclass ",
                // Split along function definitions
                "\nvoid ",
                "\nint ",
                "\nfloat ",
                "\ndouble ",
                // Split along control flow statements
                "\nif ",
                "\nfor ",
                "\nwhile ",
                "\nswitch ",
                "\ncase ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::GO => [
                // Split along function definitions
                "\nfunc ",
                "\nvar ",
                "\nconst ",
                "\ntype ",
                // Split along control flow statements
                "\nif ",
                "\nfor ",
                "\nswitch ",
                "\ncase ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::JAVA => [
                // Split along class definitions
                "\nclass ",
                // Split along method definitions
                "\npublic ",
                "\nprotected ",
                "\nprivate ",
                "\nstatic ",
                // Split along control flow statements
                "\nif ",
                "\nfor ",
                "\nwhile ",
                "\nswitch ",
                "\ncase ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::JS => [
                // Split along function definitions
                "\nfunction ",
                "\nconst ",
                "\nlet ",
                "\nvar ",
                "\nclass ",
                // Split along control flow statements
                "\nif ",
                "\nfor ",
                "\nwhile ",
                "\nswitch ",
                "\ncase ",
                "\ndefault ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::PHP => [
                // Split along function definitions
                "\nfunction ",
                // Split along class definitions
                "\nclass ",
                // Split along control flow statements
                "\nif ",
                "\nforeach ",
                "\nwhile ",
                "\ndo ",
                "\nswitch ",
                "\ncase ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::PROTO => [
                // Split along message definitions
                "\nmessage ",
                // Split along service definitions
                "\nservice ",
                // Split along enum definitions
                "\nenum ",
                // Split along option definitions
                "\noption ",
                // Split along import statements
                "\nimport ",
                // Split along syntax declarations
                "\nsyntax ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::PYTHON => [
                // First, try to split along class definitions
                "\nclass ",
                "\ndef ",
                "\n\tdef ",
                // Now split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::RST => [
                // Split along section titles
                "\n===\n",
                "\n---\n",
                "\n***\n",
                // Split along directive markers
                "\n.. ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::RUBY => [
                // Split along method definitions
                "\ndef ",
                "\nclass ",
                // Split along control flow statements
                "\nif ",
                "\nunless ",
                "\nwhile ",
                "\nfor ",
                "\ndo ",
                "\nbegin ",
                "\nrescue ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::RUST => [
                // Split along function definitions
                "\nfn ",
                "\nconst ",
                "\nlet ",
                // Split along control flow statements
                "\nif ",
                "\nwhile ",
                "\nfor ",
                "\nloop ",
                "\nmatch ",
                "\nconst ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::SCALA => [
                // Split along class definitions
                "\nclass ",
                "\nobject ",
                // Split along method definitions
                "\ndef ",
                "\nval ",
                "\nvar ",
                // Split along control flow statements
                "\nif ",
                "\nfor ",
                "\nwhile ",
                "\nmatch ",
                "\ncase ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::SWIFT => [
                // Split along function definitions
                "\nfunc ",
                // Split along class definitions
                "\nclass ",
                "\nstruct ",
                "\nenum ",
                // Split along control flow statements
                "\nif ",
                "\nfor ",
                "\nwhile ",
                "\ndo ",
                "\nswitch ",
                "\ncase ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::MARKDOWN => [
                // First, try to split along Markdown headings (starting with level 2)
                "\n## ",
                "\n### ",
                "\n#### ",
                "\n##### ",
                "\n###### ",
                // Note the alternative syntax for headings (below) is not handled here
                // Heading level 2
                // ---------------
                // End of code block
                "```\n\n",
                // Horizontal lines
                "\n\n***\n\n",
                "\n\n---\n\n",
                "\n\n___\n\n",
                // Note that this splitter doesn't handle horizontal lines defined
                // by *three or more* of ***, ---, or ___, but this is not handled
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::LATEX => [
                // First, try to split along Latex sections
                "\n\\chapter{",
                "\n\\section{",
                "\n\\subsection{",
                "\n\\subsubsection{",
                // Now split by environments
                "\n\\begin{enumerate}",
                "\n\\begin{itemize}",
                "\n\\begin{description}",
                "\n\\begin{list}",
                "\n\\begin{quote}",
                "\n\\begin{quotation}",
                "\n\\begin{verse}",
                "\n\\begin{verbatim}",
                // Now split by math environments
                "\n\\begin{align}",
                '$$',
                '$',
                // Now split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            Language::HTML => [
                // First, try to split along HTML tags
                '<body>',
                '<div>',
                '<p>',
                '<br>',
                '<li>',
                '<h1>',
                '<h2>',
                '<h3>',
                '<h4>',
                '<h5>',
                '<h6>',
                '<span>',
                '<table>',
                '<tr>',
                '<td>',
                '<th>',
                '<ul>',
                '<ol>',
                '<header>',
                '<footer>',
                '<nav>',
                // Head
                '<head>',
                '<style>',
                '<script>',
                '<meta>',
                '<title>',
                // Normal type of lines
                ' ',
                '',
            ],
            Language::SOL => [
                // Split along compiler informations definitions
                "\npragma ",
                "\nusing ",
                // Split along contract definitions
                "\ncontract ",
                "\ninterface ",
                "\nlibrary ",
                // Split along method definitions
                "\nconstructor ",
                "\ntype ",
                "\nfunction ",
                "\nevent ",
                "\nmodifier ",
                "\nerror ",
                "\nstruct ",
                "\nenum ",
                // Split along control flow statements
                "\nif ",
                "\nfor ",
                "\nwhile ",
                "\ndo while ",
                "\nassembly ",
                // Split by the normal type of lines
                "\n\n",
                "\n",
                ' ',
                '',
            ],
            default => throw new \InvalidArgumentException("Language {$language} is not supported."),
        };
    }
}
