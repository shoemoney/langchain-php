<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\TextSplitters;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangChain\Schema\Document;
use LangChain\TextSplitters\BaseDocumentTransformer;
use LangChain\TextSplitters\CharacterTextSplitter;
use LangChain\TextSplitters\LatexTextSplitter;
use LangChain\TextSplitters\MarkdownTextSplitter;
use LangChain\TextSplitters\RecursiveCharacterTextSplitter;
use LangChain\TextSplitters\TextSplitter;
use LangChain\TextSplitters\TextSplitterChunkHeaderOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextSplitter::class)]
#[CoversClass(BaseDocumentTransformer::class)]
#[CoversClass(CharacterTextSplitter::class)]
#[CoversClass(RecursiveCharacterTextSplitter::class)]
#[CoversClass(MarkdownTextSplitter::class)]
#[CoversClass(LatexTextSplitter::class)]
#[CoversClass(TextSplitterChunkHeaderOptions::class)]
final class TextSplitterTest extends TestCase
{
    /**
     * The JS `textLineGenerator`: `new Array(length).join(char)` yields
     * `length - 1` copies, and each generated line ends in a newline.
     */
    private static function textLine(string $char, int $length): string
    {
        return str_repeat($char, $length - 1) . "\n";
    }

    /**
     * Run `$fn` with user-level PHP warnings swallowed.
     *
     * A few upstream cases deliberately produce chunks larger than `chunkSize`.
     * Upstream reports that with `console.warn`; the port RECORDS it
     * ({@see TextSplitter::oversizedChunkWarnings()}) rather than raising an
     * `E_USER_WARNING`, because this suite runs with `failOnWarning="true"` and
     * the library could not otherwise be tested for its own documented case.
     *
     * This helper is now a no-op kept so those call sites read as they did: the
     * behaviour it existed to contain is gone. Retained deliberately rather
     * than deleted — it is the thing that makes a regression here visible, since
     * a warning would be swallowed again by someone who did not know why the
     * wrapper was there.
     */
    private static function ignoringUserWarnings(callable $fn): mixed
    {
        set_error_handler(
            static fn (int $errno): bool => $errno === E_USER_WARNING,
            E_USER_WARNING,
        );

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Run `$fn` and return every user-level warning it raised.
     *
     * The warning is captured rather than asserted with PHPUnit's error
     * expectation API, which this PHPUnit build does not expose for user
     * warnings.
     *
     * @return list<string>
     */
    private static function capturingUserWarnings(callable $fn): array
    {
        $seen = [];
        set_error_handler(
            static function (int $errno, string $message) use (&$seen): bool {
                $seen[] = $message;

                return true;
            },
            E_USER_WARNING,
        );

        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $seen;
    }

    /**
     * @param list<Document> $docs
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private static function flatten(array $docs): array
    {
        return array_map(
            static fn (Document $d): array => [$d->pageContent, $d->metadata],
            $docs,
        );
    }

    // ---- CharacterTextSplitter ---------------------------------------------

    public function testSplittingByCharacterCount(): void
    {
        $text = 'foo bar baz 123';
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 7, chunkOverlap: 3);

        $this->assertSame(['foo bar', 'bar baz', 'baz 123'], $splitter->splitText($text));
    }

    public function testSplittingByCharacterCountDoesNotCreateEmptyDocuments(): void
    {
        $text = 'foo  bar';
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 2, chunkOverlap: 0);

        $output = self::ignoringUserWarnings(static fn (): array => $splitter->splitText($text));

        $this->assertSame(['foo', 'bar'], $output);
    }

    public function testSplittingByCharacterCountOnLongWords(): void
    {
        $text = 'foo bar baz a a';
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 1);

        $this->assertSame(['foo', 'bar', 'baz', 'a a'], $splitter->splitText($text));
    }

    public function testSplittingByCharacterCountWhenShorterWordsAreFirst(): void
    {
        $text = 'a a foo bar baz';
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 1);

        $this->assertSame(['a a', 'foo', 'bar', 'baz'], $splitter->splitText($text));
    }

    public function testSplittingByCharactersWhenSplitsNotFoundEasily(): void
    {
        $text = 'foo bar baz 123';
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 1, chunkOverlap: 0);

        $output = self::ignoringUserWarnings(static fn (): array => $splitter->splitText($text));

        $this->assertSame(['foo', 'bar', 'baz', '123'], $output);
    }

    public function testInvalidArgumentsThrowsWhenOverlapIsNotSmallerThanSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot have chunkOverlap >= chunkSize');

        new CharacterTextSplitter(chunkSize: 2, chunkOverlap: 4);
    }

    public function testEqualChunkSizeAndOverlapIsAlsoRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CharacterTextSplitter(chunkSize: 4, chunkOverlap: 4);
    }

    /**
     * An oversized chunk is RECORDED, and nothing is raised.
     *
     * This asserted the opposite for the life of the bug: it required an
     * `E_USER_WARNING`, and the rest of the file was contorted to swallow that
     * warning under `failOnWarning="true"`. The pin meant the defect could not
     * be fixed without also rewriting the test that enshrined it — which is
     * what happened, and the reason the fix looked larger than it was.
     */
    public function testAnOversizedChunkIsRecordedAndNothingIsRaised(): void
    {
        TextSplitter::clearOversizedChunkWarnings();
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 2, chunkOverlap: 0);

        $raised = self::capturingUserWarnings(static fn (): array => $splitter->splitText('foo  bar'));

        $this->assertSame([], $raised, 'an oversized chunk must not raise E_USER_WARNING');
        $this->assertSame(
            ['Created a chunk of size 3, which is longer than the specified 2'],
            TextSplitter::oversizedChunkWarnings(),
            'the notice must still be reported, just not as a PHP warning',
        );
    }

    public function testCreateDocumentsMethod(): void
    {
        $texts = ['foo bar', 'baz'];
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 0);

        $docs = $splitter->createDocuments($texts);

        $this->assertSame([
            ['foo', ['loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
            ['bar', ['loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
            ['baz', ['loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
        ], self::flatten($docs));
    }

    public function testCreateDocumentsWithMetadataMethod(): void
    {
        $texts = ['foo bar', 'baz'];
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 0);

        $docs = $splitter->createDocuments($texts, [['source' => '1'], ['source' => '2']]);

        $this->assertSame([
            ['foo', ['source' => '1', 'loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
            ['bar', ['source' => '1', 'loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
            ['baz', ['source' => '2', 'loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
        ], self::flatten($docs));
    }

    public function testCreateDocumentsWithMetadataAndAnAddedChunkHeader(): void
    {
        $texts = ['foo bar', 'baz'];
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 0);

        $docs = $splitter->createDocuments(
            $texts,
            [['source' => '1'], ['source' => '2']],
            new TextSplitterChunkHeaderOptions(
                chunkHeader: "SOURCE NAME: testing\n-----\n",
                appendChunkOverlapHeader: true,
            ),
        );

        $this->assertSame([
            ['SOURCE NAME: testing' . "\n-----\n" . 'foo', ['source' => '1', 'loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
            ["SOURCE NAME: testing\n-----\n(cont'd) bar", ['source' => '1', 'loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
            ["SOURCE NAME: testing\n-----\nbaz", ['source' => '2', 'loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
        ], self::flatten($docs));
    }

    public function testChunkHeaderIsNotAppendedToTheFirstChunkUnlessRequested(): void
    {
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 0);

        $docs = $splitter->createDocuments(['foo bar'], [], new TextSplitterChunkHeaderOptions(chunkHeader: 'HEAD: '));

        $this->assertSame([
            ['HEAD: foo', ['loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
            ['HEAD: bar', ['loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
        ], self::flatten($docs));
    }

    public function testChunkOverlapHeaderIsNotAppliedWhenAppendIsOff(): void
    {
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 0);

        $docs = $splitter->createDocuments(['foo bar'], [], new TextSplitterChunkHeaderOptions(
            chunkHeader: 'HEAD: ',
            appendChunkOverlapHeader: false,
        ));

        $this->assertSame('HEAD: bar', $docs[1]->pageContent);
    }

    public function testChunkHeaderOptionsFromArrayFallsBackToDefaults(): void
    {
        $options = TextSplitterChunkHeaderOptions::fromArray([
            'chunkHeader' => 'x',
            'unknownKey' => 'ignored',
        ]);

        $this->assertSame('x', $options->chunkHeader);
        $this->assertSame("(cont'd) ", $options->chunkOverlapHeader);
        $this->assertFalse($options->appendChunkOverlapHeader);
    }

    // ---- RecursiveCharacterTextSplitter -------------------------------------

    public function testOneUniqueChunk(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 100, chunkOverlap: 0);
        $content = self::textLine('A', 70);

        $docs = $splitter->createDocuments([$content]);

        $this->assertSame([
            [trim($content), ['loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
        ], self::flatten($docs));
    }

    public function testIterativeTextSplitter(): void
    {
        $text = "Hi.\n\nI'm Harrison.\n\nHow? Are? You?\nOkay then f f f f.\n"
            . "This is a weird text to write, but gotta test the splittingggg some how.\n\n"
            . "Bye!\n\n-H.";
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 10, chunkOverlap: 1);

        $this->assertSame([
            'Hi.',
            "I'm",
            'Harrison.',
            'How? Are?',
            'You?',
            'Okay then',
            'f f f f.',
            'This is a',
            'weird',
            'text to',
            'write,',
            'but gotta',
            'test the',
            'splitting',
            'gggg',
            'some how.',
            'Bye!',
            '-H.',
        ], $splitter->splitText($text));
    }

    public function testABasicChunkedDocument(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 100, chunkOverlap: 0);
        $line1 = self::textLine('A', 70);
        $line2 = self::textLine('B', 70);
        $content = $line1 . $line2;

        $docs = $splitter->createDocuments([$content]);

        $this->assertSame([
            [trim($line1), ['loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
            [trim($line2), ['loc' => ['lines' => ['from' => 2, 'to' => 2]]]],
        ], self::flatten($docs));
    }

    public function testAChunkedDocumentWithSimilarText(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 100, chunkOverlap: 0);
        $line = self::textLine('A', 70);
        $content = $line . $line;

        $docs = $splitter->createDocuments([$content]);

        $this->assertSame([
            [trim($line), ['loc' => ['lines' => ['from' => 1, 'to' => 1]]]],
            [trim($line), ['loc' => ['lines' => ['from' => 2, 'to' => 2]]]],
        ], self::flatten($docs));
    }

    public function testAChunkedDocumentStartingWithNewLines(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 100, chunkOverlap: 0);
        $line1 = self::textLine("\n", 2);
        $line2 = self::textLine('A', 70);
        $line3 = self::textLine("\n", 4);
        $line4 = self::textLine('B', 70);
        $line5 = self::textLine("\n", 4);
        $content = $line1 . $line2 . $line3 . $line4 . $line5;

        $docs = $splitter->createDocuments([$content]);

        $this->assertSame([
            [trim($line2), ['loc' => ['lines' => ['from' => 3, 'to' => 3]]]],
            [trim($line4), ['loc' => ['lines' => ['from' => 8, 'to' => 8]]]],
        ], self::flatten($docs));
    }

    public function testAChunkedWithOverlap(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 100, chunkOverlap: 30);
        $line1 = self::textLine('A', 70);
        $line2 = self::textLine('B', 20);
        $line3 = self::textLine('C', 70);
        $content = $line1 . $line2 . $line3;

        $docs = $splitter->createDocuments([$content]);

        $this->assertSame([
            [$line1 . trim($line2), ['loc' => ['lines' => ['from' => 1, 'to' => 2]]]],
            [$line2 . trim($line3), ['loc' => ['lines' => ['from' => 2, 'to' => 3]]]],
        ], self::flatten($docs));
    }

    public function testChunksWithOverlapThatContainsNewLines(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 100, chunkOverlap: 30);
        $line1 = self::textLine('A', 70);
        $line2 = self::textLine('B', 10);
        $line3 = self::textLine('C', 10);
        $line4 = self::textLine('D', 70);
        $content = $line1 . $line2 . $line3 . $line4;

        $docs = $splitter->createDocuments([$content]);

        $this->assertSame([
            [$line1 . $line2 . trim($line3), ['loc' => ['lines' => ['from' => 1, 'to' => 3]]]],
            [$line2 . $line3 . trim($line4), ['loc' => ['lines' => ['from' => 2, 'to' => 4]]]],
        ], self::flatten($docs));
    }

    public function testLineNumbersWithACustomLengthFunction(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(
            chunkSize: 3,
            chunkOverlap: 0,
            lengthFunction: static fn (string $text): int => count(preg_split('/\s+/', $text) ?: []),
        );

        $docs = $splitter->createDocuments(["aaaa\nbbbb\n\ncccc"]);

        $this->assertSame([
            ["aaaa\nbbbb", ['loc' => ['lines' => ['from' => 1, 'to' => 2]]]],
            ['cccc', ['loc' => ['lines' => ['from' => 4, 'to' => 4]]]],
        ], self::flatten($docs));
    }

    public function testLinesLocOnIterativeTextSplitter(): void
    {
        $text = "Hi.\nI'm Harrison.\n\nHow?\na\nb";
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 20, chunkOverlap: 1);

        $docs = $splitter->createDocuments([$text]);

        $this->assertSame([
            ["Hi.\nI'm Harrison.", ['loc' => ['lines' => ['from' => 1, 'to' => 2]]]],
            ["How?\na\nb", ['loc' => ['lines' => ['from' => 4, 'to' => 6]]]],
        ], self::flatten($docs));
    }

    public function testSeparatorLengthIsConsideredCorrectlyForChunkSize(): void
    {
        $text = 'aa ab ac ba bb';
        $splitter = new RecursiveCharacterTextSplitter(keepSeparator: false, chunkSize: 7, chunkOverlap: 3);

        $this->assertSame(['aa ab', 'ab ac', 'ac ba', 'ba bb'], $splitter->splitText($text));
    }

    public function testRecursiveSplitterKeepsSeparatorByDefault(): void
    {
        $splitter = new RecursiveCharacterTextSplitter();

        $this->assertTrue($splitter->keepSeparator);
    }

    public function testCharacterSplitterDoesNotKeepSeparatorByDefault(): void
    {
        $splitter = new CharacterTextSplitter();

        $this->assertFalse($splitter->keepSeparator);
    }

    public function testKeepSeparatorAttachesTheSeparatorToTheFollowingPiece(): void
    {
        $text = "a\n\nb\n\nc";

        // The cut is a zero-width lookahead, so with keepSeparator the separator
        // rides along with the text it introduces rather than being consumed.
        $this->assertSame(['a', "\n\nb", "\n\nc"], self::splitOn($text, "\n\n", true));
        $this->assertSame(['a', 'b', 'c'], self::splitOn($text, "\n\n", false));
    }

    public function testKeepSeparatorAppliesToSingleCharacterSeparatorsToo(): void
    {
        $this->assertSame(['a', ' b', ' ', ' c'], self::splitOn('a b  c', ' ', true));
        $this->assertSame(['a', 'b', 'c'], self::splitOn('a b  c', ' ', false));
    }

    public function testEmptyPiecesAreDroppedEvenWhenTheyLeadTheText(): void
    {
        // The lookahead split opens with an empty piece; the filter must remove
        // it, or the first chunk would be blank.
        $this->assertSame(["\n\na"], self::splitOn("\n\na", "\n\n", true));
    }

    public function testAnEmptySeparatorSplitsIntoSingleCharacters(): void
    {
        $this->assertSame(['a', 'é'], self::splitOn('aé', '', true));
    }

    public function testAnEmptySubjectYieldsNoPiecesForAnEmptySeparator(): void
    {
        $this->assertSame([], self::splitOn('', '', true));
    }

    #[DataProvider('regexMetaCharacterSeparators')]
    public function testRegexMetaCharactersInTheSeparatorAreMatchedLiterally(string $separator, string $text, array $expected): void
    {
        // A zero-width lookahead is a regex, so an unescaped "." would match
        // every character and split the text into single letters.
        $this->assertSame($expected, self::splitOn($text, $separator, true));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function regexMetaCharacterSeparators(): array
    {
        return [
            'dot' => ['.', 'x.y.z', ['x', '.y', '.z']],
            'dollar' => ['$', 'x$yy$z', ['x', '$yy', '$z']],
            'alternation group' => ['(a|b)', 'x(a|b)y', ['x', '(a|b)y']],
            'literal that is not a group' => ['a.b', 'xaybazb', ['xaybazb']],
        ];
    }

    /**
     * Reach the protected cut step, which is where `keepSeparator` is actually
     * implemented and therefore the only place it can be asserted directly.
     *
     * @return list<string>
     */
    private static function splitOn(string $text, string $separator, bool $keepSeparator): array
    {
        return (new class (keepSeparator: $keepSeparator) extends CharacterTextSplitter {
            /** @return list<string> */
            public function cut(string $text, string $separator): array
            {
                return $this->splitOnSeparator($text, $separator);
            }
        })->cut($text, $separator);
    }

    // ---- Markdown / LaTeX / HTML --------------------------------------------

    public function testMarkdownTextSplitter(): void
    {
        $text = "# \u{1F9CC}\u{FE0F}\u{1F517} LangChain\n"
            . "\n"
            . "\u{26A1} Building applications with LLMs through composability \u{26A1}\n"
            . "\n"
            . "## Quick Install\n"
            . "\n"
            . "```bash\n"
            . "# Hopefully this code block isn't split\n"
            . "pip install langchain\n"
            . "```\n"
            . "\n"
            . 'As an open source project in a rapidly developing field, we are extremely open to contributions.';
        $splitter = new MarkdownTextSplitter(chunkSize: 100, chunkOverlap: 0);

        $this->assertSame([
            "# \u{1F9CC}\u{FE0F}\u{1F517} LangChain\n\n\u{26A1} Building applications with LLMs through composability \u{26A1}",
            "## Quick Install\n\n```bash\n# Hopefully this code block isn't split\npip install langchain",
            '```',
            'As an open source project in a rapidly developing field, we are extremely open to contributions.',
        ], $splitter->splitText($text));
    }

    public function testLatexTextSplitter(): void
    {
        $text = "\\begin{document}\n"
            . "\\title{\u{1F9CC}\u{FE0F}\u{1F517} LangChain}\n"
            . "\u{26A1} Building applications with LLMs through composability \u{26A1}\n"
            . "\n"
            . "\\section{Quick Install}\n"
            . "\n"
            . "\\begin{verbatim}\n"
            . "Hopefully this code block isn't split\n"
            . "pnpm install langchain\n"
            . "\\end{verbatim}\n"
            . "\n"
            . 'As an open source project in a rapidly developing field, we are extremely open to contributions.'
            . "\n"
            . '\\end{document}';
        $splitter = new LatexTextSplitter(chunkSize: 100, chunkOverlap: 0);

        $this->assertSame([
            "\\begin{document}\n\\title{\u{1F9CC}\u{FE0F}\u{1F517} LangChain}\n\u{26A1} Building applications with LLMs through composability \u{26A1}",
            '\\section{Quick Install}',
            "\\begin{verbatim}\nHopefully this code block isn't split\npnpm install langchain\n\\end{verbatim}",
            'As an open source project in a rapidly developing field, we are extremely open to contributions.',
            '\\end{document}',
        ], $splitter->splitText($text));
    }

    public function testHtmlTextSplitter(): void
    {
        $text = "<!DOCTYPE html>\n<html>\n  <head>\n    <title>\u{1F9CC}\u{FE0F}\u{1F517} LangChain</title>\n"
            . "    <style>\n      body {\n        font-family: Arial, sans-serif;\n      }\n"
            . "      h1 {\n        color: darkblue;\n      }\n    </style>\n  </head>\n"
            . "  <body>\n    <div>\n      <h1>\u{1F9CC}\u{FE0F}\u{1F517} LangChain</h1>\n"
            . "      <p>\u{26A1} Building applications with LLMs through composability \u{26A1}</p>\n    </div>\n"
            . "    <div>\n      As an open source project in a rapidly developing field, we are extremely open to contributions.\n"
            . "    </div>\n  </body>\n</html>";
        $splitter = RecursiveCharacterTextSplitter::fromLanguage('html', chunkSize: 175, chunkOverlap: 20);

        $this->assertSame([
            "<!DOCTYPE html>\n<html>",
            "<head>\n    <title>\u{1F9CC}\u{FE0F}\u{1F517} LangChain</title>",
            "<style>\n      body {\n        font-family: Arial, sans-serif;\n      }\n      h1 {\n        color: darkblue;\n      }\n    </style>\n  </head>",
            "<body>\n    <div>\n      <h1>\u{1F9CC}\u{FE0F}\u{1F517} LangChain</h1>\n      <p>\u{26A1} Building applications with LLMs through composability \u{26A1}</p>\n    </div>",
            "<div>\n      As an open source project in a rapidly developing field, we are extremely open to contributions.\n    </div>\n  </body>\n</html>",
        ], $splitter->splitText($text));
    }

    // ---- Runnable integration ------------------------------------------------

    public function testSplitDocumentsPreservesMetadata(): void
    {
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 0);

        $docs = $splitter->splitDocuments([
            new Document('foo bar', ['source' => 'a', 'loc' => ['page' => 7]]),
        ]);

        $this->assertSame([
            ['foo', ['source' => 'a', 'loc' => ['page' => 7, 'lines' => ['from' => 1, 'to' => 1]]]],
            ['bar', ['source' => 'a', 'loc' => ['page' => 7, 'lines' => ['from' => 1, 'to' => 1]]]],
        ], self::flatten($docs));
    }

    public function testTransformDocumentsDelegatesToSplitDocuments(): void
    {
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 0);

        $this->assertSame(
            self::flatten($splitter->splitDocuments([new Document('foo bar', ['source' => 'a'])])),
            self::flatten($splitter->transformDocuments([new Document('foo bar', ['source' => 'a'])])),
        );
    }

    public function testInvokeRunsTransformDocuments(): void
    {
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 0);

        $result = $splitter->invoke([new Document('foo bar', [])]);

        $this->assertInstanceOf(BaseDocumentTransformer::class, $splitter);
        $this->assertSame(['foo', 'bar'], array_map(
            static fn (Document $d): string => $d->pageContent,
            $result,
        ));
    }

    public function testSplitterIsPipeableAsARunnable(): void
    {
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 0);
        $collect = RunnableLambda::from(
            static fn (array $docs): array => array_map(
                static fn (Document $d): string => $d->pageContent,
                $docs,
            ),
        );

        $chain = $splitter->pipe($collect);
        $result = $chain->invoke([new Document('foo bar', [])]);

        $this->assertInstanceOf(RunnableSequence::class, $chain);
        $this->assertSame(['foo', 'bar'], $result);
    }

    public function testSplitterIsARunnable(): void
    {
        $splitter = new CharacterTextSplitter();

        $this->assertInstanceOf(\LangChain\Runnables\RunnableInterface::class, $splitter);
        $this->assertSame('CharacterTextSplitter', $splitter->getName());
        $this->assertInstanceOf(RunnableConfig::class, new RunnableConfig());
    }

    public function testStreamEmitsTheWholeResultOnTheDefaultChannel(): void
    {
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 3, chunkOverlap: 0);

        $chunks = iterator_to_array($splitter->stream([new Document('foo bar', [])]));

        $this->assertCount(1, $chunks);
        $this->assertSame(TextSplitter::CHANNEL_DEFAULT, $chunks[0][0]);
        $this->assertCount(2, $chunks[0][1]);
    }

    public function testLcId(): void
    {
        $this->assertSame(
            ['langchain', 'document_transformers', 'text_splitters'],
            TextSplitter::lcId(),
        );
        $this->assertSame(
            ['langchain', 'document_transformers', 'text_splitters', 'CharacterTextSplitter'],
            CharacterTextSplitter::lcId(),
        );
        $this->assertSame(
            ['langchain', 'document_transformers', 'text_splitters', 'RecursiveCharacterTextSplitter'],
            RecursiveCharacterTextSplitter::lcId(),
        );
        $this->assertSame(
            ['langchain', 'document_transformers', 'text_splitters', 'MarkdownTextSplitter'],
            MarkdownTextSplitter::lcId(),
        );
        $this->assertSame(
            ['langchain', 'document_transformers', 'text_splitters', 'LatexTextSplitter'],
            LatexTextSplitter::lcId(),
        );
    }

    // ---- mergeSplits ---------------------------------------------------------

    public function testMergeSplitsPacksUpToChunkSize(): void
    {
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 7, chunkOverlap: 0);

        // "aa ab ac" is 8 characters, so the third word cannot join a chunk
        // that already holds two.
        $this->assertSame(['aa ab', 'ac ba', 'bb'], $splitter->mergeSplits(['aa', 'ab', 'ac', 'ba', 'bb'], ' '));
    }

    public function testMergeSplitsDropsEmptyResults(): void
    {
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 10, chunkOverlap: 0);

        $this->assertSame([], $splitter->mergeSplits(['', '  ', ''], ' '));
    }

    public function testMergeSplitsPaysForTheSeparatorOnEveryInternalJoin(): void
    {
        $splitter = new CharacterTextSplitter(chunkSize: 7, chunkOverlap: 0);

        // A three-character separator costs three per join: "aa" + "-->" + "ab"
        // is exactly 7 and fits, where a one-character separator would leave
        // room for a third word. Charging the wrong amount here shifts every
        // chunk boundary in the output.
        $this->assertSame(['aa-->ab', 'ac-->ba', 'bb'], $splitter->mergeSplits(['aa', 'ab', 'ac', 'ba', 'bb'], '-->'));
    }

    // ---- edge cases ----------------------------------------------------------

    public function testEmptyTextProducesNoChunks(): void
    {
        $splitter = new RecursiveCharacterTextSplitter();

        $this->assertSame([], $splitter->splitText(''));
        $this->assertSame([], $splitter->createDocuments(['']));
    }

    public function testTextOfOnlySeparatorsProducesNoChunks(): void
    {
        $splitter = new CharacterTextSplitter(separator: "\n\n", chunkSize: 10, chunkOverlap: 0);

        $this->assertSame([], $splitter->splitText("\n\n\n\n"));
    }

    public function testDefaultChunkSizeAndOverlap(): void
    {
        $splitter = new RecursiveCharacterTextSplitter();

        $this->assertSame(1000, $splitter->chunkSize);
        $this->assertSame(200, $splitter->chunkOverlap);
    }

    public function testSplitTextPreservesEveryCharacterWhenTheSeparatorIsEmpty(): void
    {
        $splitter = new CharacterTextSplitter(separator: '', chunkSize: 1000, chunkOverlap: 0, keepSeparator: false);

        $text = "a\u{1F9CC}é\nz";

        $this->assertSame([$text], $splitter->splitText($text));
    }

    public function testRecursionFallsBackToCharacterLevelForAnUnbreakableWord(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 4, chunkOverlap: 0);

        $this->assertSame(['abcd', 'efgh', 'ij'], $splitter->splitText('abcdefghij'));
    }
}
