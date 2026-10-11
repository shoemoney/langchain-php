<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\MongoDB\MongoClientInterface;
use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;
use LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface;
use LangGraph\Checkpoint\MongoDB\MongoDBDriverCollection;
use LangGraph\Checkpoint\MongoDB\MongoDBSaver;
use LangGraph\Checkpoint\Serde\BaseCheckpointSerializer;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PHP-native empty BSON update-key adaptation. The pinned JS saver writes raw metadata_search.
 * Binary metadata remains authoritative. Dot/$ query limits, raw legacy prefix records and
 * custom BSON serializers that emit hidden empty keys are deliberately not repaired here.
 */
#[CoversClass(MongoDBSaver::class)]
#[CoversClass(MongoDBDriverCollection::class)]
final class MongoDBSaverMetadataSearchTest extends TestCase
{
    private const PREFIX = '__lg_empty_key_v1__';

    public function testRootAncestryProjectionKeepsOpaqueMetadataAndAddressing(): void
    {
        $client = new MongoDBFakeClient();
        $saver = new MongoDBSaver($client);
        $metadata = ['source' => 'loop', 'step' => 0, 'parents' => ['' => 'parent']];
        $config = ['configurable' => ['thread_id' => 'thread', 'checkpoint_ns' => '', 'checkpoint_id' => 'parent']];
        $checkpoint = new Checkpoint(v: 4, id: 'child', ts: '', channelValues: ['x' => 1]);
        $stored = $saver->put($config, $checkpoint, $metadata);
        $saver->putWrites($stored, [['x', 'pending']], 'task');
        $tuple = $saver->getTuple($stored);
        $call = $client->checkpoints()->callsTo('updateOne')[0]['args'];
        self::assertSame(['thread_id' => 'thread', 'checkpoint_ns' => '', 'checkpoint_id' => 'child'], $call['filter']);
        self::assertSame(['upsert' => true], $call['options']);
        self::assertSame('parent', $call['update']['$set']['parent_checkpoint_id']);
        self::assertSame(['source' => 'loop', 'step' => 0, 'parents' => [self::PREFIX . 'e' => 'parent']], $call['update']['$set']['metadata_search']);
        self::assertSame((new JsonPlusSerializer())->dumpsTyped($metadata)[1], $call['update']['$set']['metadata']->value());
        self::assertSame($metadata, $tuple?->metadata);
        self::assertSame($config, $tuple?->parentConfig);
        self::assertSame([['task', 'x', 'pending']], $tuple?->pendingWrites);
        self::assertEquals($checkpoint->toArray(), $tuple?->checkpoint->toArray());
        self::assertSame(['source' => 'loop', 'step' => 0, 'parents' => ['' => 'parent']], $metadata);
        self::assertCount(1, $saver->list($stored, new CheckpointListOptions(filter: ['parents.' => 'parent'])));
    }

    public static function primitiveValues(): array
    {
        return [['value'], [null], [false], [0], ['']];
    }

    #[DataProvider('primitiveValues')]
    public function testEmptyAndLiteralPrefixPathsDoNotCollide(mixed $value): void
    {
        $client = new MongoDBFakeClient();
        $saver = new MongoDBSaver($client);
        $literal = self::PREFIX . 'e';
        $metadata = ['' => $value, 'parents' => ['' => $value, $literal => 'literal', self::PREFIX => 'prefix', self::PREFIX . 'k' => 'hex-looking']];
        $config = $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), $metadata);
        self::assertCount(1, $saver->list($config, new CheckpointListOptions(filter: ['' => $value, 'parents.' => $value, 'parents.' . $literal => 'literal'])));
        self::assertCount(0, $saver->list($config, new CheckpointListOptions(filter: ['parents.' => 'literal'])));
        $search = $client->checkpoints()->documents[0]['metadata_search'];
        self::assertArrayHasKey(self::PREFIX . 'e', $search);
        self::assertSame($value, $search[self::PREFIX . 'e']);
        self::assertCount(4, $search['parents']);
        self::assertSame('literal', $search['parents'][self::PREFIX . 'k' . bin2hex($literal)]);
        self::assertSame($metadata, $saver->getTuple($config)?->metadata);
    }

    public function testListsObjectsDotsDollarsAndMissingNullKeepTheirExistingGrammar(): void
    {
        $client = new MongoDBFakeClient();
        $saver = new MongoDBSaver($client);
        $metadata = ['parents' => (object) ['' => 'root', 'child.ns' => 'literal', 'child' => ['ns' => 'nested'], '$child' => 'dollar'], 'values' => [['' => 'first']], 'emptyObject' => new \stdClass()];
        $config = $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), $metadata);
        self::assertCount(1, $saver->list($config, new CheckpointListOptions(filter: ['parents.' => 'root', 'values.0.' => 'first', 'parents.child.ns' => 'nested', 'parents.$child' => 'dollar', 'missing' => null])));
        self::assertCount(0, $saver->list($config, new CheckpointListOptions(filter: ['parents.child.ns' => 'literal'])));
        $set = $client->checkpoints()->callsTo('updateOne')[0]['args']['update']['$set'];
        self::assertInstanceOf(\stdClass::class, $set['metadata_search']['emptyObject']);
        self::assertSame('root', $metadata['parents']->{''});
        self::assertSame('literal', $set['metadata_search']['parents']->{'child.ns'});
        self::assertSame('dollar', $set['metadata_search']['parents']->{'$child'});
    }

    public function testSameIdOverwriteProjectsOriginalOnceAndLeavesSafeLegacySearchable(): void
    {
        $client = new MongoDBFakeClient();
        $saver = new MongoDBSaver($client);
        $config = ['configurable' => ['thread_id' => 't']];
        foreach (['one', 'two'] as $value) {
            $saver->put($config, new Checkpoint(v: 4, id: 'c', ts: ''), ['source' => 'loop', 'parents' => ['' => $value]]);
        }
        self::assertCount(1, $client->checkpoints()->documents);
        self::assertSame(['source' => 'loop', 'parents' => [self::PREFIX . 'e' => 'two']], $client->checkpoints()->documents[0]['metadata_search']);
        self::assertCount(1, $saver->list($config, new CheckpointListOptions(filter: ['parents.' => 'two', 'source' => 'loop'])));
        self::assertCount(0, $saver->list($config, new CheckpointListOptions(filter: ['parents.' => 'one'])));
        // Raw pre-adaptation records still match safe fields, but prefix filters require migration.
        $client->checkpoints()->documents[0]['metadata_search'][self::PREFIX . 'e'] = 'legacy';
        self::assertCount(1, $saver->list($config, new CheckpointListOptions(filter: ['source' => 'loop'])));
        self::assertCount(0, $saver->list($config, new CheckpointListOptions(filter: [self::PREFIX . 'e' => 'legacy'])));
        self::assertCount(1, $saver->list($config, new CheckpointListOptions(filter: [self::PREFIX . 'e' => null])));
    }

    public function testInjectionFailureRetainsExactMessageAndMakesNoCollectionCall(): void
    {
        $client = new MongoDBFakeClient();
        $saver = new MongoDBSaver($client);
        try {
            $saver->list([], new CheckpointListOptions(filter: ['parents.' => ['$regex' => '.*']]));
            self::fail('Expected primitive filter guard.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Invalid filter value for key "parents.": filter values must be primitives (string, number, boolean, or null)', $error->getMessage());
        }
        self::assertSame([], $client->database->collections);
    }

    public function testLiteralLcRecordsSurviveOpaqueRoundtripWithoutAdditionalEscape(): void
    {
        $client = new MongoDBFakeClient();
        $saver = new MongoDBSaver($client);
        $metadata = ['' => ['lc' => 1, 'type' => 'secret', 'id' => ['N28_SYNTHETIC']], 'constructor' => ['lc' => 1, 'type' => 'constructor', 'id' => ['unknown', 'literal'], 'kwargs' => ['' => 'value']], 'wrapper' => ['__lc_escaped__' => ['lc' => 0]]];
        $config = $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), $metadata);
        self::assertSame((new JsonPlusSerializer())->dumpsTyped($metadata)[1], $client->checkpoints()->documents[0]['metadata']->value());
        self::assertSame($metadata, $saver->getTuple($config)?->metadata);
        self::assertArrayHasKey(self::PREFIX . 'e', $client->checkpoints()->documents[0]['metadata_search']);
        self::assertSame($metadata[''], $client->checkpoints()->documents[0]['metadata_search'][self::PREFIX . 'e']);
    }

    public function testCopyOnWritePreservesReferenceSlotsAndSharesChangedAliasesPerCall(): void
    {
        $serde = self::opaqueSerde();
        [$saver, $capture] = self::capture($serde);
        $shared = (object) ['' => 'one'];
        $safe = (object) ['value' => 'safe'];
        $branch = ['' => 'reference'];
        $referencedValue = 'property';
        $withReference = new \stdClass();
        $withReference->{''} = &$referencedValue;
        $metadata = ['before' => 'unchanged', 'a' => $shared, 'b' => $shared, 'safe' => $safe, 'property' => $withReference];
        $metadata['array'] = &$branch;
        $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), $metadata);
        $search = $capture->updates[0][1]['$set']['metadata_search'];
        self::assertSame($search['a'], $search['b']);
        self::assertNotSame($shared, $search['a']);
        self::assertSame($safe, $search['safe']);
        self::assertSame(['' => 'reference'], $branch);
        self::assertSame('property', $referencedValue);
        self::assertSame('property', $search['property']->{self::PREFIX . 'e'});
        self::assertSame('one', $shared->{''});
        self::assertCount(2, $serde->calls);
        self::assertSame($metadata, $serde->calls[1]);
        self::assertSame('opaque-2', $capture->updates[0][1]['$set']['metadata']->value());
        $search['array'][self::PREFIX . 'e'] = 'local mutation';
        $search['property']->{self::PREFIX . 'e'} = 'local mutation';
        self::assertSame(['' => 'reference'], $branch);
        self::assertSame('property', $referencedValue);
        $shared->{''} = 'two';
        $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), $metadata);
        self::assertSame('two', $capture->updates[1][1]['$set']['metadata_search']['a']->{self::PREFIX . 'e'});
        self::assertSame('one', $capture->updates[0][1]['$set']['metadata_search']['a']->{self::PREFIX . 'e'});
        self::assertCount(4, $serde->calls);
    }

    public function testInvalidAndCustomKeysAndObjectsAreNotNormalizedThroughAnotherCodec(): void
    {
        [$saver, $capture] = self::capture(self::opaqueSerde());
        $custom = new class () extends \stdClass { public int $calls = 0; public function jsonSerialize(): array { ++$this->calls; return ['' => 'hidden']; } };
        $custom->{''} = 'custom';
        $invalidKeys = [self::PREFIX . "\0invalid" => 'nul', self::PREFIX . "\xff" => 'utf8'];
        $metadata = ['custom' => $custom, 'invalid' => $invalidKeys];
        $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), $metadata);
        $search = $capture->updates[0][1]['$set']['metadata_search'];
        self::assertSame($custom, $search['custom']);
        self::assertSame(0, $custom->calls);
        self::assertSame($invalidKeys, $search['invalid']);
    }

    public static function depthCases(): array
    {
        return [[true, 509, true, true], [false, 509, true, true], [true, 510, false, true], [false, 510, false, true], [true, 509, true, false], [false, 509, true, false], [true, 510, false, false], [false, 510, false, false]];
    }

    #[DataProvider('depthCases')]
    public function testMemoDepthIsCheckedInBothTraversalOrders(bool $shallowFirst, int $wrappers, bool $valid, bool $changed): void
    {
        [$saver, $capture] = self::capture(self::opaqueSerde());
        $leafKey = $changed ? '' : 'value';
        $shared = (object) ['child' => (object) [$leafKey => 'leaf']];
        $deep = $shared;
        for ($i = 0; $i < $wrappers; ++$i) { $deep = (object) ['child' => $deep]; }
        $metadata = $shallowFirst ? ['a' => $shared, 'b' => $deep] : ['b' => $deep, 'a' => $shared];
        if (!$valid) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Metadata search nesting exceeds 512 levels.');
        }
        try {
            $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), $metadata);
            self::assertCount(1, $capture->updates);
            self::assertSame('leaf', $shared->child->{$leafKey});
        } finally {
            self::assertCount($valid ? 1 : 0, $capture->updates);
        }
    }

    public static function cycles(): array
    {
        $object = new \stdClass(); $object->self = $object;
        $array = []; $array['self'] = &$array;
        return [[$object, 'Circular metadata search document.'], [$array, 'Metadata search nesting exceeds 512 levels.']];
    }

    #[DataProvider('cycles')]
    public function testCustomSerdeCyclesAreBoundedBeforeTransport(mixed $value, string $expected): void
    {
        [$saver, $capture] = self::capture(self::opaqueSerde());
        try {
            $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), ['value' => $value]);
            self::fail('Expected bounded cycle/depth rejection.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame($expected, $error->getMessage());
        }
        self::assertSame([], $capture->updates);
    }

    public function testMismatchedSerdeTagsStillFailBeforeProjectionOrTransport(): void
    {
        $serde = new class () extends BaseCheckpointSerializer {
            public int $calls = 0;
            public function dumpsTyped(mixed $data): array { return [++$this->calls === 1 ? 'a' : 'b', 'opaque']; }
            public function loadsTyped(string $type, string $data): mixed { return null; }
        };
        [$saver, $capture] = self::capture($serde);
        $cycle = new \stdClass(); $cycle->self = $cycle;
        try {
            $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), ['cycle' => $cycle]);
            self::fail('Expected mismatched tags.');
        } catch (\RuntimeException $error) {
            self::assertSame('Mismatched checkpoint and metadata types.', $error->getMessage());
        }
        self::assertSame(2, $serde->calls);
        self::assertSame([], $capture->updates);
    }

    /** Tests actual production adapter conversion and native queueing, without a server. */
    public function testNativeUpdateQueuesRootNamespaceProjectionAndPreservesTypedLeaves(): void
    {
        if (!class_exists(\MongoDB\Driver\BulkWrite::class)) { self::markTestSkipped('Native BSON queue check requires ext-mongodb.'); }
        $binary = new \MongoDB\BSON\Binary('synthetic');
        $date = new \MongoDB\BSON\UTCDateTime(0);
        [$adapter, $native] = self::nativeQueue();
        [$saver] = self::capture(self::opaqueSerde(), $adapter);
        $saver->put(['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '']], new Checkpoint(v: 4, id: 'c', ts: ''), ['parents' => ['' => 'root'], 'native' => [$binary, $date]]);
        self::assertSame(1, $native->queued);
        self::assertSame($binary, $native->update['$set']['metadata_search']['native'][0]);
        self::assertSame($date, $native->update['$set']['metadata_search']['native'][1]);
        self::assertSame('opaque-2', $native->update['$set']['metadata']->getData());
        self::assertSame(['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c'], $native->filter);
    }

    public function testHiddenEmptyBsonSerializerKeyRetainsItsNativeRejection(): void
    {
        if (!class_exists(\MongoDB\Driver\BulkWrite::class)) { self::markTestSkipped('Native BSON queue check requires ext-mongodb.'); }
        $custom = new class () implements \MongoDB\BSON\Serializable {
            public int $calls = 0;
            public function bsonSerialize(): array { ++$this->calls; return ['' => 'hidden']; }
        };
        [$adapter, $native] = self::nativeQueue();
        [$saver] = self::capture(self::opaqueSerde(), $adapter);
        try {
            $saver->put(['configurable' => ['thread_id' => 't']], new Checkpoint(v: 4, id: 'c', ts: ''), ['custom' => $custom]);
            self::fail('Hidden custom BSON empty key remains invalid.');
        } catch (\MongoDB\Driver\Exception\InvalidArgumentException $error) {
            self::assertSame('invalid argument for update: Element key cannot be an empty string', $error->getMessage());
        }
        self::assertSame(1, $custom->calls);
        self::assertSame(0, $native->queued);
    }

    private static function nativeQueue(): array
    {
        $native = new class () {
            public int $queued = 0;
            public array $filter = [];
            public array $update = [];
            public function updateOne(array $filter, array $update, array $options): void
            {
                $this->filter = $filter; $this->update = $update;
                $bulk = new \MongoDB\Driver\BulkWrite();
                $bulk->update($filter, $update, $options);
                $this->queued = count($bulk);
            }
        };
        // Only borrowed-handle construction is a seam; inherited updateOne/toBson run unchanged.
        $adapter = new class ($native) extends MongoDBDriverCollection {
            public function __construct(private readonly object $native) {}
            protected function nativeCollection(): object { return $this->native; }
        };
        return [$adapter, $native];
    }

    /**
     * kind, size, changed leaf key. Sized for real headroom under the 128M child limit on PHP 8.2-8.4:
     * dag20/linear40000 sat at 95% of the limit on 8.5 and fataled in json_encode on native 8.2, in
     * baseline and repair alike, so those near-limit shapes are kept as scratch evidence only.
     * Native 8.2-8.4 children peak ~30% above 8.5 here; linear20000 already reached 86M.
     */
    private const SHAPES = [
        'dag16' => ['dag', 16, true],
        'dag16safe' => ['dag', 16, false],
        'linear15000' => ['linear', 15000, true],
        'linear15000safe' => ['linear', 15000, false],
        'adapter-dag12' => ['dag', 12, true],
        'adapter-linear10000' => ['linear', 10000, true],
        'adapter-linear10000safe' => ['linear', 10000, false],
    ];

    /** Fails a shape that drifts toward the limit before it can flake as a fatal. */
    private const HEADROOM_PEAK = 100663296;

    public static function boundedShapes(): array
    {
        return array_map(static fn (string $name): array => [$name, str_starts_with($name, 'adapter-')], array_combine(array_keys(self::SHAPES), array_keys(self::SHAPES)));
    }

    #[DataProvider('boundedShapes')]
    public function testProjectionAndAdapterPositiveControlsAt128M(string $shape, bool $adapter): void
    {
        [, $size, $changed] = self::SHAPES[$shape];
        $result = self::runChild($shape, $adapter);
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertTrue($result['json']['sent']);
        self::assertSame($size, $result['json']['extent']);
        self::assertTrue($result['json']['callerUnchanged']);
        self::assertSame($changed ? [self::PREFIX . 'e'] : ['value'], $result['json']['leafKeys']);
        // Unchanged search data is the caller's own data on the wire; empty keys deliberately are not.
        self::assertSame(!$changed, $result['json']['wireSha'] === $result['json']['originalSha']);
        if (!$adapter) {
            self::assertTrue($result['json']['sharedProjection']);
            self::assertTrue($result['json']['safeIdentity']);
            self::assertSame(!$changed, $result['json']['wholeIdentity']);
        }
        self::assertLessThan(self::HEADROOM_PEAK, $result['json']['peak']);
    }

    public function testTimedOutChildIsStoppedAndItsScriptRemoved(): void
    {
        $script = null;
        $started = microtime(true);
        try {
            self::runChild('dag16', false, timeout: 0.001, script: $script);
            self::fail('Expected forced timeout.');
        } catch (\PHPUnit\Framework\AssertionFailedError $error) {
            self::assertSame('Bounded metadata child timed out.', $error->getMessage());
        }
        self::assertIsString($script);
        self::assertStringStartsWith('n28-memory-', basename($script));
        self::assertFileDoesNotExist($script);
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    /** Child transport hashes wire data; it never decodes expanded JSON into arrays. */
    private static function runChild(string $shape, bool $adapter, ?string $baseline = null, float $timeout = 20.0, ?string &$script = null): array
    {
        [$kind, $size, $changed] = self::SHAPES[$shape];
        $root = dirname(__DIR__, 3);
        $source = <<<'PHP'
<?php
require $argv[1] . '/vendor/autoload.php';
if ($argv[6] !== '') require $argv[6];
[$kind, $size, $adapterMode] = [$argv[2], (int) $argv[3], $argv[5] === '1'];
$leafKey = $argv[4] === '1' ? '' : 'value';
if ($kind === 'linear') {
    $safe = new stdClass();
    for ($i = 0; $i < $size; ++$i) { $safe->{'field' . $i} = (object) ['name' => str_repeat('s', 600), 'value' => str_repeat('v', 600)]; }
    $metadata = ['safe' => $safe, 'parents' => [$leafKey => 'root']];
} else {
    $dag = (object) [$leafKey => 'synthetic-leaf'];
    for ($i = 0; $i < $size; ++$i) { $dag = (object) ['left' => $dag, 'right' => $dag]; }
    $safe = (object) ['safe' => true]; $metadata = ['tree' => $dag, 'alias' => $dag, 'safe' => $safe];
}
$originalSha = hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR));
$native = new class ($metadata, $safe) {
    public bool $sent = false; public array $data = [];
    public function __construct(private array $original, private object $expectedSafe) {}
    public function updateOne(array $filter, array $update, array $options): void {
        $search = $update['$set']['metadata_search'];
        $wire = json_encode($search, JSON_THROW_ON_ERROR);
        $this->data = ['wireSha' => hash('sha256', $wire), 'bytes' => strlen($wire)];
        unset($wire);
        $leaf = $search['parents'] ?? $search['tree'];
        $extent = isset($search['tree']) ? 0 : count((array) $search['safe']);
        while (isset($leaf->left)) { $leaf = $leaf->left; ++$extent; }
        $this->data += ['extent' => $extent, 'leafKeys' => array_map('strval', array_keys((array) $leaf)), 'safeIdentity' => $search['safe'] === $this->expectedSafe, 'wholeIdentity' => $search === $this->original, 'sharedProjection' => !isset($search['tree']) || $search['tree'] === $search['alias']];
        $this->sent = true;
    }
};
$collection = new class ($native) implements LangGraph\Checkpoint\MongoDB\MongoCollectionInterface {
    public function __construct(private object $native) {}
    public function updateOne(array $filter, array $update, array $options = []): void { $this->native->updateOne($filter, $update, $options); }
    public function find(array $filter = [], array $sort = [], ?int $limit = null): array { return []; }
    public function findOne(array $filter, array $sort = []): ?array { return null; }
    public function insertMany(array $documents): void {}
    public function deleteMany(array $filter): int { return 0; }
    public function createIndex(array $keys, array $options = []): string { return 'index'; }
};
if ($adapterMode) {
    $collection = new class ($native) extends LangGraph\Checkpoint\MongoDB\MongoDBDriverCollection {
        public function __construct(private object $native) {}
        protected function nativeCollection(): object { return $this->native; }
        protected function encodeBinary(LangGraph\Checkpoint\MongoDB\MongoBinary $binary): object { return (object) ['data' => $binary->value()]; }
    };
}
$db = new class ($collection) implements LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface {
    public function __construct(private LangGraph\Checkpoint\MongoDB\MongoCollectionInterface $collection) {}
    public function collection(string $name): LangGraph\Checkpoint\MongoDB\MongoCollectionInterface { return $this->collection; }
};
$client = new class ($db) implements LangGraph\Checkpoint\MongoDB\MongoClientInterface {
    public function __construct(private LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface $database) {}
    public function appendMetadata(array $metadata): void {}
    public function db(?string $name = null): LangGraph\Checkpoint\MongoDB\MongoDatabaseInterface { return $this->database; }
};
$serde = new class extends LangGraph\Checkpoint\Serde\BaseCheckpointSerializer {
    public function dumpsTyped(mixed $data): array { return ['custom', 'opaque']; }
    public function loadsTyped(string $type, string $data): mixed { return null; }
};
$saver = new LangGraph\Checkpoint\MongoDB\MongoDBSaver($client, serde: $serde);
$saver->put(['configurable' => ['thread_id' => 't']], new LangGraph\Checkpoint\Checkpoint(v: 4, id: 'c', ts: ''), $metadata);
$peak = memory_get_peak_usage(true);
$callerUnchanged = hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR)) === $originalSha && $metadata['safe'] === $safe;
echo json_encode($native->data + ['sent' => $native->sent, 'originalSha' => $originalSha, 'callerUnchanged' => $callerUnchanged, 'peak' => $peak]), "\n";
PHP;
        $script = tempnam(sys_get_temp_dir(), 'n28-memory-');
        self::assertNotFalse($script);
        file_put_contents($script, $source);
        // The parent's coverage instrumentation would be inherited through PHP_BINARY and measured
        // against the 128M limit; the child measures the saver, so it runs uninstrumented.
        $env = ['XDEBUG_MODE' => 'off'] + getenv();
        $process = null; $pipes = [];
        try {
            $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', $script, $root, $kind, (string) $size, $changed ? '1' : '0', $adapter ? '1' : '0', $baseline ?? ''], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($process);
            fclose($pipes[0]); unset($pipes[0]);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $stdout = ''; $stderr = ''; $started = microtime(true);
            do {
                $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                if (microtime(true) - $started > $timeout) { self::fail('Bounded metadata child timed out.'); }
                usleep(10000);
            } while (true);
            $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
            return ['exit' => $status['exitcode'], 'stderr' => $stderr, 'stdout' => $stdout, 'json' => $status['exitcode'] === 0 ? json_decode($stdout, true, 512, JSON_THROW_ON_ERROR) : null];
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) fclose($pipe); }
            if (is_resource($process)) { $status = proc_get_status($process); if ($status['running']) proc_terminate($process, 9); proc_close($process); }
            unlink($script);
        }
    }

    /** A capture transport never recursively normalizes or decodes the search tree. */
    private static function capture(?BaseCheckpointSerializer $serde = null, ?MongoCollectionInterface $collection = null): array
    {
        $collection ??= new class () implements MongoCollectionInterface {
            public array $updates = [];
            public function updateOne(array $filter, array $update, array $options = []): void { $this->updates[] = [$filter, $update, $options]; }
            public function find(array $filter = [], array $sort = [], ?int $limit = null): array { return []; }
            public function findOne(array $filter, array $sort = []): ?array { return null; }
            public function insertMany(array $documents): void {}
            public function deleteMany(array $filter): int { return 0; }
            public function createIndex(array $keys, array $options = []): string { return 'index'; }
        };
        $db = new class ($collection) implements MongoDatabaseInterface {
            public function __construct(private readonly MongoCollectionInterface $collection) {}
            public function collection(string $name): MongoCollectionInterface { return $this->collection; }
        };
        $client = new class ($db) implements MongoClientInterface {
            public function __construct(private readonly MongoDatabaseInterface $database) {}
            public function appendMetadata(array $metadata): void {}
            public function db(?string $name = null): MongoDatabaseInterface { return $this->database; }
        };
        return [new MongoDBSaver($client, serde: $serde), $collection];
    }

    private static function opaqueSerde(): BaseCheckpointSerializer
    {
        return new class () extends BaseCheckpointSerializer {
            public array $calls = [];
            public function dumpsTyped(mixed $data): array { $this->calls[] = $data; return ['custom', 'opaque-' . count($this->calls)]; }
            public function loadsTyped(string $type, string $data): mixed { throw new \LogicException('Not needed by capture.'); }
        };
    }
}
