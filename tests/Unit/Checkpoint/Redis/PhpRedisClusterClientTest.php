<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint\Redis;

use LangGraph\Checkpoint\Redis\PhpRedisClient;
use LangGraph\Checkpoint\Redis\RedisClientException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpRedisClient::class)]
#[RequiresPhpExtension('redis')]
final class PhpRedisClusterClientTest extends TestCase
{
    /** A socket-free ext-redis transport, including its distinct raw-command signature. */
    public static function fake(): \RedisCluster
    {
        return new class extends \RedisCluster {
            /** @var list<array<mixed>> */
            public array $calls = [];
            public mixed $reply = true;
            public ?string $error = null;
            public ?string $replyError = null;
            public ?\Throwable $failure = null;
            public bool $literal = false;
            public int $closes = 0;
            public int $clears = 0;
            public bool $documentsEnabled = false;
            public bool $noMasters = false;
            /** @var list<array{string, int}> */
            public array $masters = [['node-b', 7001], ['node-a', 7000]];
            public ?string $warning = null;
            public ?\Closure $diagnostic = null;
            /** @var array<string, string> */
            public array $documents = [];

            public function __construct()
            {
            }

            public function _masters(): array
            {
                return $this->noMasters ? [] : $this->masters;
            }

            public function rawCommand(array|string $key_or_address, string $command, mixed ...$args): mixed
            {
                $this->calls[] = [$key_or_address, $command, ...$args];
                if ($this->diagnostic !== null) {
                    ($this->diagnostic)();
                }
                if ($this->warning !== null) {
                    trigger_error($this->warning, E_USER_WARNING);
                }
                $this->error = $this->replyError;
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                if ($this->replyError !== null) {
                    return false;
                }
                if ($this->documentsEnabled) {
                    if ($command === 'JSON.SET') {
                        if (($args[3] ?? null) === 'NX' && isset($this->documents[$args[0]])) {
                            return false;
                        }
                        $this->documents[$args[0]] = $args[2];

                        return true;
                    }
                    if ($command === 'JSON.GET') {
                        return $this->documents[$args[0]] ?? false;
                    }
                    if ($command === 'FT.SEARCH') {
                        $hits = [0];
                        foreach ($this->documents as $key => $json) {
                            if (str_starts_with($key, $args[0] . ':')) {
                                ++$hits[0];
                                array_push($hits, $key, ['$', $json]);
                            }
                        }

                        return $hits;
                    }
                }
                if ($command === 'FT.INFO') {
                    if ($this->reply === false) {
                        return false;
                    }
                    return ['index_name', $args[0], 'num_docs', 2];
                }

                return $this->reply;
            }

            public function keys(string $pattern): \RedisCluster|array|false
            {
                $this->calls[] = ['KEYS', $pattern];
                $this->error = $this->replyError;
                if ($this->replyError !== null) {
                    return false;
                }

                return ['checkpoint:a', 'checkpoint:b'];
            }

            public function del(array|string $key, string ...$other_keys): \RedisCluster|int|false
            {
                $this->calls[] = ['DEL', $key, ...$other_keys];

                return count((array) $key) + count($other_keys);
            }

            public function getOption(int $option): mixed
            {
                return $this->literal;
            }

            public function exists(mixed $key, mixed ...$other_keys): \RedisCluster|int|bool
            {
                $this->calls[] = ['EXISTS', $key];

                return isset($this->documents[$key]) ? 1 : 0;
            }

            public function expire(string $key, int $timeout, ?string $mode = null): \RedisCluster|bool
            {
                $this->calls[] = ['EXPIRE', $key, $timeout];

                return isset($this->documents[$key]);
            }

            public function zAdd(string $key, array|float $score_or_options, mixed ...$more_scores_and_mems): \RedisCluster|int|float|false
            {
                $this->calls[] = ['ZADD', $key, $score_or_options, ...$more_scores_and_mems];

                return 1;
            }

            public function zRange(string $key, mixed $start, mixed $end, array|bool|null $options = null): \RedisCluster|array|bool
            {
                $this->calls[] = ['ZRANGE', $key, $start, $end];

                return [];
            }

            public function setOption(int $option, mixed $value): bool
            {
                $this->literal = $value;

                return true;
            }

            public function clearLastError(): bool
            {
                ++$this->clears;
                $this->error = null;

                return true;
            }

            public function getLastError(): ?string
            {
                return $this->error;
            }

            public function close(): bool
            {
                ++$this->closes;
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return true;
            }
        };
    }

    public function testFactoryDecodesCommonAclAndTlsForEveryDiscoveredNode(): void
    {
        $fake = self::fake();
        $arguments = [];
        $client = PhpRedisClient::fromCluster([
            ['url' => 'rediss://fake%20user:fake%40password@node-a:7000/0'],
            ['url' => 'rediss://fake%20user:fake%40password@node-b:7001'],
        ], function (...$args) use ($fake, &$arguments): \RedisCluster {
            $arguments = $args;

            return $fake;
        });

        self::assertInstanceOf(PhpRedisClient::class, $client);
        self::assertSame([null, ['node-a:7000', 'node-b:7001'], 0, 0, false, ['fake user', 'fake@password'], []], $arguments);
    }

    public function testFactorySupportsPasswordOnlyAndNoAuthentication(): void
    {
        foreach (['redis://:fake%3Apassword@node-a:7000' => 'fake:password', 'redis://node-a' => null] as $url => $expected) {
            $arguments = [];
            PhpRedisClient::fromCluster([['url' => $url]], function (...$args) use (&$arguments): \RedisCluster {
                $arguments = $args;

                return self::fake();
            });
            self::assertSame($expected, $arguments[5]);
            self::assertNull($arguments[6]);
        }
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidSeeds(): iterable
    {
        yield 'empty' => [[]];
        yield 'missing url' => [[[]]];
        yield 'wrong type' => [[['url' => 123]]];
        yield 'scheme' => [[['url' => 'http://node-a']]];
        yield 'database' => [[['url' => 'redis://node-a/1']]];
        yield 'zero port' => [[['url' => 'redis://node-a:0']]];
        yield 'bad port' => [[['url' => 'redis://node-a:65536']]];
        yield 'username without password' => [[['url' => 'redis://fake-user@node-a']]];
        yield 'query' => [[['url' => 'redis://node-a?unknown=1']]];
        yield 'mixed TLS' => [[['url' => 'redis://node-a'], ['url' => 'rediss://node-b']]];
        yield 'mixed auth' => [[['url' => 'redis://:fake-a@node-a'], ['url' => 'redis://:fake-b@node-b']]];
    }

    #[DataProvider('invalidSeeds')]
    public function testInvalidConfigurationNeverConnects(array $seeds): void
    {
        $connected = false;
        try {
            PhpRedisClient::fromCluster($seeds, function () use (&$connected): \RedisCluster {
                $connected = true;

                return self::fake();
            });
            self::fail('Expected invalid cluster configuration.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('fake-a', $e->getMessage());
            self::assertFalse($connected);
        }
    }

    public function testJsonCommandsRouteByDocumentKeyWithoutDuplicatingOrDroppingWireArguments(): void
    {
        $fake = self::fake();
        $client = new PhpRedisClient($fake);
        self::assertTrue($client->jsonSet('store:one', '$', '{}', true));
        $fake->reply = '{}';
        self::assertSame('{}', $client->jsonGet('store:two'));
        self::assertSame([
            ['store:one', 'JSON.SET', 'store:one', '$', '{}', 'NX'],
            ['store:two', 'JSON.GET', 'store:two'],
        ], $fake->calls);
    }

    public function testSearchCommandsShareCoordinatorAndKeepBinaryVectorParameters(): void
    {
        $fake = self::fake();
        $client = new PhpRedisClient($fake);
        $client->ftCreate('store', ['$.key' => ['type' => 'TAG', 'as' => 'key']], 'store:');
        $fake->reply = [1, 'store:one', ['$', '{}']];
        self::assertSame([['id' => 'store:one', 'value' => '{}']], $client->ftSearch('store', '*', 0, 10, '', false, params: ['vector' => "\0\xff"], dialect: 2));
        self::assertSame(2, $client->ftInfo('store')['num_docs']);
        self::assertFalse($fake->literal);
        foreach ($fake->calls as $call) {
            self::assertSame(['node-a', 7000], $call[0]);
        }
        self::assertContains("\0\xff", $fake->calls[1]);
    }

    public function testClusterBuiltinsRetainNativeGlobalKeysAndSlotSplittingDelete(): void
    {
        $fake = self::fake();
        $client = new PhpRedisClient($fake);
        self::assertSame(['checkpoint:a', 'checkpoint:b'], $client->keys('checkpoint:*'));
        self::assertSame(2, $client->del(['{a}:one', '{b}:two']));
        self::assertSame(0, $client->del([]));
        self::assertSame([['KEYS', 'checkpoint:*'], ['DEL', ['{a}:one', '{b}:two']]], $fake->calls);
    }

    public function testClusterExceptionIsNormalizedAndLiteralOptionRestored(): void
    {
        $fake = self::fake();
        $fake->failure = new \RedisClusterException('no such index');
        try {
            (new PhpRedisClient($fake))->ftInfo('missing');
            self::fail('Expected module error.');
        } catch (RedisClientException $e) {
            self::assertSame('no such index', $e->getMessage());
            self::assertSame($fake->failure, $e->getPrevious());
        }
        self::assertFalse($fake->literal);
        self::assertGreaterThan(0, $fake->clears);
    }

    public function testBuiltinErrorsAreCheckedBeforeArrayConversion(): void
    {
        $fake = self::fake();
        $fake->replyError = 'CLUSTERDOWN';
        $this->expectException(RedisClientException::class);
        $this->expectExceptionMessage('CLUSTERDOWN');
        (new PhpRedisClient($fake))->keys('checkpoint:*');
    }

    public function testNativeSingleKeyCommandsRemainSlotRoutedByRedisCluster(): void
    {
        $fake = self::fake();
        $fake->documents['key'] = '{}';
        $client = new PhpRedisClient($fake);
        self::assertSame(1, $client->exists('key'));
        self::assertTrue($client->expire('key', 60));
        self::assertSame(1, $client->zAdd('registry', [['score' => 1, 'value' => 'member']]));
        self::assertSame([], $client->zRange('registry', 0, -1));
        self::assertSame(0, $client->zAdd('registry', []));
        self::assertSame([
            ['EXISTS', 'key'], ['EXPIRE', 'key', 60], ['ZADD', 'registry', 1.0, 'member'], ['ZRANGE', 'registry', 0, -1],
        ], $fake->calls);
    }

    public function testFactoryConnectionErrorIsNormalized(): void
    {
        $this->expectException(RedisClientException::class);
        $this->expectExceptionMessage('CLUSTERDOWN');
        PhpRedisClient::fromCluster([['url' => 'redis://node-a']], static function (): \RedisCluster {
            throw new \RedisClusterException('CLUSTERDOWN');
        });
    }

    public function testFalseServerErrorsRemainDistinctFromMissingDocumentsAndDeclinedNx(): void
    {
        $fake = self::fake();
        $client = new PhpRedisClient($fake);
        $fake->reply = false;
        self::assertNull($client->jsonGet('absent'));
        self::assertFalse($client->jsonSet('exists', '$', '{}', true));
        $fake->replyError = 'ERR unknown command JSON.GET';
        try {
            $client->jsonGet('broken');
            self::fail('Expected server error, not a missing document.');
        } catch (RedisClientException $e) {
            self::assertSame('ERR unknown command JSON.GET', $e->getMessage());
        }
        self::assertNull($fake->error);
        $fake->replyError = null;
        $fake->reply = '{}';
        self::assertSame('{}', $client->jsonGet('recovered'));
    }

    public function testMissingCoordinatorFailsHonestly(): void
    {
        $fake = self::fake();
        $fake->noMasters = true;
        $this->expectException(RedisClientException::class);
        $this->expectExceptionMessage('no Search coordinator');
        (new PhpRedisClient($fake))->ftInfo('store');
    }

    /** Defensive normalization fallback, not a reachable native rawCommand driver trace. */
    public function testDefensiveLastErrorFallbackPreservesServerTextFromAnExceptionTransport(): void
    {
        $fake = self::fake();
        $fake->failure = new \RedisClusterException('Unable to send command to the specified node');
        $fake->replyError = 'Index already exists';
        $this->expectException(RedisClientException::class);
        $this->expectExceptionMessage('Index already exists');
        (new PhpRedisClient($fake))->ftCreate('store', [], 'store:');
    }

    public static function searchOperations(): iterable
    {
        yield 'create' => ['create'];
        yield 'search' => ['search'];
        yield 'info' => ['info'];
    }

    #[DataProvider('searchOperations')]
    public function testFalseSearchReplyWithNoLastErrorCannotBeAnEmptySuccess(string $operation): void
    {
        $fake = self::fake();
        $client = new PhpRedisClient($fake);
        $client->ftInfo('store');
        $fake->reply = false;
        try {
            match ($operation) {
                'create' => $client->ftCreate('store', [], 'store:'),
                'search' => $client->ftSearch('store', '*', 0, 10, '', false),
                'info' => $client->ftInfo('store'),
            };
            self::fail('A failed Search reply must throw, even without lastError.');
        } catch (RedisClientException $e) {
            self::assertStringContainsString('returned false', $e->getMessage());
        }
        self::assertNull($fake->error);
        self::assertFalse($fake->literal);
        // Repick on the caller's next operation, not an automatic retry.
        $fake->masters = [['node-b', 7001]];
        $fake->reply = true;
        self::assertSame(2, $client->ftInfo('store')['num_docs']);
        self::assertSame(['node-b', 7001], $fake->calls[array_key_last($fake->calls)][0]);
    }

    public function testUnknownNodeWarningBecomesVisibleExceptionAndNextCallRepicksCoordinator(): void
    {
        $fake = self::fake();
        $client = new PhpRedisClient($fake);
        $client->ftInfo('store');
        $fake->warning = 'RedisCluster::rawcommand(): Unknown node node-a:7000';
        $fake->reply = false;
        try {
            $client->ftSearch('store', '*', 0, 10, '', false);
            self::fail('Expected the warning as an exception.');
        } catch (RedisClientException $e) {
            self::assertStringContainsString('Unknown node', $e->getMessage());
        }
        $fake->warning = null;
        $fake->masters = [['node-b', 7001]];
        $fake->reply = true;
        self::assertSame(2, $client->ftInfo('store')['num_docs']);
        self::assertSame(['node-b', 7001], $fake->calls[2][0]);
    }

    public function testRawWarningFailureRestoresTheCallersErrorHandler(): void
    {
        $handler = static fn (int $severity, string $message): bool => true;
        set_error_handler($handler);
        try {
            $fake = self::fake();
            $fake->warning = 'Unknown node';
            try {
                (new PhpRedisClient($fake))->ftInfo('store');
                self::fail('Expected warning normalization.');
            } catch (RedisClientException) {
            }
            $restored = set_error_handler($handler);
            restore_error_handler();
            self::assertSame($handler, $restored);
        } finally {
            restore_error_handler();
        }
    }

    public function testQuitClosesTheTransportAndNormalizesCloseFailures(): void
    {
        $fake = self::fake();
        $client = new PhpRedisClient($fake);
        $client->quit();
        self::assertSame(1, $fake->closes);
        $fake->failure = new \RedisClusterException('connection lost');
        $this->expectException(RedisClientException::class);
        $client->quit();
    }

    private static function currentErrorHandler(): ?callable
    {
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $handler;
    }

    public static function delegatedDiagnostics(): iterable
    {
        foreach ([E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED] as $severity) {
            foreach (['handled' => true, 'unhandled' => false, 'void' => null] as $label => $result) {
                yield $severity . '-' . $label => [$severity, $result];
            }
        }
    }

    #[DataProvider('delegatedDiagnostics')]
    public function testNonWarningDiagnosticsDelegateEveryArgumentAndReturnValue(int $severity, ?bool $result): void
    {
        $seen = [];
        $handler = static function (...$args) use (&$seen, $result): ?bool {
            $seen[] = $args;

            return $result;
        };
        set_error_handler($handler);
        try {
            $fake = self::fake();
            $actual = 'not called';
            $fake->diagnostic = static function () use (&$actual, $severity): void {
                // Invoke the installed handler as the engine would. Returning false must
                // remain observable without emitting an unhandled diagnostic into PHPUnit.
                $actual = (self::currentErrorHandler())($severity, 'driver diagnostic', 'driver.c', 123);
            };
            self::assertSame(2, (new PhpRedisClient($fake))->ftInfo('store')['num_docs']);
            self::assertSame([[$severity, 'driver diagnostic', 'driver.c', 123]], $seen);
            self::assertSame($result, $actual);
            self::assertSame($handler, self::currentErrorHandler());
            self::assertFalse($fake->literal);
        } finally {
            restore_error_handler();
        }
    }

    public function testActualUserNoticeReachesPreviousHandlerDuringRawCommand(): void
    {
        $seen = [];
        $handler = static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = [$severity, $message];

            return true;
        };
        set_error_handler($handler);
        try {
            $fake = self::fake();
            $fake->diagnostic = static fn () => trigger_error('driver notice', E_USER_NOTICE);
            self::assertSame(2, (new PhpRedisClient($fake))->ftInfo('store')['num_docs']);
            self::assertSame([[E_USER_NOTICE, 'driver notice']], $seen);
            self::assertSame($handler, self::currentErrorHandler());
        } finally {
            restore_error_handler();
        }
    }

    public function testNoPreviousHandlerFallsBackToFalseForNonWarnings(): void
    {
        set_error_handler(null);
        try {
            $fake = self::fake();
            $actual = null;
            $fake->diagnostic = static function () use (&$actual): void {
                $actual = (self::currentErrorHandler())(E_NOTICE, 'driver notice', 'driver.c', 123);
            };
            self::assertSame(2, (new PhpRedisClient($fake))->ftInfo('store')['num_docs']);
            self::assertFalse($actual);
            self::assertNull(self::currentErrorHandler());
        } finally {
            restore_error_handler();
        }
    }

    public function testPreviousHandlerExceptionPropagatesAndRestoresBothHandlerAndReplyOption(): void
    {
        $failure = new \LogicException('caller handler failure');
        $handler = static function () use ($failure): never { throw $failure; };
        set_error_handler($handler);
        try {
            $fake = self::fake();
            $fake->diagnostic = static function (): void {
                (self::currentErrorHandler())(E_DEPRECATED, 'driver deprecation', 'driver.c', 123);
            };
            try {
                (new PhpRedisClient($fake))->ftInfo('store');
                self::fail('Previous handler exception must propagate.');
            } catch (\LogicException $error) {
                self::assertSame($failure, $error);
            }
            self::assertSame($handler, self::currentErrorHandler());
            self::assertFalse($fake->literal);
        } finally {
            restore_error_handler();
        }
    }

    public static function warningSeverities(): iterable
    {
        yield 'native' => [E_WARNING];
        yield 'user' => [E_USER_WARNING];
    }

    #[DataProvider('warningSeverities')]
    public function testWarningsStayNormalizedAndNeverReachPreviousHandler(int $severity): void
    {
        $seen = [];
        $handler = static function (...$args) use (&$seen): bool {
            $seen[] = $args;

            return true;
        };
        set_error_handler($handler);
        try {
            $fake = self::fake();
            $fake->diagnostic = static function () use ($severity): void {
                (self::currentErrorHandler())($severity, 'Unknown node', 'driver.c', 123);
            };
            try {
                (new PhpRedisClient($fake))->ftInfo('store');
                self::fail('Warning must become a RedisClientException.');
            } catch (RedisClientException $error) {
                self::assertSame('Unknown node', $error->getMessage());
            }
            self::assertSame([], $seen);
            self::assertSame($handler, self::currentErrorHandler());
            self::assertFalse($fake->literal);
        } finally {
            restore_error_handler();
        }
    }


    #[DataProvider('searchOperations')]
    public function testClusterFalseSearchRepliesPreserveServerText(string $operation): void
    {
        $fake = self::fake();
        $fake->replyError = 'ERR native Search failure';
        try {
            $client = new PhpRedisClient($fake);
            match ($operation) {
                'create' => $client->ftCreate('store', [], 'store:'),
                'search' => $client->ftSearch('store', '*', 0, 10, '', false),
                'info' => $client->ftInfo('store'),
            };
            self::fail('Server error must propagate.');
        } catch (RedisClientException $error) {
            self::assertSame('ERR native Search failure', $error->getMessage());
        }
        self::assertNull($fake->error);
        self::assertFalse($fake->literal);
    }

    public function testNativeTransportExceptionRestoresPreviousHandlerAndReplyOption(): void
    {
        $handler = static fn (): bool => true;
        set_error_handler($handler);
        try {
            $fake = self::fake();
            $fake->failure = new \RedisClusterException('transport failure');
            try {
                (new PhpRedisClient($fake))->ftInfo('store');
                self::fail('Transport exception must propagate.');
            } catch (RedisClientException $error) {
                self::assertSame('transport failure', $error->getMessage());
            }
            self::assertSame($handler, self::currentErrorHandler());
            self::assertFalse($fake->literal);
        } finally {
            restore_error_handler();
        }
    }

}
