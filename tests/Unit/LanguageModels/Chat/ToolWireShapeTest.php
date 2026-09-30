<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\{DynamicStructuredTool, Schema};
use LangChain\Utils\Http\{HttpClient, HttpException, HttpResponse};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every route a tool can take to the wire must produce the PROVIDER's shape.
 *
 * `bindTools()` was already covered, and the tests for it all went through it.
 * Two other routes existed and neither converted anything:
 *
 *   * tools handed to the OpenAI client's CONSTRUCTOR land in `kwargs` raw;
 *   * tools passed PER CALL to the Anthropic client are picked straight out of
 *     the options.
 *
 * Both sent a serialised PHP object as the request body:
 *
 *     [{"lc":1,"type":"constructor","id":["langchain","tools",
 *       "DynamicStructuredTool"],"kwargs":[]}]
 *
 * Note what is missing from that: the tool's name, its description and its
 * schema. Not a malformed tool — no tool at all. And nothing errored, because
 * nothing between the call and `json_encode` objected.
 *
 * Every test here asserts on the decoded request BODY. Asserting that
 * `bindTools` returns a new instance, or that `kwargs` holds something, proved
 * nothing about either defect.
 */
#[CoversClass(ChatOpenAI::class)]
#[CoversClass(ChatAnthropic::class)]
final class ToolWireShapeTest extends TestCase
{
    private function tool(): DynamicStructuredTool
    {
        return new DynamicStructuredTool(
            [
                'name' => 'search',
                'description' => 'search things',
                'schema' => Schema::object(['q' => Schema::string()], ['q']),
            ],
            static fn (array $in): string => 'x',
        );
    }

    private static function client(bool $anthropic, array &$bodies): HttpClient
    {
        return new class ($anthropic, $bodies) implements HttpClient {
            /** @param list<string> $bodies */
            public function __construct(private bool $anthropic, private array &$bodies)
            {
            }

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                $this->bodies[] = $body;
                $payload = $this->anthropic
                    ? ['id' => 'x', 'model' => 'm', 'content' => [['type' => 'text', 'text' => 'ok']],
                       'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]
                    : ['id' => 'x', 'model' => 'm',
                       'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok']]]];

                return new HttpResponse(200, [], json_encode($payload) ?: '{}');
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield;
            }
        };
    }

    /** @return array<string, mixed> */
    private static function decodeBody(array $bodies, int $i = 0): array
    {
        self::assertArrayHasKey($i, $bodies, 'no request was sent');
        $decoded = json_decode($bodies[$i], true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testConstructorToolsAreConvertedForOpenAI(): void
    {
        $bodies = [];
        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => self::client(false, $bodies), 'tools' => [$this->tool()]]);
        $model->invoke([new HumanMessage('hi')]);

        $tools = self::decodeBody($bodies)['tools'] ?? null;
        self::assertIsArray($tools);
        self::assertSame('function', $tools[0]['type'] ?? null, 'a raw PHP object reached the provider');
        self::assertSame('search', $tools[0]['function']['name'] ?? null);
        self::assertSame('search things', $tools[0]['function']['description'] ?? null);
        self::assertSame(
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
            $tools[0]['function']['parameters'] ?? null,
        );
    }

    public function testPerCallToolsAreConvertedForAnthropic(): void
    {
        $bodies = [];
        $model = new ChatAnthropic(['apiKey' => 'k', 'httpClient' => self::client(true, $bodies)]);
        $model->invoke(
            [new HumanMessage('hi')],
            new RunnableConfig(options: ['tools' => [$this->tool()]]),
        );

        $tools = self::decodeBody($bodies)['tools'] ?? null;
        self::assertIsArray($tools);
        self::assertSame('search', $tools[0]['name'] ?? null, 'a raw PHP object reached the provider');
        self::assertSame('search things', $tools[0]['description'] ?? null);
        self::assertSame(
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
            $tools[0]['input_schema'] ?? null,
        );
    }

    public function testConstructorToolsAreConvertedForAnthropic(): void
    {
        $bodies = [];
        $model = new ChatAnthropic(['apiKey' => 'k', 'httpClient' => self::client(true, $bodies), 'tools' => [$this->tool()]]);
        $model->invoke([new HumanMessage('hi')]);

        $tools = self::decodeBody($bodies)['tools'] ?? null;
        self::assertIsArray($tools);
        self::assertSame('search', $tools[0]['name'] ?? null);
    }

    /**
     * The bound route must keep working, and must not be double-converted.
     *
     * `convertAll()`/`convertTool()` pass an already-shaped array through, which
     * is what makes it safe for `invocationParams()` to convert the bound tools
     * again on every call. This pins that, because the alternative — a
     * double-conversion that mangles the shape — would look like a fix.
     */
    public function testTheBoundRouteIsUnchangedAndNotDoubleConverted(): void
    {
        $bodies = [];
        $model = (new ChatOpenAI(['apiKey' => 'k', 'httpClient' => self::client(false, $bodies)]))->bindTools([$this->tool()]);

        $model->invoke([new HumanMessage('one')]);
        $model->invoke([new HumanMessage('two')]);

        self::assertCount(2, $bodies, 'the bound tools must survive to the second call');
        foreach ([0, 1] as $i) {
            $tools = self::decodeBody($bodies, $i)['tools'] ?? null;
            self::assertIsArray($tools);
            self::assertSame('search', $tools[0]['function']['name'] ?? null, "call $i");
            self::assertArrayNotHasKey('function', $tools[1] ?? [], 'no second tool appeared');
        }
    }

    /**
     * A tool already given in the provider's own shape is left alone.
     *
     * The conversion is applied to every route now, so it has to be a no-op for
     * a caller who built the shape themselves.
     */
    public function testAnAlreadyShapedToolIsPassedThroughUnchanged(): void
    {
        $bodies = [];
        $shaped = ['type' => 'function', 'function' => [
            'name' => 'manual', 'description' => 'hand built',
            'parameters' => ['type' => 'object', 'properties' => ['z' => ['type' => 'string']]],
        ]];

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => self::client(false, $bodies), 'tools' => [$shaped]]);
        $model->invoke([new HumanMessage('hi')]);

        $tools = self::decodeBody($bodies)['tools'] ?? null;
        self::assertSame($shaped, $tools[0] ?? null, 'a hand-built tool must survive conversion untouched');
    }
}
