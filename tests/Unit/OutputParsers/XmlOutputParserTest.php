<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\XmlOutputParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `output_parsers/tests/xml.test.ts`.
 */
#[CoversClass(XmlOutputParser::class)]
final class XmlOutputParserTest extends TestCase
{
    private const XML_EXAMPLE = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <userProfile>
          <userID>12345</userID>
          <email>john.doe@example.com</email>
          <roles>
            <role>Admin</role>
            <role>User</role>
          </roles>
          <preferences>
            <theme>Dark</theme>
            <notifications>
              <email>true</email>
            </notifications>
          </preferences>
        </userProfile>
        XML;

    /** @return array<string, mixed> */
    private static function expectedResult(): array
    {
        return [
            'userProfile' => [
                ['userID' => '12345'],
                ['email' => 'john.doe@example.com'],
                [
                    'roles' => [
                        ['role' => 'Admin'],
                        ['role' => 'User'],
                    ],
                ],
                [
                    'preferences' => [
                        ['theme' => 'Dark'],
                        [
                            'notifications' => [
                                ['email' => 'true'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function xml(): string
    {
        return self::XML_EXAMPLE;
    }

    public function testCanParseXml(): void
    {
        $parser = new XmlOutputParser();

        self::assertSame(self::expectedResult(), $parser->invoke(self::xml()));
    }

    public function testCanParseBacktickWrappedXml(): void
    {
        $parser = new XmlOutputParser();

        self::assertSame(
            self::expectedResult(),
            $parser->invoke("```xml\n" . self::xml() . "\n```")
        );
    }

    public function testCanFormatInstructionsWithPassedTags(): void
    {
        $parser = new XmlOutputParser(['tags' => ['tag1', 'tag2', 'tag3']]);

        self::assertStringContainsString('tag1, tag2, tag3', $parser->getFormatInstructions());
    }

    public function testFormatInstructionsLeaveTheTagsPlaceholderWhenNoTagsGiven(): void
    {
        self::assertStringContainsString(
            '{tags}',
            (new XmlOutputParser())->getFormatInstructions()
        );
    }

    public function testCanParseStreams(): void
    {
        $parser = new XmlOutputParser();

        $chunks = [];
        foreach ($parser->transform([self::xml()]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertNotSame([], $chunks);
        self::assertSame(self::expectedResult(), $chunks[count($chunks) - 1]);
    }

    public function testRejectsMismatchedTags(): void
    {
        $this->expectException(\RuntimeException::class);

        XmlOutputParser::parseXmlMarkdown('<a><b></c></a>');
    }
}
