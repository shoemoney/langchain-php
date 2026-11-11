<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Caches\BaseCache;
use LangChain\Caches\InMemoryCache;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\Load\ImportMap;
use LangChain\Load\Load;
use LangChain\Load\Serializable;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\HumanMessageChunk;
use LangChain\Messages\MessageUtils;
use LangChain\Messages\SystemMessage;
use LangChain\Schema\ChatPromptValue;
use LangChain\Schema\Document;
use LangChain\Schema\StringPromptValue;
use LangChain\Tracers\EventStreamCallbackHandler;
use LangChain\Tracers\Serialized;
use LangGraph\Channels\ValueSet;
use LangGraph\Checkpoint\Serde\LcConstructorLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exact shipped Human current-field bridge, not general Serializable or constructor parity.
 * Fixed canonical bytes are actual full-import LC source R4 oracle values at
 * 17b6bba8343bdd3f3dfc5c59578e0b72337ec740 (241 audited imports, no transport).
 * Source serializable.ts sha2f422908b4d447bcff8d143849f80dc14b4a65ee31fd972909ff30ad617ad612.
 * Noncanonical order, unknown/null/contentBlocks capture, caller defaults and nested []/{}
 * remain typed PHP adaptations. Stored-cache reserialization retains PHP single escaping.
 * Inherited SerializableCredentialContractTest preserves M1/M1b and N1-N10: secret-path
 * RAW/marker, Unicode whitespace, numeric whitelist, scalar path, native/identity, enum paths,
 * sentinel autoload, nested undefined, hook timing/eligibility and empty-intermediate omission.
 * Cycles below pin PHP behavior only; the source-cycle oracle was not executed.
 */
#[CoversClass(HumanMessage::class)]
final class HumanMessageSerializationContractTest extends TestCase
{
    private const ID = ['langchain_core', 'messages', 'HumanMessage'];
    private const CANONICAL = '{"lc":1,"type":"constructor","id":["langchain_core","messages","HumanMessage"],"kwargs":{"content":"SYNTH_CHANGED","additional_kwargs":{"literal":{"__lc_escaped__":{"lc":1,"type":"secret","id":["SYNTH_LITERAL"]}},"extra":"SYNTH_ADDED"},"response_metadata":{"origin":"SYNTH_CHANGED"},"id":"SYNTH_CHANGED_ID","name":"SYNTH_CHANGED_NAME"}}';
    private const STORED = '{"type":"human","data":{"content":"SYNTH_CHANGED","additional_kwargs":{"literal":{"__lc_escaped__":{"lc":1,"type":"secret","id":["SYNTH_LITERAL"]}},"extra":"SYNTH_ADDED"},"response_metadata":{"origin":"SYNTH_CHANGED"},"id":"SYNTH_CHANGED_ID","name":"SYNTH_CHANGED_NAME"}}';

    /** @return iterable<string, array{string}> */
    public static function publicPaths(): iterable
    {
        foreach (['toSerializedConstructor', 'toJson', 'jsonSerialize', 'json_encode', 'toDict'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('publicPaths')]
    public function testAllPublicPathsEmitFixedCompleteSourceBytesAfterFirstSerialization(string $path): void
    {
        $human = self::canonicalHuman();
        $captured = $human->kwargs();
        $human->toSerializedConstructor();
        $human->content = 'SYNTH_CHANGED';
        $human->additional_kwargs = ['literal' => self::literal(), 'extra' => 'SYNTH_ADDED'];
        $human->response_metadata = ['origin' => 'SYNTH_CHANGED'];
        $human->id = 'SYNTH_CHANGED_ID';
        $human->name = 'SYNTH_CHANGED_NAME';
        for ($i = 0; $i < 2; ++$i) {
            $actual = $path === 'json_encode' ? json_encode($human, JSON_THROW_ON_ERROR) : json_encode($human->{$path}(), JSON_THROW_ON_ERROR);
            self::assertSame($path === 'toDict' ? self::STORED : self::CANONICAL, $actual);
            self::assertSame($captured, $human->kwargs());
        }
    }

    public function testBeforeFirstAndRepeatedCallsRefreshOnlyCapturedFields(): void
    {
        $human = new HumanMessage('SYNTH_CONTENT');
        $human->content = 'SYNTH_BEFORE_FIRST';
        self::assertSame('SYNTH_BEFORE_FIRST', $human->toSerializedConstructor()['kwargs']['content']);
        $human->content = 'SYNTH_AFTER_FIRST';
        $human->name = 'SYNTH_LATE_NAME';
        $human->id = 'SYNTH_LATE_ID';
        $human->type = 'SYNTH_UNCAPTURED_TYPE';
        foreach ([$human->toJson(), $human->jsonSerialize(), $human->toSerializedConstructor()] as $record) {
            self::assertSame(['content' => 'SYNTH_AFTER_FIRST', 'additional_kwargs' => [], 'response_metadata' => []], $record['kwargs']);
            self::assertSame(self::ID, $record['id']);
        }
        self::assertSame(['content' => 'SYNTH_CONTENT', 'additional_kwargs' => [], 'response_metadata' => []], $human->kwargs());
    }

    public function testCapturedNullAndNullableUpdateIdRetainPhpCaptureProvenance(): void
    {
        $human = new HumanMessage(['content' => 'SYNTH_CONTENT', 'name' => 'SYNTH_NAME', 'id' => 'SYNTH_ID']);
        $human->name = null;
        $human->id = null;
        self::assertSame(null, $human->toSerializedConstructor()['kwargs']['name']);
        self::assertSame(null, $human->toSerializedConstructor()['kwargs']['id']);
        $late = new HumanMessage('SYNTH_CONTENT');
        $late->updateId('SYNTH_ASSIGNED');
        $late->id = 'SYNTH_CURRENT_ID';
        self::assertSame('SYNTH_CURRENT_ID', $late->toJson()['kwargs']['id']);
        self::assertSame('SYNTH_ASSIGNED', $late->kwargs()['id']);
        $late->updateId(null);
        self::assertArrayHasKey('id', $late->toJson()['kwargs']);
        self::assertNull($late->toJson()['kwargs']['id']);
        self::assertStringContainsString('"id":null', json_encode($late, JSON_THROW_ON_ERROR));
        self::assertNull($late->kwargs()['id']);
    }

    /** @return iterable<string, array{string|array, string|array}> */
    public static function typedInputs(): iterable
    {
        yield 'string' => ['SYNTH_CONTENT', 'SYNTH_CONTENT'];
        yield 'empty string' => ['', ''];
        yield 'empty array' => [[], []];
        yield 'content list' => [[['type' => 'text', 'text' => 'SYNTH_BLOCK']], [['type' => 'text', 'text' => 'SYNTH_BLOCK']]];
        yield 'empty content list' => [['content' => []], []];
        yield 'unknown capture' => [['content' => 'SYNTH_CONTENT', 'extraField' => 'SYNTH_UNKNOWN'], 'SYNTH_CONTENT'];
        yield 'constructor null optional' => [['content' => 'SYNTH_CONTENT', 'name' => null, 'id' => null], 'SYNTH_CONTENT'];
        yield 'contentBlocks boundary' => [['contentBlocks' => [['type' => 'text', 'text' => 'SYNTH_BLOCK']]], [['type' => 'text', 'text' => 'SYNTH_BLOCK']]];
    }

    #[DataProvider('typedInputs')]
    public function testTypedConstructorBoundariesStayExplicit(string|array $input, string|array $content): void
    {
        $before = $input;
        $human = new HumanMessage($input);
        self::assertSame($before, $input);
        $record = $human->toSerializedConstructor();
        self::assertSame(self::ID, $record['id']);
        self::assertSame(['content', 'additional_kwargs', 'response_metadata'], array_keys($record['kwargs']));
        self::assertSame($content, $record['kwargs']['content']);
        self::assertSame([], $record['kwargs']['additional_kwargs']);
        self::assertSame(is_array($input) && array_key_exists('contentBlocks', $input) ? ['output_version' => 'v1'] : [], $record['kwargs']['response_metadata']);
        self::assertIsArray($record['kwargs']);
        self::assertNotEmpty($record['kwargs']);
    }

    public function testLiteralAliasesAndCanonicalKeyOrderAreFixed(): void
    {
        self::assertSame(['additional_kwargs' => 'additional_kwargs', 'response_metadata' => 'response_metadata'], BaseMessage::lcAliases());
        self::assertSame(['additional_kwargs' => 'additional_kwargs', 'response_metadata' => 'response_metadata'], HumanMessage::lcAliases());
        $human = new HumanMessage(['name' => 'SYNTH_NAME', 'id' => 'SYNTH_ID', 'response_metadata' => ['origin' => 'SYNTH_ORIGIN'], 'additional_kwargs' => ['literal' => self::literal()], 'content' => 'SYNTH_CONTENT']);
        self::assertSame(['content', 'additional_kwargs', 'response_metadata', 'id', 'name'], array_keys($human->toJson()['kwargs']));
        self::assertArrayNotHasKey('additionalKwargs', $human->toJson()['kwargs']);
        self::assertArrayNotHasKey('responseMetadata', $human->toJson()['kwargs']);
    }

    public function testRealFactoriesAndTwoTrustedLoadRoundsRefreshShippedHuman(): void
    {
        $factory = ImportMap::resolve(self::ID);
        $factoryHuman = $factory(['content' => 'SYNTH_FACTORY']);
        $promptHuman = (new StringPromptValue('SYNTH_PROMPT'))->toMessages()[0];
        foreach ([$factoryHuman, $promptHuman] as $human) {
            self::assertSame(HumanMessage::class, $human::class);
            $human->content = 'SYNTH_FACTORY_CURRENT';
            self::assertSame('SYNTH_FACTORY_CURRENT', $human->toDict()['data']['content']);
        }
        $human = Load::load(self::CANONICAL, ['secretsMap' => []]);
        for ($i = 0; $i < 2; ++$i) {
            self::assertSame(HumanMessage::class, $human::class);
            self::assertSame(self::literal(), $human->additional_kwargs['literal']);
            $human->content = 'SYNTH_ROUND_'.$i;
            self::assertSame('SYNTH_ROUND_'.$i, $human->toJson()['kwargs']['content']);
            $human = Load::load(json_encode($human, JSON_THROW_ON_ERROR), ['secretsMap' => []]);
        }
        self::assertSame('SYNTH_ROUND_1', $human->content);
    }

    public function testTwoStoredAndCheckpointRoundsPreserveSingleLiteralEscape(): void
    {
        foreach (['stored', 'checkpoint'] as $mode) {
            $human = $mode === 'stored' ? MessageUtils::mapStoredMessageToChatMessage(json_decode(self::STORED, true, 512, JSON_THROW_ON_ERROR)) : LcConstructorLoader::load(json_decode(self::CANONICAL, true, 512, JSON_THROW_ON_ERROR));
            for ($i = 0; $i < 2; ++$i) {
                self::assertSame(HumanMessage::class, $human::class);
                self::assertSame(self::literal(), $human->additional_kwargs['literal']);
                $human->content = 'SYNTH_CURRENT_'.$i;
                $record = $human->toJson();
                self::assertSame('SYNTH_CURRENT_'.$i, $record['kwargs']['content']);
                self::assertSame(['__lc_escaped__' => self::literal()], $record['kwargs']['additional_kwargs']['literal']);
                $human = $mode === 'stored' ? MessageUtils::mapStoredMessageToChatMessage($human->toDict()) : LcConstructorLoader::load($record);
            }
            self::assertSame('SYNTH_CURRENT_1', $human->content);
        }
    }

    public function testCacheSerializerHitMissAndRepeatStoredAdapterUseCurrentFields(): void
    {
        $human = self::canonicalHuman();
        $human->content = 'SYNTH_CHANGED';
        $human->additional_kwargs = ['literal' => self::literal(), 'extra' => 'SYNTH_ADDED'];
        $human->response_metadata = ['origin' => 'SYNTH_CHANGED'];
        $human->id = 'SYNTH_CHANGED_ID';
        $human->name = 'SYNTH_CHANGED_NAME';
        $stored = BaseCache::serializeGeneration(new ChatGeneration($human, 'SYNTH_TEXT'));
        self::assertSame(self::STORED, json_encode($stored['message'], JSON_THROW_ON_ERROR));
        $cache = new InMemoryCache();
        self::assertNull($cache->lookup('SYNTH_PROMPT', 'SYNTH_MODEL_KEY'));
        $cache->update('SYNTH_PROMPT', 'SYNTH_MODEL_KEY', [$stored]);
        self::assertSame([$stored], $cache->lookup('SYNTH_PROMPT', 'SYNTH_MODEL_KEY'));
        self::assertNull($cache->lookup('SYNTH_PROMPT', 'SYNTH_OTHER_KEY'));
        for ($i = 0; $i < 2; ++$i) {
            $generation = BaseCache::deserializeStoredGeneration($stored);
            self::assertSame(self::literal(), $generation->message->additional_kwargs['literal']);
            $generation->message->content = 'SYNTH_CACHE_CURRENT_'.$i;
            $stored = BaseCache::serializeGeneration($generation);
            self::assertSame('SYNTH_CACHE_CURRENT_'.$i, $stored['message']['data']['content']);
            self::assertSame(['__lc_escaped__' => self::literal()], $stored['message']['data']['additional_kwargs']['literal']);
        }
    }

    public function testPromptIdentityStringAndNestedConstructorAreLive(): void
    {
        $human = new HumanMessage(['content' => 'SYNTH_CONTENT', 'name' => 'SYNTH_NAME']);
        $prompt = new ChatPromptValue([$human]);
        $human->content = 'SYNTH_CHANGED';
        $human->name = 'SYNTH_CHANGED_NAME';
        self::assertSame($human, $prompt->toMessages()[0]);
        self::assertSame('Human: SYNTH_CHANGED_NAME, SYNTH_CHANGED', $prompt->toStringValue());
        $wire = json_decode(json_encode($prompt, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['lc' => 1, 'type' => 'constructor', 'id' => self::ID, 'kwargs' => ['content' => 'SYNTH_CHANGED', 'additional_kwargs' => [], 'response_metadata' => [], 'name' => 'SYNTH_CHANGED_NAME']], $wire['kwargs']['messages'][0]);
        self::assertSame($human, $prompt->toMessages()[0]);
    }

    public function testHumanChunkStaysSnapshotAndPromotionActivatesOnlyFinishedHuman(): void
    {
        $chunk = (new HumanMessageChunk('SYNTH_LEFT'))->concat(new HumanMessageChunk('SYNTH_RIGHT'));
        $captured = $chunk->toJson();
        $chunk->content = 'SYNTH_CHUNK_CURRENT';
        self::assertSame($captured, $chunk->toJson());
        self::assertSame('SYNTH_LEFTSYNTH_RIGHT', $chunk->toJson()['kwargs']['content']);
        $human = $chunk->toMessage();
        self::assertSame(HumanMessage::class, $human::class);
        self::assertSame('SYNTH_CHUNK_CURRENT', $human->content);
        $human->content = 'SYNTH_PROMOTED_CURRENT';
        self::assertSame('SYNTH_PROMOTED_CURRENT', $human->toJson()['kwargs']['content']);
    }

    public function testDefaultUserSubclassPreservesLegacyIdHooksAndSnapshot(): void
    {
        $human = new class('SYNTH_CONTENT') extends HumanMessage {
            public int $hookCalls = 0;
            public static function lcId(): array { return ['SYNTH_USER', 'Human']; }
            protected function sourceSerializationSecrets(): array { ++$this->hookCalls; return ['content' => 'SYNTH_FORBIDDEN']; }
            protected function filterKwargs(array $kwargs): array { $kwargs['custom'] = 'SYNTH_LEGACY'; return $kwargs; }
        };
        $before = $human->toJson();
        $human->content = 'SYNTH_CHANGED';
        self::assertSame($before, $human->toJson());
        self::assertSame(['SYNTH_USER', 'Human'], $before['id']);
        self::assertSame('SYNTH_CONTENT', $before['kwargs']['content']);
        self::assertSame('SYNTH_LEGACY', $before['kwargs']['custom']);
        self::assertSame(0, $human->hookCalls);
    }

    public function testAlreadyOptedUserSubclassKeepsInheritedExplicitCoreWrapper(): void
    {
        $human = new class(['content' => 'SYNTH_CONTENT']) extends HumanMessage {
            public function __construct(array $fields) { parent::__construct($fields); $this->captureSourceSerializationBag($fields); }
            protected function sourceSerializationEnabled(): bool { return true; }
            protected function sourceSerializationEligible(): bool { return true; }
            protected function sourceSerializationAliases(): array { return ['content' => 'user_content']; }
            public static function lcId(): array { return ['SYNTH_USER', 'OptedHuman']; }
        };
        $human->content = 'SYNTH_CURRENT';
        self::assertSame(['lc' => 1, 'type' => 'constructor', 'id' => ['SYNTH_USER', 'OptedHuman'], 'kwargs' => ['user_content' => 'SYNTH_CURRENT']], $human->toJson());
    }

    public function testOtherShippedFamiliesKeepTheirOwnLegacySnapshots(): void
    {
        foreach ([new AIMessage('SYNTH_CONTENT'), new SystemMessage('SYNTH_CONTENT')] as $message) {
            $before = $message->toJson();
            $message->content = 'SYNTH_CHANGED';
            self::assertSame($before, $message->toJson());
        }
        $document = new Document('SYNTH_DOCUMENT');
        $before = $document->toJson();
        $document->pageContent = 'SYNTH_CHANGED';
        self::assertSame($before, $document->toJson());
    }

    public function testOrdinaryNestedCallerNativeAndPrivateCaptureRemainUnchanged(): void
    {
        $nested = (object) ['value' => 'SYNTH_OBJECT'];
        $caller = ['content' => 'SYNTH_CONTENT', 'additional_kwargs' => ['nested' => $nested]];
        $beforeCaller = serialize($caller);
        $human = new HumanMessage($caller);
        self::assertSame($beforeCaller, serialize($caller));
        self::assertArrayNotHasKey('response_metadata', $caller);
        $human->content = 'SYNTH_CHANGED';
        $before = serialize($human);
        $kwargs = $human->kwargs();
        $key = "\0".Serializable::class."\0sourceSerializationCapturedBag";
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('SYNTH_CHANGED', $human->toDict()['data']['content']);
            self::assertSame($before, serialize($human));
            self::assertSame($kwargs, $human->kwargs());
            self::assertSame($nested, $human->additional_kwargs['nested']);
            self::assertSame([], ((array) $human)[$key]);
            self::assertSame($beforeCaller, serialize($caller));
        }
        self::assertEquals($human, unserialize($before));
        self::assertEquals(new HumanMessage('SYNTH_EQUAL'), new HumanMessage('SYNTH_EQUAL'));
    }

    public function testRepresentativeDedupKeepsObjectIdentityAndNativeArrayFingerprint(): void
    {
        $one = new HumanMessage('SYNTH_CONTENT');
        $two = new HumanMessage('SYNTH_CONTENT');
        $set = new ValueSet([$one, $one, $two]);
        self::assertSame(2, $set->size());
        $fingerprint = serialize([$one]);
        json_encode($one, JSON_THROW_ON_ERROR);
        self::assertSame($fingerprint, serialize([$one]));
        $arraySet = new ValueSet([[$one], [$one]]);
        self::assertSame(1, $arraySet->size());
        self::assertTrue($arraySet->has([$one]));
        $oldJson = json_encode($one, JSON_THROW_ON_ERROR);
        $one->content = 'SYNTH_CHANGED';
        self::assertNotSame($oldJson, json_encode($one, JSON_THROW_ON_ERROR));
        self::assertSame(2, $set->size());
    }

    public function testLiteralWrapperAndGenuineNestedLcObjectStayDistinct(): void
    {
        $child = new HumanMessage('SYNTH_CHILD');
        $outer = new AIMessage(['content' => 'SYNTH_OUTER', 'additional_kwargs' => ['child' => $child]]);
        $child->content = 'SYNTH_CHILD_CURRENT';
        $wire = json_decode(json_encode($outer, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('SYNTH_CHILD_CURRENT', $wire['kwargs']['additional_kwargs']['child']['kwargs']['content']);
        self::assertSame(self::ID, $wire['kwargs']['additional_kwargs']['child']['id']);
        $human = new HumanMessage('SYNTH_CONTENT');
        $human->additional_kwargs = ['marker' => self::literal(), 'wrapper' => ['__lc_escaped__' => self::literal()]];
        $record = $human->toJson();
        self::assertSame(['__lc_escaped__' => self::literal()], $record['kwargs']['additional_kwargs']['marker']);
        self::assertSame(['__lc_escaped__' => ['__lc_escaped__' => self::literal()]], $record['kwargs']['additional_kwargs']['wrapper']);
        $loaded = Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => []]);
        self::assertSame(self::literal(), $loaded->additional_kwargs['marker']);
        self::assertSame(['__lc_escaped__' => self::literal()], $loaded->additional_kwargs['wrapper']);
    }

    public function testSyntheticExplicitCoreMarkerProtocolIsSeparateFromHumanHooks(): void
    {
        $human = new HumanMessage(['content' => 'SYNTH_CONTENT', 'name' => 'SYNTH_NAME', 'additional_kwargs' => ['literal' => self::literal()]]);
        $state = Serializable::sourceContractPublicState($human);
        $record = Serializable::serializeSourceContract($human, $human->kwargs(), self::ID, true, [['aliases' => [], 'secrets' => ['name' => 'SYNTH_NAME_SECRET'], 'attributes' => []]], $state, $state);
        self::assertSame(['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_NAME_SECRET']], $record['kwargs']['name']);
        self::assertSame(['__lc_escaped__' => self::literal()], $record['kwargs']['additional_kwargs']['literal']);
        $loaded = Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => ['SYNTH_NAME_SECRET' => 'SYNTH_RESOLVED']]);
        self::assertSame('SYNTH_RESOLVED', $loaded->name);
        self::assertSame(self::literal(), $loaded->additional_kwargs['literal']);
        self::assertSame('SYNTH_NAME', $human->toJson()['kwargs']['name']);
        self::assertSame('SYNTH_NAME', $human->name);
    }

    public function testLocalSerializedTraceNamesAndStartEndCountsUseCurrentHumanRecord(): void
    {
        $human = new HumanMessage(['content' => 'SYNTH_CONTENT', 'name' => 'SYNTH_NAME']);
        $human->name = 'SYNTH_CURRENT_NAME';
        $handler = new EventStreamCallbackHandler();
        $handler->handleChainStart(Serialized::fromArray($human->toSerializedConstructor()), ['input' => 'SYNTH_INPUT'], 'SYNTH_RUN');
        $handler->handleChainEnd(['output' => 'SYNTH_OUTPUT'], 'SYNTH_RUN');
        $events = [];
        foreach ($handler->drain() as $event) {
            $events[] = $event->toArray();
        }
        self::assertSame(['on_chain_start', 'on_chain_end'], array_column($events, 'event'));
        self::assertSame(['SYNTH_CURRENT_NAME', 'SYNTH_CURRENT_NAME'], array_column($events, 'name'));
        self::assertSame(['SYNTH_RUN', 'SYNTH_RUN'], array_column($events, 'run_id'));
    }

    public function testSelfCycleEmitsPhpNotImplementedAndLeavesLiveGraphUntouched(): void
    {
        $human = new HumanMessage('SYNTH_CONTENT');
        $human->additional_kwargs = ['self' => $human];
        self::assertArrayHasKey('self', $human->toJson()['kwargs']['additional_kwargs']);
        self::assertSame(['lc' => 1, 'type' => 'not_implemented', 'id' => self::ID], $human->toJson()['kwargs']['additional_kwargs']['self']);
        self::assertSame($human, $human->additional_kwargs['self']);
    }

    public function testMutualHumanCycleFailsClosedInJsonButStoredFormReturnsLiveObject(): void
    {
        $left = new HumanMessage('SYNTH_LEFT');
        $right = new HumanMessage('SYNTH_RIGHT');
        $left->additional_kwargs = ['other' => $right];
        $right->additional_kwargs = ['other' => $left];
        self::assertArrayHasKey('other', $left->toDict()['data']['additional_kwargs']);
        self::assertSame($right, $left->toDict()['data']['additional_kwargs']['other']);
        try {
            json_encode($left, JSON_THROW_ON_ERROR);
            self::fail('Mutual Human JSON cycle must throw. Source cycle oracle unexecuted.');
        } catch (\JsonException $error) {
            self::assertSame(JSON_ERROR_RECURSION, $error->getCode());
            self::assertSame('Recursion detected', $error->getMessage());
        }
        self::assertSame($left, $right->additional_kwargs['other']);
    }

    public function testNewlyReachableNestedUndefinedKeepsExplicitPhpJsonFailure(): void
    {
        $human = new HumanMessage('SYNTH_CONTENT');
        $human->additional_kwargs = ['undefined' => Serializable::sourceContractUndefined()];
        self::assertArrayHasKey('undefined', $human->toDict()['data']['additional_kwargs']);
        self::assertSame(Serializable::sourceContractUndefined(), $human->toDict()['data']['additional_kwargs']['undefined']);
        $this->expectException(\JsonException::class);
        $this->expectExceptionCode(JSON_ERROR_NON_BACKED_ENUM);
        json_encode($human, JSON_THROW_ON_ERROR);
    }

    private static function canonicalHuman(): HumanMessage
    {
        return new HumanMessage(['content' => 'SYNTH_CONTENT', 'additional_kwargs' => ['literal' => self::literal()], 'response_metadata' => ['origin' => 'SYNTH_ORIGIN'], 'id' => 'SYNTH_ID', 'name' => 'SYNTH_NAME']);
    }

    /** @return array{lc: int, type: string, id: list<string>} */
    private static function literal(): array
    {
        return ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL']];
    }
}
