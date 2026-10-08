<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesTools;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Tools;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Responses-facing half of upstream `utils/tools.ts`.
 *
 * Upstream has no unit file for these helpers: they are exercised through the
 * streaming and round-trip cases in `responses.test.ts` (the `isCustomTool`
 * markers) and through `chat_models`. These tests pin each predicate, parser and
 * converter directly, and assert on the encoded JSON where a PHP `[]` could hide
 * an `{}`.
 */
#[CoversClass(ResponsesTools::class)]
final class ResponsesToolsTest extends TestCase
{
    use AssertsWireJson;

    private static function searchTool(array $extras = [], array $metadata = []): DynamicStructuredTool
    {
        return new DynamicStructuredTool(
            [
                'name' => 'search',
                'description' => 'search things',
                'schema' => Schema::object(['q' => Schema::string()], ['q']),
                'extras' => $extras,
                'metadata' => $metadata,
            ],
            static fn (array $in): string => 'x',
        );
    }

    private static function noArgTool(): DynamicStructuredTool
    {
        return new DynamicStructuredTool(
            ['name' => 'ping', 'description' => 'ping', 'schema' => Schema::object([], [])],
            static fn (array $in): string => 'pong',
        );
    }

    public function testIsBuiltInToolIsAnyTypedToolThatIsNotAFunction(): void
    {
        self::assertTrue(ResponsesTools::isBuiltInTool(['type' => 'web_search_preview']));
        self::assertTrue(ResponsesTools::isBuiltInTool(['type' => 'custom', 'name' => 'x']));
        self::assertFalse(ResponsesTools::isBuiltInTool(['type' => 'function', 'function' => ['name' => 'x']]));
        self::assertFalse(ResponsesTools::isBuiltInTool(self::searchTool()));
        self::assertFalse(ResponsesTools::isBuiltInTool(['name' => 'untyped']));
    }

    public function testIsBuiltInToolChoiceIsATypedChoiceThatIsNotAFunction(): void
    {
        self::assertTrue(ResponsesTools::isBuiltInToolChoice(['type' => 'web_search_preview']));
        self::assertFalse(ResponsesTools::isBuiltInToolChoice(['type' => 'function', 'name' => 'x']));
        self::assertFalse(ResponsesTools::isBuiltInToolChoice('auto'));
        self::assertFalse(ResponsesTools::isBuiltInToolChoice(null));
    }

    public function testHasProviderToolDefinitionReadsExtras(): void
    {
        $definition = ['type' => 'shell'];

        self::assertTrue(ResponsesTools::hasProviderToolDefinition(self::searchTool(['providerToolDefinition' => $definition])));
        self::assertTrue(ResponsesTools::hasProviderToolDefinition(['extras' => ['providerToolDefinition' => $definition]]));
        self::assertFalse(ResponsesTools::hasProviderToolDefinition(self::searchTool()));
        self::assertFalse(ResponsesTools::hasProviderToolDefinition(self::searchTool(['providerToolDefinition' => null])));
        self::assertFalse(ResponsesTools::hasProviderToolDefinition('shell'));
        self::assertSame($definition, ResponsesTools::providerToolDefinition(self::searchTool(['providerToolDefinition' => $definition])));
    }

    public function testIsCustomToolReadsMetadata(): void
    {
        $custom = ['type' => 'custom', 'name' => 'apply_patch'];

        self::assertTrue(ResponsesTools::isCustomTool(self::searchTool([], ['customTool' => $custom])));
        self::assertTrue(ResponsesTools::isCustomTool(['metadata' => ['customTool' => $custom]]));
        self::assertFalse(ResponsesTools::isCustomTool(self::searchTool()));
        self::assertFalse(ResponsesTools::isCustomTool(self::searchTool([], ['customTool' => 'x'])));
    }

    public function testIsOpenAICustomToolNeedsATypeAndACustomObject(): void
    {
        self::assertTrue(ResponsesTools::isOpenAICustomTool(['type' => 'custom', 'custom' => ['name' => 'x']]));
        self::assertFalse(ResponsesTools::isOpenAICustomTool(['type' => 'custom', 'name' => 'x']));
        self::assertFalse(ResponsesTools::isOpenAICustomTool(['type' => 'function', 'custom' => ['name' => 'x']]));
    }

    public function testParseCustomToolCallSwapsTheTwoIds(): void
    {
        $raw = ['type' => 'custom_tool_call', 'id' => 'ctc_1', 'call_id' => 'call_1', 'name' => 'apply', 'input' => 'diff'];

        $parsed = ResponsesTools::parseCustomToolCall($raw);

        self::assertSame('tool_call', $parsed['type']);
        self::assertSame('call_1', $parsed['id'], 'the LangChain id is the API call_id');
        self::assertSame('ctc_1', $parsed['call_id']);
        self::assertTrue($parsed['isCustomTool']);
        self::assertSame(['input' => 'diff'], $parsed['args']);
        self::assertNull(ResponsesTools::parseCustomToolCall(['type' => 'function_call']));
    }

    public function testParseComputerCallBuildsAComputerUseToolCall(): void
    {
        $raw = ['type' => 'computer_call', 'id' => 'cu_1', 'call_id' => 'call_k', 'action' => ['type' => 'click']];

        $parsed = ResponsesTools::parseComputerCall($raw);

        self::assertSame('computer_use', $parsed['name']);
        self::assertSame('call_k', $parsed['id']);
        self::assertSame('cu_1', $parsed['call_id']);
        self::assertSame(['action' => ['type' => 'click']], $parsed['args']);
        self::assertTrue(ResponsesTools::isComputerToolCall($parsed));
        self::assertFalse(ResponsesTools::isComputerToolCall($raw));
        self::assertNull(ResponsesTools::parseComputerCall(['type' => 'function_call']));
    }

    public function testIsCustomToolCallAcceptsTheMarkerOrAnIdInTheRecordedMap(): void
    {
        self::assertTrue(ResponsesTools::isCustomToolCall(['type' => 'tool_call', 'id' => 'a', 'isCustomTool' => true]));
        self::assertTrue(ResponsesTools::isCustomToolCall(['type' => 'tool_call', 'id' => 'a'], ['a' => 'ctc_a']));
        self::assertFalse(ResponsesTools::isCustomToolCall(['type' => 'tool_call', 'id' => 'b'], ['a' => 'ctc_a']));
        self::assertFalse(ResponsesTools::isCustomToolCall(['type' => 'tool_call', 'id' => 'b']));
        self::assertFalse(ResponsesTools::isCustomToolCall(['id' => 'a', 'isCustomTool' => true]));
    }

    public function testCustomToolFormatsConvertBothWays(): void
    {
        $completions = [
            'type' => 'custom',
            'custom' => [
                'name' => 'sql',
                'description' => 'run sql',
                'format' => ['type' => 'grammar', 'grammar' => ['definition' => 'start: x', 'syntax' => 'lark']],
            ],
        ];
        $responses = [
            'type' => 'custom',
            'name' => 'sql',
            'description' => 'run sql',
            'format' => ['type' => 'grammar', 'definition' => 'start: x', 'syntax' => 'lark'],
        ];

        self::assertWire($responses, ResponsesTools::convertCompletionsCustomTool($completions));
        self::assertWire($completions, ResponsesTools::convertResponsesCustomTool($responses));

        // An absent description and a text format: undefined members are omitted, not nulled.
        self::assertWire(
            ['type' => 'custom', 'name' => 't', 'format' => ['type' => 'text']],
            ResponsesTools::convertCompletionsCustomTool(['type' => 'custom', 'custom' => ['name' => 't', 'format' => ['type' => 'text']]]),
        );
        self::assertWire(
            ['type' => 'custom', 'custom' => ['name' => 't']],
            ResponsesTools::convertResponsesCustomTool(['type' => 'custom', 'name' => 't']),
        );
    }

    public function testReduceFlattensAFunctionToolAndKeepsExtraKeys(): void
    {
        $chatTool = [
            'type' => 'function',
            'function' => ['name' => 'get_weather', 'description' => 'weather', 'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]],
            'defer_loading' => true,
        ];

        self::assertWire([[
            'type' => 'function',
            'name' => 'get_weather',
            'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            'description' => 'weather',
            'strict' => true,
            'defer_loading' => true,
        ]], ResponsesTools::reduceTools([$chatTool], false, true));
    }

    public function testReduceLetsTopLevelExtraKeysOverrideComputedOnes(): void
    {
        $reduced = ResponsesTools::reduceTools([
            ['type' => 'function', 'function' => ['name' => 'f', 'parameters' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]], 'strict' => true, 'name' => 'renamed'],
        ]);

        self::assertTrue($reduced[0]['strict']);
        self::assertSame('renamed', $reduced[0]['name']);
    }

    public function testReduceLeavesStrictNullWhenUnspecified(): void
    {
        $reduced = ResponsesTools::reduceTools([
            ['type' => 'function', 'function' => ['name' => 'f', 'parameters' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]],
        ]);

        self::assertStringContainsString('"strict":null', json_encode($reduced));
    }

    public function testReduceRendersALangChainToolThroughTheCompletionsConverterThenFlattens(): void
    {
        $tool = self::searchTool();

        $reduced = ResponsesTools::reduceTools([$tool]);
        $chat = Tools::convert($tool);

        self::assertSame('function', $reduced[0]['type']);
        self::assertSame($chat['function']['name'], $reduced[0]['name']);
        self::assertSame($chat['function']['description'], $reduced[0]['description']);
        self::assertSame($chat['function']['parameters'], $reduced[0]['parameters']);
        self::assertArrayNotHasKey('function', $reduced[0]);
    }

    public function testReduceKeepsANoArgumentToolsPropertiesAnObject(): void
    {
        // The no-argument-tool bug class: `properties: []` tells the provider the tool takes a list.
        $reduced = ResponsesTools::reduceTools([self::noArgTool()]);

        self::assertStringContainsString('"properties":{}', json_encode($reduced));
        self::assertStringNotContainsString('"properties":[]', json_encode($reduced));
    }

    public function testReducePassesBuiltInsAndProviderDefinitionsAndCustomToolsThrough(): void
    {
        $reduced = ResponsesTools::reduceTools([
            ['type' => 'web_search_preview'],
            self::searchTool(['providerToolDefinition' => ['type' => 'shell', 'environment' => ['type' => 'local']]]),
            self::searchTool([], ['customTool' => ['type' => 'custom', 'name' => 'apply', 'description' => 'patch', 'format' => ['type' => 'text']]]),
            ['type' => 'custom', 'custom' => ['name' => 'sql']],
            'not a tool',
        ]);

        self::assertWire([
            ['type' => 'web_search_preview'],
            ['type' => 'shell', 'environment' => ['type' => 'local']],
            ['type' => 'custom', 'name' => 'apply', 'description' => 'patch', 'format' => ['type' => 'text']],
            // Faithful to upstream: `isBuiltInTool` (any non-function type) is tested first, so a Chat
            // Completions `{type: custom, custom: {...}}` tool is passed through and the
            // `isOpenAICustomTool` branch of `_reduceChatOpenAITools` is unreachable.
            ['type' => 'custom', 'custom' => ['name' => 'sql']],
        ], $reduced);
    }

    public function testReduceForcesPartialImagesOnAStreamedImageGenerationTool(): void
    {
        self::assertSame(
            [['type' => 'image_generation', 'partial_images' => 1]],
            ResponsesTools::reduceTools([['type' => 'image_generation']], true),
        );
        self::assertSame(
            [['type' => 'image_generation']],
            ResponsesTools::reduceTools([['type' => 'image_generation']], false),
        );
    }

    public function testFormatToolChoiceFlattensFunctionChoicesAndPassesBuiltInsThrough(): void
    {
        self::assertNull(ResponsesTools::formatToolChoice('any'));
        self::assertNull(ResponsesTools::formatToolChoice('required'));
        self::assertNull(ResponsesTools::formatToolChoice('auto'));
        self::assertNull(ResponsesTools::formatToolChoice('none'));
        self::assertSame(['type' => 'function', 'name' => 'get_weather'], ResponsesTools::formatToolChoice('get_weather'));
        self::assertSame(
            ['type' => 'function', 'name' => 'get_weather'],
            ResponsesTools::formatToolChoice(['type' => 'function', 'function' => ['name' => 'get_weather']]),
        );
        self::assertSame(
            ['type' => 'web_search_preview'],
            ResponsesTools::formatToolChoice(['type' => 'web_search_preview']),
        );
    }
}
