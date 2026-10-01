<?php

declare(strict_types=1);

namespace LangChain\Tests\Integration;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * SSE bytes fed to both clients, and the chunks each one emitted.
 *
 * The last piece of the §E oracle: the previous two fixtures pin what goes OUT
 * (checkpoint bytes, request bodies), and this pins what comes back — the
 * provider's wire format into the decoder and the chunk sequence out of it. The
 * provider bytes are literal in the fixture generator; the `emitted` array is what
 * the JavaScript clients actually produced when fed those bytes, so it is an
 * observation rather than a reading of their source.
 *
 * WHAT IT SETTLED, in both directions.
 *
 * **OpenAI matches exactly** — three chunks, `["Hel", "lo", ""]`, byte for byte
 * against the oracle. That is a real fidelity result on the streaming decode path,
 * which is where this project has recorded wire defects before (the SSE `data:`
 * field stripping every leading space instead of at most one).
 *
 * **Anthropic diverges, deliberately.** The oracle emits five chunks — two empty
 * ones before the text and one after — where this port emits two. That is not a
 * defect and not an accident: `BaseChatModel` drops metadata-only chunks, with a
 * comment claiming that yielding the trailing usage event "put a phantom EMPTY
 * message at the end of every streamed call". **The oracle confirms that claim**,
 * which is the thing worth having — the port asserted a behaviour of upstream
 * based on reading its source, and the recorded bytes now show it was right. An
 * empty `content_block_start` is metadata-only by the same test, so the two
 * LEADING empties are dropped as well.
 *
 * The divergence is therefore pinned as a divergence rather than closed. Emitting
 * the empty chunks would be *more* faithful and *worse* for every consumer that
 * renders or counts chunks, which is a trade-off to decide deliberately — not
 * something to change because a fixture said so.
 */
final class LangChainJsSseStreamFixtureTest extends TestCase
{
    private const FIXTURE = 'tests/Fixtures/langchain/sse-streams.json';

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 2) . '/' . self::FIXTURE;
        self::assertFileExists($path);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testTheFixtureDeclaresItsVersions(): void
    {
        $fixture = $this->fixture();

        self::assertStringContainsString('recorded verbatim', (string) $fixture['_provenance']);
        self::assertArrayHasKey('@langchain/openai', $fixture['versions']);
        self::assertArrayHasKey('@langchain/anthropic', $fixture['versions']);
    }

    /**
     * The provider bytes must be the real SSE wire format, not a summary.
     *
     * A fixture that stored the decoded events instead of the bytes could not catch
     * a parser defect at all — the parser is exactly what is under test, so feeding
     * it pre-parsed input would assert that the decoder agrees with itself.
     *
     * @return list<string>
     */
    private function providerBytes(string $provider): array
    {
        $raw = (string) $this->fixture()[$provider]['providerBytes'];

        self::assertStringContainsString('data: ', $raw, 'SSE frames are `data:`-prefixed');
        self::assertStringContainsString("\n\n", $raw, 'SSE frames are separated by a blank line');

        return array_values(array_filter(explode("\n\n", trim($raw)), static fn (string $b): bool => $b !== ''));
    }

    /** @return list<string> */
    private function oracleContents(string $provider): array
    {
        return array_map(
            static fn (array $c): ?string => $c['content'] ?? null,
            $this->fixture()[$provider]['emitted'],
        );
    }

    /**
     * @param list<string> $providerBytes
     *
     * @return list<string|null>
     */
    private function portContents(bool $anthropic, array $providerBytes): array
    {
        $http = new FakeHttpClient(streamChunks: [$providerBytes === [] ? '' : implode("\n\n", $providerBytes)]);

        $model = $anthropic
            ? new ChatAnthropic([
                'apiKey' => 'k',
                'model' => 'claude-3-5-sonnet-latest',
                'maxTokens' => 1024,
                'httpClient' => $http,
            ])
            : new ChatOpenAI(['apiKey' => 'k', 'model' => 'gpt-4o', 'httpClient' => $http]);

        $out = [];
        foreach ($model->stream('hi') as $tuple) {
            $out[] = $tuple[1]->content;
        }

        return $out;
    }

    /**
     * OpenAI's decode path must match the oracle chunk for chunk.
     *
     * The strongest assertion in this file: same count, same order, same content.
     * A decoder that merged two deltas, dropped the terminating frame, or split one
     * differently all fail here.
     */
    public function testOpenAIChunksMatchTheOracleExactly(): void
    {
        $port = $this->portContents(false, $this->providerBytes('openai'));
        $oracle = $this->oracleContents('openai');

        self::assertSame(
            ['Hel', 'lo', ''],
            $oracle,
            'the OpenAI oracle changed shape; re-derive this port against fresh bytes',
        );
        self::assertSame(
            $oracle,
            $port,
            'OpenAI must emit exactly what LangChain JS emits from the same provider bytes',
        );
    }

    /**
     * Anthropic's divergence is pinned, with the reason in the assertion.
     *
     * Written as an explicit expectation rather than `assertSame` on the oracle, so
     * the file states the trade-off instead of implying the port matches. If this
     * ever becomes `assertSame($oracle, $port)`, the empty-chunk suppression in
     * `BaseChatModel` was removed and the comment there needs rewriting too.
     */
    public function testAnthropicSuppressesTheMetadataOnlyChunksUpstreamEmits(): void
    {
        $port = $this->portContents(true, $this->providerBytes('anthropic'));
        $oracle = $this->oracleContents('anthropic');

        self::assertSame(
            ['', '', 'Hel', 'lo', ''],
            $oracle,
            'the Anthropic oracle changed shape; re-derive this port against fresh bytes',
        );

        self::assertSame(
            ['Hel', 'lo'],
            $port,
            'DELIBERATE DIVERGENCE. Upstream yields a metadata-only chunk for message_start, '
            . 'content_block_start and the terminating events, so a consumer that renders or counts '
            . 'chunks sees three phantom turns. BaseChatModel drops metadata-only chunks and its '
            . 'comment claims that; these recorded bytes are what confirm the claim. Text content is '
            . 'identical either way.',
        );

        // The point of the divergence: concatenation is unaffected.
        self::assertSame(
            implode('', array_map(strval(...), $oracle)),
            implode('', array_map(strval(...), $port)),
            'the TEXT a caller receives must be identical; only the empty frames differ',
        );
    }

    /**
     * Both providers must reassemble the answer the bytes encode.
     *
     * The property that actually matters to a caller, asserted for both so the
     * Anthropic divergence above cannot quietly become a lost token.
     */
    public function testBothProvidersReassembleTheStreamedText(): void
    {
        foreach ([false, true] as $anthropic) {
            $provider = $anthropic ? 'anthropic' : 'openai';
            $text = implode('', array_map(
                strval(...),
                array_filter(
                    $this->portContents($anthropic, $this->providerBytes($provider)),
                    static fn (?string $c): bool => $c !== null && $c !== '',
                ),
            ));

            self::assertSame('Hello', $text, "{$provider}: the streamed text must reassemble");
        }
    }

    /**
     * The fixture's provider bytes must round-trip through the port's own parser.
     *
     * Guards the fixture itself: if a future regeneration produced a stream the port
     * cannot parse at all, the assertions above would compare an empty list against
     * an oracle and one of them would fail for a reason that has nothing to do with
     * the decoder.
     */
    public function testThePortParsesEveryProviderFrameInTheFixture(): void
    {
        foreach (['openai', 'anthropic'] as $provider) {
            $frames = $this->providerBytes($provider);
            self::assertGreaterThanOrEqual(
                3,
                count($frames),
                "{$provider}: the fixture must contain a multi-frame stream, not a single event",
            );
        }
    }
}