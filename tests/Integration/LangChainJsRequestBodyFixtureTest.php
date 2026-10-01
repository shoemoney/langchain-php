<?php

declare(strict_types=1);

namespace LangChain\Tests\Integration;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\Schema;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The request bodies LangChain JS actually sent, asserted against ours.
 *
 * Same reasoning as {@see LangGraphJsCheckpointFixtureTest}: a PHP round trip is a
 * symmetric oracle and cannot see a shape that is wrong in both directions at
 * once. `tests/Fixtures/langchain/request-bodies.json` was produced by RUNNING
 * `@langchain/openai` and `@langchain/anthropic` under Node with `fetch`
 * intercepted, so the bodies are what those clients put on the wire rather than
 * what anyone read off their source. Package versions are recorded in the fixture
 * and asserted here, because an oracle whose provenance drifts stops being an
 * oracle.
 *
 * WHAT IT CAUGHT. `Schema::string()` took no parameters, so
 * `Schema::string(['description' => 'The city to look up'])` declared a description
 * that PHP then silently discarded — a userland function called with too many
 * arguments does not raise, it ignores them. The tool reached the wire with
 * `{"type":"string"}` and no description, telling the model the argument existed
 * and nothing about what it meant. The raw-array spelling of the same property
 * always worked, which is exactly why it was invisible: two spellings of one
 * schema, two different wire outputs, and only the factory spelling was broken.
 *
 * The envelope shapes themselves were already correct and are now pinned against
 * real bytes rather than against a hand-written expectation: OpenAI nests the
 * schema under `function.parameters`, Anthropic under `input_schema`. Those are
 * the exact two shapes this port's ledger records having got wrong before.
 */
final class LangChainJsRequestBodyFixtureTest extends TestCase
{
    private const FIXTURE = 'tests/Fixtures/langchain/request-bodies.json';

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 2) . '/' . self::FIXTURE;
        self::assertFileExists($path, 'the JS oracle is the whole point of this test');

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testTheFixtureDeclaresItsProvenanceAndVersions(): void
    {
        $fixture = $this->fixture();

        self::assertArrayHasKey('_provenance', $fixture);
        self::assertStringContainsString('RUNNING', (string) $fixture['_provenance']);
        self::assertSame(
            ['@langchain/core', '@langchain/openai', '@langchain/anthropic'],
            array_keys((array) $fixture['versions']),
            'every package the oracle depends on must be versioned, or the oracle rots silently',
        );
        self::assertArrayHasKey('plain', $fixture['cases']);
        self::assertArrayHasKey('tool_bound', $fixture['cases']);
    }

    /** The same tool the JS fixture describes, built through the port's factories. */
    private function tool(): DynamicStructuredTool
    {
        return new DynamicStructuredTool(
            [
                'name' => 'get_weather',
                'description' => 'Get the weather for a city',
                'schema' => Schema::object(
                    [
                        'city' => Schema::string(['description' => 'The city to look up']),
                        'units' => ['description' => 'Temperature units', 'type' => 'string', 'enum' => ['c', 'f']],
                    ],
                    ['city'],
                ),
            ],
            static fn (array $in): string => 'sunny',
        );
    }

    /**
     * The request body this port builds, for a plain call and for a tool-bound call.
     *
     * @return array<string, mixed>
     */
    private function portBody(bool $anthropic, bool $withTool): array
    {
        $http = new FakeHttpClient();

        if ($anthropic) {
            $model = new ChatAnthropic([
                'apiKey' => 'k',
                'model' => 'claude-3-5-sonnet-latest',
                'maxTokens' => 1024,
                'httpClient' => $http,
            ]);
        } else {
            $model = new ChatOpenAI(['apiKey' => 'k', 'model' => 'gpt-4o', 'httpClient' => $http]);
        }

        // `bindTools()` returns a CLONE, so the bound instance is what must be
        // invoked — invoking the original silently sends no tools, which is the
        // shape this test exists to distinguish from a genuine empty request.
        if ($withTool) {
            $model = $model->bindTools([$this->tool()]);
        }

        try {
            $model->invoke('weather in Paris?');
        } catch (\Throwable) {
            // The fake transport has no response scripted; we only want the request.
        }

        $body = $http->requests[0]['body'] ?? null;
        self::assertIsString($body, 'the client must have attempted a request');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The tool property must carry its description — the defect this oracle found.
     *
     * Asserted against the JS bytes for the same property rather than a literal, so
     * a fixture regenerated from a newer client that renames the key fails here
     * instead of passing against a stale constant.
     */
    public function testTheToolPropertyCarriesItsDescriptionOnBothProviders(): void
    {
        $fixture = $this->fixture();

        foreach ([false => 'openai', true => 'anthropic'] as $anthropic => $provider) {
            $jsProperty = $this->jsToolProperty($fixture, $provider);
            self::assertSame(
                ['type' => 'string', 'description' => 'The city to look up'],
                $jsProperty,
                "the {$provider} oracle's shape for `city` changed; re-check this port's spelling",
            );

            $portTools = $this->portBody((bool) $anthropic, true)['tools'] ?? [];
            self::assertNotSame([], $portTools, "{$provider}: bindTools() must actually reach the request");

            $portProperty = $anthropic
                ? $portTools[0]['input_schema']['properties']['city']
                : $portTools[0]['function']['parameters']['properties']['city'];

            // assertEqualsCanonicalizing, not assertSame: JSON object key ORDER is not
            // part of the wire contract, and the two clients happen to emit different
            // orders. assertSame would fail on a difference no provider cares about.
            self::assertEqualsCanonicalizing(
                $jsProperty,
                $portProperty,
                "{$provider}: the tool property must reach the wire with the same shape JS sends. "
                . 'A description dropped here tells the model the argument exists and nothing '
                . 'about what it means.',
            );
        }
    }

    /**
     * Both providers keep their own envelope — the two shapes this port has got wrong.
     *
     * OpenAI nests under `function.parameters` inside a `type: function` wrapper;
     * Anthropic uses `input_schema` directly. Confusing them is a recorded defect:
     * `ChatAnthropic` once read `parameters` off the outer tool array and sent
     * `input_schema: {type: object, properties: {}}`, so the model was never told what
     * arguments the tool took, with no error anywhere.
     */
    public function testEachProviderKeepsItsOwnEnvelope(): void
    {
        $openai = $this->portBody(false, true)['tools'][0];
        self::assertSame('function', $openai['type']);
        self::assertArrayHasKey('parameters', $openai['function']);
        self::assertArrayNotHasKey('input_schema', $openai, 'OpenAI must not receive Anthropic\'s envelope');
        self::assertArrayNotHasKey('input_schema', $openai['function']);

        $anthropic = $this->portBody(true, true)['tools'][0];
        self::assertArrayHasKey('input_schema', $anthropic);
        self::assertArrayNotHasKey('function', $anthropic, 'Anthropic must not receive OpenAI\'s envelope');
        self::assertSame('get_weather', $anthropic['name'] ?? null, 'the tool name is top-level for Anthropic');
    }

    /**
     * The schema must be an OBJECT TYPE with the right required list.
     *
     * The failure mode recorded in this ledger is an empty schema reaching the
     * provider: `input_schema: {type: object, properties: {}}` validates nothing,
     * so arguments violating the contract reach the tool body.
     */
    public function testTheSchemaIsAPopulatedObjectType(): void
    {
        foreach ([false => 'openai', true => 'anthropic'] as $anthropic => $provider) {
            $tools = $this->portBody((bool) $anthropic, true)['tools'] ?? [];
            $schema = $anthropic
                ? $tools[0]['input_schema']
                : $tools[0]['function']['parameters'];

            self::assertSame('object', $schema['type'] ?? null, "{$provider}: schema type");
            self::assertSame(
                ['city'],
                $schema['required'] ?? null,
                "{$provider}: only `city` is required in the oracle's tool",
            );
            self::assertSame(
                ['city', 'units'],
                array_keys($schema['properties'] ?? []),
                "{$provider}: both properties must be declared, not an empty properties map",
            );
        }
    }

    /**
     * `Schema::string()` must merge keywords rather than discard them.
     *
     * The regression guard for the defect itself. PHP does not raise when a
     * userland function receives too many arguments — it ignores them — so a
     * factory with an empty signature turns a caller's description into silence,
     * and the only symptom is a request body missing a field.
     */
    public function testStringFactoryMergesKeywordsInsteadOfDiscardingThem(): void
    {
        self::assertSame(
            ['type' => 'string', 'description' => 'The city to look up'],
            Schema::string(['description' => 'The city to look up'])->toJsonSchema(),
            'a description passed to Schema::string() must survive to the JSON schema',
        );

        self::assertSame(
            ['type' => 'string'],
            Schema::string()->toJsonSchema(),
            'the bare factory must be unchanged — no arguments, no extra keys',
        );

        // `type` is the factory's entire claim, so it cannot be overridden. The
        // left-hand side of `+` wins, which is what makes that true.
        self::assertSame(
            'string',
            Schema::string(['type' => 'number'])->toJsonSchema()['type'],
            'Schema::string() must still declare a string even if `type` is passed',
        );
    }

    /**
     * The tool name and description must be where each provider expects them.
     */
    public function testToolNameAndDescriptionLandWhereEachProviderReadsThem(): void
    {
        $fixture = $this->fixture();

        $jsOpenAI = $fixture['cases']['tool_bound']['openai']['body']['tools'][0];
        $portOpenAI = $this->portBody(false, true)['tools'][0];

        self::assertSame(
            $jsOpenAI['function']['name'],
            $portOpenAI['function']['name'],
            'OpenAI tool name',
        );
        self::assertSame(
            $jsOpenAI['function']['description'],
            $portOpenAI['function']['description'],
            'OpenAI tool description — this is what tells the model the tool exists and does what',
        );

        $jsAnthropic = $fixture['cases']['tool_bound']['anthropic']['body']['tools'][0];
        $portAnthropic = $this->portBody(true, true)['tools'][0];

        self::assertSame($jsAnthropic['name'], $portAnthropic['name'], 'Anthropic tool name');
        self::assertSame(
            $jsAnthropic['description'],
            $portAnthropic['description'],
            'Anthropic tool description',
        );
    }

    /**
     * `max_tokens` must be present and numeric for Anthropic.
     *
     * Anthropic requires it; omitting it is a 400 from the provider, so this is the
     * one request-body field that is not merely a fidelity question.
     */
    public function testAnthropicSendsMaxTokens(): void
    {
        $port = $this->portBody(true, false);

        self::assertArrayHasKey('max_tokens', $port);
        self::assertIsInt($port['max_tokens']);
        self::assertSame(
            $this->fixture()['cases']['plain']['anthropic']['body']['max_tokens'],
            $port['max_tokens'],
            'max_tokens must match the value the JS client sends for the same configuration',
        );
    }

    /**
     * The `city` property exactly as the JS oracle sent it.
     *
     * Returned verbatim, with no normalisation: the caller compares it against what
     * this port sends, so any transformation here would be a place for the oracle
     * to be quietly reshaped into agreement.
     *
     * @param array<string, mixed> $fixture
     *
     * @return array<string, mixed>
     */
    private function jsToolProperty(array $fixture, string $provider): array
    {
        $tool = $fixture['cases']['tool_bound'][$provider]['body']['tools'][0];
        $schema = $provider === 'anthropic' ? $tool['input_schema'] : $tool['function']['parameters'];

        /** @var array<string, mixed> $city */
        $city = $schema['properties']['city'];

        return $city;
    }
}