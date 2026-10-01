<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A parameter the client cannot send must be REFUSED, at every layer.
 *
 * `topK` is a real Anthropic parameter that Chat Completions has no equivalent
 * for. The first version of this guard lived in the constructor only, so a bound
 * or per-call `topK` was accepted, stored in `kwargs`, serialised into every
 * trace — and then dropped from the request. The port's own status ledger claimed
 * it was "refused under both spellings" while only one of three layers was
 * covered. A test that exercised the constructor therefore passed against code
 * that was still silently swallowing the other two.
 */
#[CoversClass(ChatOpenAI::class)]
final class UnsupportedParameterTest extends TestCase
{
    /** @return iterable<string, array{class-string<ChatOpenAI|ChatAnthropic>}> */
    public static function clients(): iterable
    {
        yield 'openai' => [ChatOpenAI::class];
        yield 'anthropic' => [\LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic::class];
    }

    /** @return iterable<string, array{string}> */
    public static function topKSpellings(): iterable
    {
        yield 'camelCase' => ['topK'];
        yield 'wire' => ['top_k'];
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function layers(): iterable
    {
        yield 'constructor' => [static fn (string $k): mixed => new ChatOpenAI(['apiKey' => 'k', $k => 0.25])];
        yield 'bindTools' => [static fn (string $k): mixed => (new ChatOpenAI(['apiKey' => 'k']))->bindTools([], [$k => 0.25])];
        yield 'invocationParams' => [static fn (string $k): mixed => (new ChatOpenAI(['apiKey' => 'k']))->invocationParams([$k => 0.25])];
    }

    #[DataProvider('layers')]
    public function testTheConstructorLayerRefuses(callable $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no topK parameter');

        $make('topK');
    }

    /**
     * The wire spelling must be refused identically.
     *
     * This is the half that was missed. Rejecting the canonical name while
     * dropping the wire spelling is two spellings of one mistake, one loud and
     * one quiet — and the quiet one is the one a caller using the HTTP field
     * name will hit.
     */
    #[DataProvider('layers')]
    public function testEveryLayerRefusesTheWireSpellingToo(callable $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no topK parameter');

        $make('top_k');
    }

    /**
     * Refusing must not break the parameters this client does support.
     */
    public function testSupportedParametersStillWork(): void
    {
        $model = new ChatOpenAI(['apiKey' => 'k', 'topP' => 0.5, 'maxTokens' => 128]);
        $params = $model->invocationParams();

        self::assertSame(0.5, $params['top_p']);
        self::assertSame(128, $params['max_tokens']);
        self::assertArrayNotHasKey('top_k', $params);
    }

    /**
     * An empty model name is refused at construction.
     *
     * The TypeScript original gets this from its type system; PHP has no
     * equivalent, so without an explicit check an empty name goes out on the
     * wire and the provider answers 400 about the *request* rather than about
     * the constructor, which points the reader at the wrong place entirely.
     */
    #[DataProvider('clients')]
    public function testAnEmptyModelNameIsRefused(string $client): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty model name');

        new $client(['apiKey' => 'k', 'model' => '']);
    }

    #[DataProvider('clients')]
    public function testAWhitespaceModelNameIsRefused(string $client): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new $client(['apiKey' => 'k', 'model' => "   "]);
    }

    /**
     * The check must not reject a real model, or the default.
     */
    #[DataProvider('clients')]
    public function testAValidModelIsAccepted(string $client): void
    {
        $model = new $client(['apiKey' => 'k', 'model' => 'some-real-model']);

        self::assertSame('some-real-model', $model->model);
    }

    #[DataProvider('clients')]
    public function testTheDefaultModelIsAccepted(string $client): void
    {
        $model = new $client(['apiKey' => 'k']);

        self::assertNotSame('', trim($model->model));
    }

    /**
     * A bound `strict` must reach the rendered tool definition.
     *
     * It used to be stored in `kwargs` and read by nothing: the call appeared
     * to configure strict tool calling and configured nothing. `strict` is a
     * rendering decision — it is baked into each tool definition — so the only
     * place it can take effect is the rendered output.
     */
    public function testABoundStrictReachesTheRenderedTool(): void
    {
        $tool = \LangChain\Tools\tool(static fn (array $a): string => 'x', [
            'name' => 't',
            'description' => 'd',
            'schema' => \LangChain\Tools\Schema::object([]),
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k']);
        $bound = $model->bindTools([$tool], ['strict' => true]);

        self::assertTrue($bound->kwargs()['tools'][0]['function']['strict'] ?? null);
        self::assertTrue($bound->invocationParams()['tools'][0]['function']['strict'] ?? null);
    }

    /**
     * A `strict` established by one bind survives a second bind on it.
     *
     * The bound instance kept `supportsStrictToolCalling` as null, so chaining
     * a bind silently dropped strictness the first one had set.
     */
    public function testStrictSurvivesAChainedBind(): void
    {
        $tool = \LangChain\Tools\tool(static fn (array $a): string => 'x', [
            'name' => 't', 'description' => 'd', 'schema' => \LangChain\Tools\Schema::object([]),
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k']);
        $second = $model->bindTools([$tool], ['strict' => true])->bindTools([$tool]);

        self::assertTrue($second->kwargs()['tools'][0]['function']['strict'] ?? null);
    }

    public function testNoStrictMeansNoStrictKey(): void
    {
        $tool = \LangChain\Tools\tool(static fn (array $a): string => 'x', [
            'name' => 't',
            'description' => 'd',
            'schema' => \LangChain\Tools\Schema::object([]),
        ]);

        $bound = (new ChatOpenAI(['apiKey' => 'k']))->bindTools([$tool]);

        self::assertArrayNotHasKey('strict', $bound->kwargs()['tools'][0]['function']);
    }

    /**
     * A constructor-level `strict` still applies to bound tools.
     */
    public function testAConstructorStrictAppliesToBoundTools(): void
    {
        $tool = \LangChain\Tools\tool(static fn (array $a): string => 'x', [
            'name' => 't',
            'description' => 'd',
            'schema' => \LangChain\Tools\Schema::object([]),
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'supportsStrictToolCalling' => true]);
        $bound = $model->bindTools([$tool]);

        self::assertTrue($bound->kwargs()['tools'][0]['function']['strict'] ?? null);
    }

    /**
     * A `strict` established by one bind survives a second bind, on Anthropic
     * too — upstream passes it through `withConfig`, so it persists the same way
     * the OpenAI client's does.
     */
    public function testStrictSurvivesAChainedBindOnAnthropic(): void
    {
        $tool = \LangChain\Tools\tool(static fn (array $a): string => 'x', [
            'name' => 't', 'description' => 'd', 'schema' => \LangChain\Tools\Schema::object([]),
        ]);

        $model = new \LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic(['apiKey' => 'k']);
        $second = $model->bindTools([$tool], ['strict' => true])->bindTools([$tool]);

        self::assertTrue($second->kwargs()['tools'][0]['strict'] ?? null);
    }

    /**
     * A per-call `tools` is converted like a bound one.
     *
     * Passing a `StructuredTool` per call used to serialise it as a constructor
     * blob — `{"lc":1,"type":"constructor",...}` — and send THAT: a tool the
     * model cannot read, with no error anywhere. An already provider-shaped
     * array still passes through untouched, so the conversion cannot double-wrap.
     */
    public function testPerCallToolsAreConvertedLikeBoundOnes(): void
    {
        $tool = \LangChain\Tools\tool(static fn (array $a): string => 'x', [
            'name' => 't', 'description' => 'd', 'schema' => \LangChain\Tools\Schema::object([]),
        ]);

        $params = (new ChatOpenAI(['apiKey' => 'k']))->invocationParams(['tools' => [$tool]]);

        self::assertSame('function', $params['tools'][0]['type'] ?? null);
        self::assertSame('t', $params['tools'][0]['function']['name'] ?? null);
        self::assertArrayNotHasKey('lc', $params['tools'][0], 'no serialised constructor blob on the wire');
    }

    public function testPerCallProviderShapedToolsPassThroughUntouched(): void
    {
        $native = ['type' => 'function', 'function' => ['name' => 'n', 'parameters' => ['type' => 'object']]];

        $params = (new ChatOpenAI(['apiKey' => 'k']))->invocationParams(['tools' => [$native]]);

        self::assertSame($native, $params['tools'][0]);
    }

    public function testAnthropicStillAcceptsTopK(): void
    {
        // The other side of the same finding: `top_k` IS valid for Anthropic, so
        // a blanket ban would be wrong in the other direction.
        $params = (new ChatAnthropic(['apiKey' => 'k', 'topK' => 0.25]))->invocationParams();

        self::assertSame(0.25, $params['top_k']);
    }
}
