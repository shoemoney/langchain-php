<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\TextSplitters;

use LangChain\TextSplitters\Language;
use LangChain\TextSplitters\RecursiveCharacterTextSplitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecursiveCharacterTextSplitter::class)]
#[CoversClass(Language::class)]
final class RecursiveCharacterTextSplitterTest extends TestCase
{
    /**
     * Port of `code_text_splitter.test.ts`.
     *
     * Each case pins the separator hierarchy of a language end to end: the list
     * has to prefer a definition keyword over a blank line, and the recursion
     * has to fall through to word and character level for whatever is still too
     * long. A separator list that is merely "close" produces plausible output
     * here, which is why the expectations are exact.
     */
    #[DataProvider('codeSamples')]
    public function testCodeSplitter(string $language, string $code, array $expected): void
    {
        $splitter = RecursiveCharacterTextSplitter::fromLanguage($language, chunkSize: 16, chunkOverlap: 0);

        $this->assertSame($expected, $splitter->splitText($code));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function codeSamples(): array
    {
        return [
            'python' => [
                'python',
                "def hello_world():\n  print(\"Hello, World!\")\n# Call the function\nhello_world()",
                [
                    'def',
                    'hello_world():',
                    'print("Hello,',
                    'World!")',
                    '# Call the',
                    'function',
                    'hello_world()',
                ],
            ],
            'go' => [
                'go',
                "package main\nimport \"fmt\"\nfunc helloWorld() {\n    fmt.Println(\"Hello, World!\")\n}\nfunc main() {\n    helloWorld()\n}",
                [
                    'package main',
                    'import "fmt"',
                    'func',
                    'helloWorld() {',
                    'fmt.Println("He',
                    'llo,',
                    'World!")',
                    '}',
                    'func main() {',
                    'helloWorld()',
                    '}',
                ],
            ],
            'rst' => [
                'rst',
                "Sample Document\n===============\nSection\n-------\nThis is the content of the section.\nLists\n-----\n- Item 1\n- Item 2\n- Item 3",
                [
                    'Sample Document',
                    '===============',
                    "Section\n-------",
                    'This is the',
                    'content of the',
                    'section.',
                    "Lists\n-----",
                    '- Item 1',
                    '- Item 2',
                    '- Item 3',
                ],
            ],
            'proto' => [
                'proto',
                "syntax = \"proto3\";\npackage example;\nmessage Person {\n    string name = 1;\n    int32 age = 2;\n    repeated string hobbies = 3;\n}",
                [
                    'syntax =',
                    '"proto3";',
                    'package',
                    'example;',
                    'message Person',
                    '{',
                    'string name',
                    '= 1;',
                    'int32 age =',
                    '2;',
                    'repeated',
                    'string hobbies',
                    '= 3;',
                    '}',
                ],
            ],
            'js' => [
                'js',
                "function helloWorld() {\n  console.log(\"Hello, World!\");\n}\n// Call the function\nhelloWorld();",
                [
                    'function',
                    'helloWorld() {',
                    'console.log("He',
                    'llo,',
                    'World!");',
                    '}',
                    '// Call the',
                    'function',
                    'helloWorld();',
                ],
            ],
            'java' => [
                'java',
                "public class HelloWorld {\n  public static void main(String[] args) {\n      System.out.println(\"Hello, World!\");\n  }\n}",
                [
                    'public class',
                    'HelloWorld {',
                    'public static',
                    'void',
                    'main(String[]',
                    'args) {',
                    'System.out.prin',
                    'tln("Hello,',
                    'World!");',
                    "}\n}",
                ],
            ],
            'cpp' => [
                'cpp',
                "#include <iostream>\nint main() {\n    std::cout << \"Hello, World!\" << std::endl;\n    return 0;\n}",
                [
                    '#include',
                    '<iostream>',
                    'int main() {',
                    'std::cout',
                    '<< "Hello,',
                    'World!" <<',
                    'std::endl;',
                    "return 0;\n}",
                ],
            ],
            'scala' => [
                'scala',
                "object HelloWorld {\n  def main(args: Array[String]): Unit = {\n    println(\"Hello, World!\")\n  }\n}",
                [
                    'object',
                    'HelloWorld {',
                    'def',
                    'main(args:',
                    'Array[String]):',
                    'Unit = {',
                    'println("Hello,',
                    'World!")',
                    "}\n}",
                ],
            ],
            'ruby' => [
                'ruby',
                "def hello_world\n  puts \"Hello, World!\"\nend\nhello_world",
                [
                    'def hello_world',
                    'puts "Hello,',
                    'World!"',
                    "end\nhello_world",
                ],
            ],
            'php' => [
                'php',
                "<?php\nfunction hello_world() {\n    echo \"Hello, World!\";\n}\nhello_world();\n?>",
                [
                    '<?php',
                    'function',
                    'hello_world() {',
                    'echo',
                    '"Hello,',
                    'World!";',
                    '}',
                    'hello_world();',
                    '?>',
                ],
            ],
            'swift' => [
                'swift',
                "func helloWorld() {\n  print(\"Hello, World!\")\n}\nhelloWorld()",
                [
                    'func',
                    'helloWorld() {',
                    'print("Hello,',
                    'World!")',
                    "}\nhelloWorld()",
                ],
            ],
            'rust' => [
                'rust',
                "fn main() {\n  println!(\"Hello, World!\");\n}",
                [
                    'fn main() {',
                    'println!("Hello',
                    ',',
                    'World!");',
                    '}',
                ],
            ],
            'sol' => [
                'sol',
                "pragma solidity ^0.8.20;\n  contract HelloWorld {\n    function add(uint a, uint b) pure public returns(uint) {\n      return  a + b;\n    }\n  }\n  ",
                [
                    'pragma solidity',
                    '^0.8.20;',
                    'contract',
                    'HelloWorld {',
                    'function',
                    'add(uint a,',
                    'uint b) pure',
                    'public',
                    'returns(uint) {',
                    'return  a',
                    '+ b;',
                    "}\n  }",
                ],
            ],
        ];
    }

    // ---- language helpers ----------------------------------------------------

    #[DataProvider('allLanguages')]
    public function testEverySupportedLanguageHasASeparatorHierarchy(string $language): void
    {
        $separators = RecursiveCharacterTextSplitter::getSeparatorsForLanguage($language);

        $this->assertNotSame([], $separators);
        // Every hierarchy ends in the empty separator, which is the floor that
        // guarantees the search terminates and guarantees a chunk can always be
        // produced, however long the input.
        $this->assertSame('', end($separators));
        $this->assertSame(' ', $separators[count($separators) - 2]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allLanguages(): array
    {
        $cases = [];
        foreach (Language::SUPPORTED_LANGUAGES as $language) {
            $cases[$language] = [$language];
        }

        return $cases;
    }

    public function testSupportedLanguageList(): void
    {
        $this->assertSame([
            'cpp', 'go', 'java', 'js', 'php', 'proto', 'python', 'rst', 'ruby',
            'rust', 'scala', 'swift', 'markdown', 'latex', 'html', 'sol',
        ], Language::SUPPORTED_LANGUAGES);
    }

    public function testIsSupported(): void
    {
        $this->assertTrue(Language::isSupported('python'));
        $this->assertTrue(Language::isSupported('markdown'));
        $this->assertFalse(Language::isSupported('cobol'));
        $this->assertFalse(Language::isSupported(''));
        // Case matters: the lookup is a literal key match, not a normalisation.
        $this->assertFalse(Language::isSupported('Python'));
    }

    public function testAnUnsupportedLanguageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Language cobol is not supported.');

        RecursiveCharacterTextSplitter::getSeparatorsForLanguage('cobol');
    }

    public function testFromLanguageRejectsAnUnsupportedLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Language cobol is not supported.');

        RecursiveCharacterTextSplitter::fromLanguage('cobol');
    }

    public function testEveryHierarchyPrefersDefinitionsOverBlankLines(): void
    {
        // The ordering is the behaviour: a definition keyword must be tried
        // before the generic "\n\n", or a class body is split mid-method.
        foreach (Language::SUPPORTED_LANGUAGES as $language) {
            $separators = RecursiveCharacterTextSplitter::getSeparatorsForLanguage($language);
            $generic = array_search("\n\n", $separators, true);
            $blank = array_search("\n", $separators, true);

            if ($generic === false || $blank === false) {
                continue; // html has no newline separators at all
            }

            $this->assertGreaterThan($generic, $blank, "{$language}: \\n must be tried after \\n\\n");
        }
    }

    public function testMarkdownSplitterUsesTheMarkdownHierarchy(): void
    {
        $this->assertSame(
            RecursiveCharacterTextSplitter::getSeparatorsForLanguage('markdown'),
            (new \LangChain\TextSplitters\MarkdownTextSplitter())->separators,
        );
    }

    public function testLatexSplitterUsesTheLatexHierarchy(): void
    {
        $this->assertSame(
            RecursiveCharacterTextSplitter::getSeparatorsForLanguage('latex'),
            (new \LangChain\TextSplitters\LatexTextSplitter())->separators,
        );
    }

    public function testAnExplicitSeparatorListOverridesTheLanguageDefault(): void
    {
        $splitter = RecursiveCharacterTextSplitter::fromLanguage('python', chunkSize: 100, chunkOverlap: 0);
        $this->assertSame(
            RecursiveCharacterTextSplitter::getSeparatorsForLanguage('python'),
            $splitter->separators,
        );

        $custom = new RecursiveCharacterTextSplitter(separators: ['\n## ', ' ', '']);
        $this->assertSame(['\n## ', ' ', ''], $custom->separators);
    }

    public function testTheEmptySeparatorIsTheLastResortNotTheLastWord(): void
    {
        // With only the empty separator in play the text is reduced to single
        // characters, and the merge step then packs them back up to chunkSize.
        $splitter = new RecursiveCharacterTextSplitter(separators: [''], chunkSize: 2, chunkOverlap: 0);

        $this->assertSame(['ab', 'c'], $splitter->splitText('abc'));
        $this->assertSame(['ab', 'cd', 'ef'], $splitter->splitText('abcdef'));
    }

    public function testASingleCustomSeparatorSplitsAndReJoinsOnTheSameBoundary(): void
    {
        // "|" is not in the text, so the search falls through to the empty
        // separator, and keepSeparator (on by default) welds "|" to the piece
        // that follows it.
        $splitter = new RecursiveCharacterTextSplitter(separators: ['|'], chunkSize: 4, chunkOverlap: 0);

        $this->assertSame(['a|b', '|c'], $splitter->splitText('a|b|c'));
    }

    public function testRepeatedCustomSeparatorsAreSplitAndReMerged(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(separators: ['ab'], chunkSize: 6, chunkOverlap: 0);

        $this->assertSame(['ababab', 'ab'], $splitter->splitText('abababab'));
    }
}
