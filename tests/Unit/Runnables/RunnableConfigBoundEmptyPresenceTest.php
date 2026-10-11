<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Retrievers\BaseRetriever;
use LangChain\Schema\Document;
use LangChain\Tracers\CallbackHandler;
use LangChain\Tracers\CallbackManagerForRetrieverRun;
use LangChain\Utils\AsyncLocalStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/** Callback-key presence only; graph publication, ancestry and sibling policy remain separate. */
#[CoversClass(RunnableConfig::class)]
final class RunnableConfigBoundEmptyPresenceTest extends TestCase
{
    private ?AsyncLocalStorage $previousProvider;

    protected function setUp(): void
    {
        $this->previousProvider = AsyncLocalStorage::getGlobalInstance();
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
    }

    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance($this->previousProvider);
    }

    public static function shapes():array
    {
        return [
            'direct omission' => ['direct-omitted',['A']],
            'direct tracked empty' => ['direct-empty',[]],
            'binding omission' => ['bind-omitted',['A']],
            'bound loose empty' => ['bound-empty',[]],
            'public withConfig empty' => ['with-empty',[]],
            'chained withConfig preserves empty' => ['chain-empty',[]],
            'caller loose empty' => ['caller-empty',[]],
            'caller with tracked empty' => ['caller-with-empty',[]],
            'caller constructor empty omission' => ['caller-ctor-empty',['A']],
            'caller null omission' => ['caller-null',['A']],
            'bound null omission' => ['bound-null',['A']],
            'factory loose empty' => ['factory-empty',[]],
            'factory tracked DTO empty' => ['factory-dto-empty',[]],
            'factory constructor empty omission' => ['factory-ctor-empty',['A']],
            'factory omitted' => ['factory-omitted',['A']],
            'kwargs loose empty' => ['kwargs-empty',[]],
            'bound nonempty plus caller empty composes' => ['nonempty-bound-caller-empty',['B']],
            'bound nonempty plus factory empty composes' => ['nonempty-bound-factory-empty',['B']],
            'empty bound plus nonempty caller composes' => ['empty-bound-caller-nonempty',['C']],
            'nonempty duplicates retain cardinality' => ['duplicate-nonempty',['B','B','C']],
        ];
    }

    #[DataProvider('shapes')]
    public function testActualBindingLambdaAndRetrieverCallbacks(string$shape,array$expected):void
    {
        $events = [];$received = null;$factoryCalls = 0;$factorySnapshots = [];
        $handler = static function(string$name)use(&$events):CallbackHandler{
            $hooks = [];foreach(['handleLLMStart','handleChatModelStart','handleChainStart','handleToolStart','handleRetrieverStart','handleLLMEnd','handleLLMError','handleChainEnd','handleChainError','handleToolEnd','handleToolError','handleRetrieverEnd','handleRetrieverError']as$hook){
                $hooks[$hook] = static function(...$args)use(&$events,$name,$hook):void{$events[] = ['name' => $name,'hook' => $hook,'args' => $args];};
            }
            return new CallbackHandler($hooks,['name' => $name,'raiseError' => true]);
        };
        $a = $handler('A');$b = $handler('B');$c = $handler('C');$l = $handler('L');
        $retriever = new class(['callbacks' => [$l],'tags' => ['local'],'metadata' => ['local' => 'L']])extends BaseRetriever{
            public ?CallbackManagerForRetrieverRun$manager = null;
            protected function getRelevantDocuments(string$q,?CallbackManagerForRetrieverRun$m = null):array{$this->manager = $m;return[new Document('fixed:'.$q)];}
            public static function lcName():string{return'PlanningSyntheticRetriever';}
            public static function lcNamespace():array{return['planning','synthetic'];}
        };
        $base = new RunnableLambda(static function($input,RunnableConfig$config)use(&$received,$retriever){
            $received = $config;return$retriever->invoke($input,$config);
        });
        $caller = null;$bound = [];$kwargs = [];$factoryResult = null;$useFactory = false;$target = $base;
        switch($shape){
            case'direct-empty':$caller = RunnableConfig::fromArray(['callbacks' => []]);break;
            case'bound-empty':$bound = ['callbacks' => []];break;
            case'with-empty':$target = $base->withConfig(['callbacks' => []]);break;
            case'chain-empty':$target = $base->withConfig(['callbacks' => []])->withConfig(['tags' => ['bound']]);break;
            case'caller-empty':$caller = RunnableConfig::fromArray(['callbacks' => []]);break;
            case'caller-with-empty':$caller = (new RunnableConfig())->with(['callbacks' => []]);break;
            case'caller-ctor-empty':$caller = new RunnableConfig(callbacks:[]);break;
            case'caller-null':$caller = RunnableConfig::fromArray(['callbacks' => null]);break;
            case'bound-null':$bound = ['callbacks' => null];break;
            case'factory-empty':$useFactory = true;$factoryResult = ['callbacks' => []];break;
            case'factory-dto-empty':$useFactory = true;$factoryResult = (new RunnableConfig())->with(['callbacks' => []]);break;
            case'factory-ctor-empty':$useFactory = true;$factoryResult = new RunnableConfig(callbacks:[]);break;
            case'factory-omitted':$useFactory = true;$factoryResult = [];break;
            case'kwargs-empty':$kwargs = ['callbacks' => []];break;
            case'nonempty-bound-caller-empty':$bound = ['callbacks' => [$b]];$caller = RunnableConfig::fromArray(['callbacks' => []]);break;
            case'nonempty-bound-factory-empty':$bound = ['callbacks' => [$b]];$useFactory = true;$factoryResult = ['callbacks' => []];break;
            case'empty-bound-caller-nonempty':$bound = ['callbacks' => []];$caller = new RunnableConfig(callbacks:[$c]);break;
            case'duplicate-nonempty':$bound = ['callbacks' => [$b]];$caller = new RunnableConfig(callbacks:[$b,$c]);break;
        }
        if(!in_array($shape,['direct-omitted','direct-empty','with-empty','chain-empty'],true)){
            $factories = $useFactory?[static function(RunnableConfig$config)use(&$factoryCalls,&$factorySnapshots,$factoryResult){
                ++$factoryCalls;$factorySnapshots[] = ['callbacks' => array_map(static fn($h) => $h->name,$config->callbacks),'presence' => $config->isFieldProvided('callbacks'),'metadata' => $config->metadata];return$factoryResult;
            }]:[];
            $target = new RunnableBinding($base,kwargs:$kwargs,config:$bound,configFactories:$factories);
        }
        $ambient = new RunnableConfig(callbacks:[$a],tags:['ambient'],metadata:['ambient' => 'A']);
        $callerBefore = $caller === null?null:get_object_vars($caller);
        $out = AsyncLocalStorage::runWithConfig($ambient,static function()use($ambient,$target,$caller){
            self::assertSame($ambient,AsyncLocalStorage::getRunnableConfig());
            return $target->invoke('query',$caller);
        });
        $names = array_map(static fn($h) => $h->name,$received->callbacks);
        $observedEvents = array_map(static fn($e) => [$e['name'],$e['hook']],array_filter($events,static fn($e) => str_starts_with($e['hook'],'handleRetriever')));
        $record = ['shape' => $shape,'expectedCallbacks' => $expected,'actualCallbacks' => $names,'retrieverEvents' => array_values($observedEvents),'factoryCalls' => $factoryCalls,'factorySnapshots' => $factorySnapshots];
        self::recordObservation(json_encode($record,JSON_UNESCAPED_SLASHES)."\n");
        self::assertSame('fixed:query',$out[0]->pageContent);
        self::assertSame($callerBefore,$caller === null?null:get_object_vars($caller));
        self::assertSame([$a],$ambient->callbacks);
        self::assertSame(['ambient'],$ambient->tags);
        self::assertSame(['ambient' => 'A'],$ambient->metadata);
        self::assertSame($useFactory?1:0,$factoryCalls);
        $local = array_values(array_filter($events,static fn($e) => $e['name'] === 'L'));
        self::assertSame(['handleRetrieverStart','handleRetrieverEnd'],array_column($local,'hook'));
        self::assertSame($local[0]['args'][2],$local[1]['args'][1]);
        self::assertSame($local[0]['args'][3],$local[1]['args'][2]);
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
        self::assertSame($expected,$names,'fixed pinned callback-list presence expectation, not generated by candidate');
        $ambientEvents = array_values(array_filter($events,static fn($e) => $e['name'] === 'A'));
        self::assertSame(in_array('A',$expected,true)?['handleRetrieverStart','handleRetrieverEnd']:[],array_column($ambientEvents,'hook'));
    }

    public function testPublicMergeRetainsExplicitEmptyPresenceAcrossRepeatedComposition():void
    {
        $one = RunnableConfig::mergeConfigs(['callbacks' => []]);
        $two = RunnableConfig::mergeConfigs($one,new RunnableConfig(),[]);
        self::assertSame([],$one->callbacks);self::assertSame([],$two->callbacks);
        self::assertTrue($one->isFieldProvided('callbacks'));self::assertTrue($two->isFieldProvided('callbacks'));
    }

    public function testExistingCompositionPrecedenceNullAndDuplicateConventions():void
    {
        $b = new CallbackHandler([],['name' => 'B']);$c = new CallbackHandler([],['name' => 'C']);
        $bound = RunnableConfig::fromArray(['callbacks' => [$b],'metadata' => ['bound' => 1,'same' => 'bound'],'configurable' => ['b' => 1,'same' => 'bound'],'tags' => ['bound','same'],'run_id' => ['R'],'run_name' => 'bound-name','run_id_parent' => 'P','verbose' => true,'recursion_limit' => 7,'stop' => ['x'],'timeout' => 80]);
        $caller = RunnableConfig::fromArray(['callbacks' => [$b,$c],'metadata' => ['caller' => 2,'same' => 'caller'],'configurable' => ['c' => 2,'same' => 'caller'],'tags' => ['same','caller'],'run_id' => null,'run_name' => null,'run_id_parent' => null,'verbose' => null,'recursion_limit' => null,'stop' => null,'timeout' => 30]);
        $before = [get_object_vars($bound),get_object_vars($caller)];
        $merged = RunnableConfig::mergeConfigs($bound,$caller,['callbacks' => []]);
        self::assertSame([$b,$b,$c],$merged->callbacks);
        self::assertSame(['bound' => 1,'same' => 'caller','caller' => 2],$merged->metadata);
        self::assertSame(['b' => 1,'same' => 'caller','c' => 2],$merged->configurable);
        self::assertSame(['bound','same','caller'],$merged->tags);
        self::assertSame(['R'],$merged->runId);self::assertSame('bound-name',$merged->runName);
        self::assertSame('P',$merged->runIdParent);self::assertTrue($merged->verbose);
        self::assertSame(7,$merged->recursionLimit);self::assertSame(['x'],$merged->options['stop']);self::assertSame(30,$merged->options['timeout']);
        self::assertSame($before,[get_object_vars($bound),get_object_vars($caller)]);
        $reset = RunnableConfig::mergeConfigs($bound,['run_id' => [],'verbose' => false,'recursion_limit' => 25]);
        self::assertSame([],$reset->runId);self::assertFalse($reset->verbose);self::assertSame(25,$reset->recursionLimit);
        $constructorEmpty = new RunnableConfig(callbacks:[]);
        self::assertFalse($constructorEmpty->isFieldProvided('callbacks'));
        self::assertFalse(RunnableConfig::fromArray(['callbacks' => null])->isFieldProvided('callbacks'));
        self::assertFalse(RunnableConfig::mergeConfigs($constructorEmpty,['callbacks' => null])->isFieldProvided('callbacks'));
    }

    public function testFactorySnapshotsAreIndependentAndNormalizationFollowsComposition():void
    {
        $b = new CallbackHandler([],['name' => 'B']);$snapshots = [];$received = null;
        $base = new RunnableLambda(static function($input,RunnableConfig$config)use(&$received){$received = $config;return 'fixed';});
        $factoryOne = static function(RunnableConfig$config)use(&$snapshots){
            $snapshots[] = get_object_vars($config);$config->metadata['private'] = 'mutation';
            return ['callbacks' => [],'metadata' => ['factory' => 1]];
        };
        $factoryTwo = static function(RunnableConfig$config)use(&$snapshots){
            $snapshots[] = get_object_vars($config);return ['callbacks' => null,'metadata' => ['second' => 2]];
        };
        $bound = ['callbacks' => [$b],'run_id' => ['R'],'run_name' => 'bound','run_id_parent' => 'P','verbose' => true,'metadata' => ['bound' => 1],'tags' => ['bound']];
        $binding = new RunnableBinding($base,config:$bound,configFactories:[$factoryOne,$factoryTwo]);
        $ambient = new RunnableConfig(callbacks:[new CallbackHandler([],['name' => 'A'])],runId:['ambientR'],runName:'ambientName',runIdParent:'ambientP',metadata:['ambient' => 1]);
        $caller = new RunnableConfig();$before = get_object_vars($caller);
        self::assertSame('fixed',AsyncLocalStorage::runWithConfig($ambient,static fn() => $binding->invoke('input',$caller)));
        self::assertCount(2,$snapshots);self::assertSame($snapshots[0],$snapshots[1]);
        self::assertSame(['R'],$snapshots[0]['runId']);self::assertTrue($snapshots[0]['verbose']);self::assertSame([$b],$snapshots[0]['callbacks']);
        // The existing PHP binding helper consumes the explicit chain ID/name and forwards its parent to the child.
        self::assertSame([],$received->runId);self::assertNull($received->runName);self::assertSame('R',$received->runIdParent);self::assertTrue($received->verbose);
        self::assertSame([$b],$received->callbacks);
        self::assertSame(['bound' => 1,'factory' => 1,'second' => 2],$received->metadata);
        self::assertSame(['bound'],$received->tags);self::assertSame($before,get_object_vars($caller));
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
        $normalized = RunnableConfig::normalizeFields(null);
        self::assertSame([],$normalized->callbacks);
        // Source defaults have no callbacks key; recomposition under a new ambient must adopt A.
        $got = AsyncLocalStorage::runWithConfig($ambient,static fn() => RunnableConfig::normalizeFields(RunnableConfig::mergeConfigs($normalized)));
        self::assertSame(['A'],array_map(static fn($h) => $h->name,$got->callbacks));
    }

    public function testBoundEmptyErrorKeepsLocalHandlerAndOriginalThrowableWithNoEnd():void
    {
        $events = [];$original = new RuntimeException('fixed-original');$seen = null;
        $record = static function(string$name)use(&$events){
            return new CallbackHandler([
                'handleRetrieverStart' => static function(...$args)use(&$events,$name){$events[] = [$name,'start',$args];},
                'handleRetrieverError' => static function(...$args)use(&$events,$name){$events[] = [$name,'error',$args];},
                'handleRetrieverEnd' => static function(...$args)use(&$events,$name){$events[] = [$name,'end',$args];},
            ],['name' => $name,'raiseError' => true]);
        };
        $a = $record('A');$local = $record('L');
        $retriever = new class(['callbacks' => [$local]],$original)extends BaseRetriever{
            public function __construct(array$fields,private Throwable$original){parent::__construct($fields);}
            protected function getRelevantDocuments(string$q,?CallbackManagerForRetrieverRun$m = null):array{throw$this->original;}
            public static function lcName():string{return'PlanningErrorRetriever';}
            public static function lcNamespace():array{return['planning','synthetic'];}
        };
        $base = new RunnableLambda(static fn($input,RunnableConfig$config) => $retriever->invoke($input,$config));
        $binding = $base->withConfig(['callbacks' => []]);$ambient = new RunnableConfig(callbacks:[$a]);
        try{AsyncLocalStorage::runWithConfig($ambient,static fn() => $binding->invoke('query'));self::fail('original throwable required');}catch(Throwable$error){$seen = $error;}
        self::assertSame($original,$seen);self::assertNull(AsyncLocalStorage::getRunnableConfig());
        $localEvents = array_values(array_filter($events,static fn($e) => $e[0] === 'L'));
        self::assertSame(['start','error'],array_column($localEvents,1));
        self::assertSame($original,$localEvents[1][2][0]);
        self::assertSame($localEvents[0][2][2],$localEvents[1][2][1]);
        self::assertSame($localEvents[0][2][3],$localEvents[1][2][2]);
        self::assertSame([],array_values(array_filter($events,static fn($e) => $e[0] === 'A')));
    }
    public static function normalizedShapes():iterable
    {
        foreach([
            'default-null' => ['A'],
            'default-array' => ['A'],
            'default-constructor' => ['A'],
            'constructor-empty' => ['A'],
            'nullable-null' => ['A'],
            'tracked-array-empty' => [],
            'tracked-with-empty' => [],
            'tracked-empty-repeat-normalization' => [],
            'ambient-explicit-empty' => [],
            'normalized-nonempty' => ['B'],
            'ordinary-nonempty' => ['B'],
            'ambient-nonempty' => ['B'],
        ]as$shape => $expected){
            foreach(['normalize','repeated-merge','binding-caller','binding-factory']as$route)yield$shape.' '.$route => [$shape,$route,$expected];
        }
    }
    #[DataProvider('normalizedShapes')]
    public function testFixedPinnedCallbackPresenceUnderChangedAmbient(string$shape,string$route,array$expected):void
    {
        $events = [];$seen = null;
        $handler = static function(string$name)use(&$events):CallbackHandler{
            return new CallbackHandler([
                'handleRetrieverStart' => static function(...$args)use(&$events,$name){$events[] = [$name,'start',$args];},
                'handleRetrieverEnd' => static function(...$args)use(&$events,$name){$events[] = [$name,'end',$args];},
                'handleRetrieverError' => static function(...$args)use(&$events,$name){$events[] = [$name,'error',$args];},
            ],['name' => $name,'raiseError' => true]);
        };
        $a = $handler('A');$b = $handler('B');$local = $handler('L');
        $dto = match($shape){
            'default-null' => RunnableConfig::normalizeFields(),
            'default-array' => RunnableConfig::normalizeFields([]),
            'default-constructor' => RunnableConfig::normalizeFields(new RunnableConfig()),
            'constructor-empty' => RunnableConfig::normalizeFields(new RunnableConfig(callbacks:[])),
            'nullable-null' => RunnableConfig::normalizeFields(RunnableConfig::fromArray(['callbacks' => null])),
            'tracked-array-empty' => RunnableConfig::normalizeFields(['callbacks' => []]),
            'tracked-with-empty' => RunnableConfig::normalizeFields((new RunnableConfig())->with(['callbacks' => []])),
            'tracked-empty-repeat-normalization' => RunnableConfig::normalizeFields(RunnableConfig::normalizeFields(['callbacks' => []])),
            'ambient-explicit-empty' => AsyncLocalStorage::runWithConfig(RunnableConfig::fromArray(['callbacks' => []]),static fn() => RunnableConfig::normalizeFields()),
            'normalized-nonempty' => RunnableConfig::normalizeFields(new RunnableConfig(callbacks:[$b])),
            'ordinary-nonempty' => new RunnableConfig(callbacks:[$b]),
            'ambient-nonempty' => AsyncLocalStorage::runWithConfig(new RunnableConfig(callbacks:[$b]),static fn() => RunnableConfig::normalizeFields()),
        };
        self::assertNull(AsyncLocalStorage::getRunnableConfig());
        $before = get_object_vars($dto);$provider = AsyncLocalStorage::getGlobalInstance();
        $leaf = new class(['callbacks' => [$local]])extends BaseRetriever{
            protected function getRelevantDocuments(string$q,?CallbackManagerForRetrieverRun$m = null):array{return[new Document('fixed:'.$q)];}
            public static function lcName():string{return'NormalizedPresencePlanningLeaf';}
            public static function lcNamespace():array{return['planning','synthetic'];}
        };
        $base = new RunnableLambda(static function($input,RunnableConfig$config)use(&$seen,$leaf){$seen = $config;return$leaf->invoke($input,$config);});
        $ambient = new RunnableConfig(callbacks:[$a]);$outer = RunnableConfig::fromArray(['callbacks' => [$b]]);
        $factoryCount = 0;
        $result = AsyncLocalStorage::runWithConfig($outer,static function()use($outer,$ambient,$base,$dto,$route,&$factoryCount){
            $result = AsyncLocalStorage::runWithConfig($ambient,static function()use($base,$dto,$route,&$factoryCount){
                return match($route){
                    'normalize' => $base->invoke('query',RunnableConfig::normalizeFields($dto)),
                    'repeated-merge' => $base->invoke('query',RunnableConfig::mergeConfigs(RunnableConfig::mergeConfigs($dto,new RunnableConfig()),[])),
                    'binding-caller' => (new RunnableBinding($base))->invoke('query',$dto),
                    'binding-factory' => (new RunnableBinding($base,configFactories:[static function(RunnableConfig$config)use($dto,&$factoryCount){++$factoryCount;return$dto;}]))->invoke('query'),
                };
            });
            self::assertSame($outer,AsyncLocalStorage::getRunnableConfig());return$result;
        });
        $names = array_map(static fn($h) => $h->name,$seen->callbacks);
        self::recordObservation(json_encode(['shape' => $shape,'route' => $route,'literalExpected' => $expected,'observed' => $names,'dtoCallbacksPresence' => $dto->isFieldProvided('callbacks'),'observedRetrieverEvents' => array_map(static fn($e) => [$e[0],$e[1]],$events)],JSON_UNESCAPED_SLASHES)."\n");
        self::assertSame('fixed:query',$result[0]->pageContent);self::assertSame($before,get_object_vars($dto));
        self::assertSame($route === 'binding-factory'?1:0,$factoryCount);
        self::assertNull(AsyncLocalStorage::getRunnableConfig());self::assertSame($provider,AsyncLocalStorage::getGlobalInstance());
        $localEvents = array_values(array_filter($events,static fn($e) => $e[0] === 'L'));
        self::assertSame(['start','end'],array_column($localEvents,1));self::assertSame($localEvents[0][2][2],$localEvents[1][2][1]);self::assertSame($localEvents[0][2][3],$localEvents[1][2][2]);
        self::assertSame($expected,$names,'literal pinned callback-key behavior under a changed ambient; constructor/null are PHP omission adaptations');
        foreach(['A','B']as$name){
            $observed = array_values(array_filter($events,static fn($e) => $e[0] === $name));
            self::assertSame(in_array($name,$expected,true)?['start','end']:[],array_column($observed,1));
        }
    }
    public function testCallbackOnlyDefaultPresenceReconsiderationKeepsSiblingContracts():void
    {
        $default = RunnableConfig::normalizeFields();
        self::assertFalse($default->isFieldProvided('callbacks'),'Explicit parent decision(b): omitted callback defaults do not acquire supplied presence.');
        foreach(['tags','metadata','configurable','options','runId','recursionLimit','verbose']as$field)self::assertTrue($default->isFieldProvided($field));
        self::assertTrue(RunnableConfig::normalizeFields(['callbacks' => []])->isFieldProvided('callbacks'));
        self::assertTrue(RunnableConfig::normalizeFields(new RunnableConfig(callbacks:[new CallbackHandler([])]))->isFieldProvided('callbacks'));
    }
    public function testOptionalTrackedMarkerNarrowingLeavesOrdinaryNonemptyPrivateStateEqual():void
    {
        $b = new CallbackHandler([],['name' => 'B']);$input = new RunnableConfig(callbacks:[$b]);
        $merged = RunnableConfig::mergeConfigs($input,new RunnableConfig());
        self::assertEquals(new RunnableConfig(callbacks:[$b]),$merged,'No new marker is needed for an ordinary nonempty fallback list.');
        self::assertSame([$b],$merged->callbacks);self::assertTrue($merged->isFieldProvided('callbacks'));
    }

    public static function serializedPresenceCases(): iterable
    {
        yield 'normalized omitted' => [false, ['recursionLimit', 'verbose', 'tags', 'metadata', 'runId', 'configurable', 'options']];
        yield 'normalized tracked empty' => [true, ['callbacks', 'recursionLimit', 'verbose', 'tags', 'metadata', 'runId', 'configurable', 'options']];
    }

    #[DataProvider('serializedPresenceCases')]
    public function testActualSerializationAndPrivateMarkerOrder(bool $tracked, array $expectedOrder): void
    {
        $caller = $tracked ? RunnableConfig::fromArray(['callbacks' => []]) : new RunnableConfig();
        $property = new ReflectionProperty(RunnableConfig::class, 'providedFields');
        $before = [$property->getValue($caller), get_object_vars($caller), serialize($caller)];
        $config = RunnableConfig::normalizeFields($caller);
        $expected = array_fill_keys($expectedOrder, true);
        self::recordObservation(json_encode([
            'case' => $tracked ? 'normalized tracked empty' : 'normalized omitted',
            'serializedConfigBase64' => base64_encode(serialize($config)),
            'actualProvidedFields' => $property->getValue($config),
            'actualProvidedOrder' => array_keys($property->getValue($config)),
            'callerProvidedFields' => $property->getValue($caller),
            'publicFields' => get_object_vars($config),
        ], JSON_UNESCAPED_SLASHES) . "\n");
        self::assertSame($expected, $property->getValue($config), 'Literal callback-only bookkeeping and insertion order.');
        self::assertSame($expectedOrder, array_keys($property->getValue($config)));
        self::assertSame($tracked, $config->isFieldProvided('callbacks'));
        foreach (['tags', 'metadata', 'configurable', 'options', 'runId', 'recursionLimit', 'verbose'] as $sibling) {
            self::assertTrue($config->isFieldProvided($sibling));
        }
        self::assertSame([], $config->callbacks);
        self::assertSame([], $config->tags);
        self::assertSame([], $config->metadata);
        self::assertSame([], $config->configurable);
        self::assertSame([], $config->options);
        self::assertSame([], $config->runId);
        self::assertSame(25, $config->recursionLimit);
        self::assertFalse($config->verbose);
        $serialized = serialize($config);
        self::assertStringContainsString(serialize($expected), $serialized);
        $roundTrip = unserialize($serialized, ['allowed_classes' => [RunnableConfig::class]]);
        self::assertInstanceOf(RunnableConfig::class, $roundTrip);
        self::assertNotSame($config, $roundTrip);
        self::assertSame($expected, $property->getValue($roundTrip));
        self::assertSame($expectedOrder, array_keys($property->getValue($roundTrip)));
        self::assertSame($serialized, serialize($roundTrip));
        self::assertSame(get_object_vars($config), get_object_vars($roundTrip));
        self::assertSame($before, [$property->getValue($caller), get_object_vars($caller), serialize($caller)]);
        self::assertNull(AsyncLocalStorage::getRunnableConfig());

    }

    private static function recordObservation(string $line): void
    {
        $path = getenv('PLAN_OBSERVATIONS');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, $line, FILE_APPEND);
        }
    }
}
