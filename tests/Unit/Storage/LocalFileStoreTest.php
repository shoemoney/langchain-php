<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Storage;

use LangChain\Storage\LocalFileStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Converted from `storage/tests/file_system.test.ts`. Every test works in its own
 * temp directory, removed in tearDown.
 *
 * Not converted: "queues writes for the same key while a lock is held". It spies on a
 * private async method and gates a promise; this port is synchronous, has no
 * `keyLocks`, and two writes can never interleave.
 */
#[CoversClass(LocalFileStore::class)]
final class LocalFileStoreTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            self::removeTree($dir);
        }
        $this->cleanup = [];
    }

    private function tempDir(string $label = 'file_system_store_test'): string
    {
        $dir = sys_get_temp_dir() . '/' . $label . '_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->cleanup[] = $dir;

        return $dir;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    public function testCanWriteAndReadValues(): void
    {
        $store = LocalFileStore::fromPath($this->tempDir());
        $value1 = date('c');
        $value2 = date('c') . date('c');

        $store->mset([['key1', $value1], ['key2', $value2]]);
        $retrieved = $store->mget(['key1', 'key2']);

        self::assertSame([$value1, $value2], $retrieved);
    }

    public function testUsesLastValueForDuplicateKeysInMset(): void
    {
        $store = LocalFileStore::fromPath($this->tempDir());

        $store->mset([['duplicate-key', 'first'], ['duplicate-key', 'second']]);

        self::assertSame(['second'], $store->mget(['duplicate-key']));
    }

    public function testRemovesOrphanedTempFilesDuringInitialization(): void
    {
        $dir = $this->tempDir('file_system_store_cleanup');
        file_put_contents($dir . '/orphan.tmp', 'stale');

        LocalFileStore::fromPath($dir);

        self::assertNotContains('orphan.tmp', scandir($dir));
    }

    public function testCanDeleteValues(): void
    {
        $store = LocalFileStore::fromPath($this->tempDir());
        $store->mset([['key1', 'a'], ['key2', 'b']]);

        $store->mdelete(['key1', 'key2']);

        self::assertSame([null, null], $store->mget(['key1', 'key2']));
    }

    public function testDeletingAMissingKeyIsIdempotent(): void
    {
        $store = LocalFileStore::fromPath($this->tempDir());

        $store->mdelete(['never-written']);

        self::assertSame([null], $store->mget(['never-written']));
    }

    public function testCanYieldKeysWithPrefix(): void
    {
        $store = LocalFileStore::fromPath($this->tempDir());
        $store->mset([['prefix_key1', 'v'], ['prefix_key2', 'v'], ['unrelated', 'v']]);

        $yielded = iterator_to_array($store->yieldKeys('prefix_'), false);
        sort($yielded);

        self::assertSame(['prefix_key1', 'prefix_key2'], $yielded);
    }

    public function testYieldKeysWithoutPrefixIgnoresNonTxtFiles(): void
    {
        $dir = $this->tempDir();
        $store = LocalFileStore::fromPath($dir);
        $store->mset([['a', '1']]);
        file_put_contents($dir . '/notes.md', 'x');

        self::assertSame(['a'], iterator_to_array($store->yieldKeys(), false));
    }

    public function testWorksWithADirectoryWhichDoesNotExist(): void
    {
        $parent = $this->tempDir();
        $root = $parent . '/file_system_store_test_secondary';
        self::assertDirectoryDoesNotExist($root);

        $store = LocalFileStore::fromPath($root);
        $value1 = date('c');
        $value2 = date('c') . date('c');
        $store->mset([['key1', $value1], ['key2', $value2]]);

        self::assertDirectoryExists($root);
        self::assertSame([$value1, $value2], $store->mget(['key1', 'key2']));
    }

    /** @return array<string, array{0: string}> */
    public static function traversalKeys(): array
    {
        return ['parent' => ['../foo'], 'absolute' => ['/foo'], 'backslash' => ['\\foo']];
    }

    #[DataProvider('traversalKeys')]
    public function testDisallowsAttemptsToTraversePathsOutsideOfASubfolder(string $key): void
    {
        $store = LocalFileStore::fromPath($this->tempDir());

        try {
            $store->mset([[$key, 'x']]);
            self::fail("mset accepted {$key}");
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\RuntimeException::class);
        $store->mget([$key]);
    }

    public function testDisallowsWritesIntoASiblingDirectorySharingTheRootPathPrefix(): void
    {
        $container = $this->tempDir('file_system_store_sibling');
        mkdir($container . '/root2', 0777, true);
        $store = LocalFileStore::fromPath($container . '/root');

        try {
            $store->mset([['../root2/pwn', 'pwned']]);
            self::fail('write into the sibling directory was accepted');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($container . '/root2/pwn.txt');
        }
    }

    public function testDisallowsDeletesInASiblingDirectorySharingTheRootPathPrefix(): void
    {
        $container = $this->tempDir('file_system_store_sibling');
        mkdir($container . '/root2', 0777, true);
        file_put_contents($container . '/root2/victim.txt', 'do not delete');
        $store = LocalFileStore::fromPath($container . '/root');

        try {
            $store->mdelete(['../root2/victim']);
            self::fail('delete in the sibling directory was accepted');
        } catch (\RuntimeException) {
            self::assertFileExists($container . '/root2/victim.txt');
        }
    }

    public function testAllowsKeysNestedInASubdirectoryOfTheRootPath(): void
    {
        $dir = $this->tempDir('file_system_store_nested');
        $store = LocalFileStore::fromPath($dir);

        $store->mset([['sub/dir/key', 'nested']]);

        self::assertSame('nested', file_get_contents($dir . '/sub/dir/key.txt'));
    }

    public function testAtomicWriteLeavesNoTempFilesBehind(): void
    {
        $dir = $this->tempDir();
        $store = LocalFileStore::fromPath($dir);

        $store->mset([['a', '1'], ['b', '2']]);
        $store->mset([['a', '3']]);

        self::assertSame([], array_values(array_filter(scandir($dir) ?: [], static fn (string $f): bool => str_ends_with($f, '.tmp'))));
        self::assertSame(['3', '2'], $store->mget(['a', 'b']));
    }

    public function testBinaryValuesSurviveARoundTrip(): void
    {
        $store = LocalFileStore::fromPath($this->tempDir());
        $bytes = implode('', array_map('chr', range(0, 255)));

        $store->mset([['bin', $bytes]]);

        self::assertSame([$bytes], $store->mget(['bin']));
    }
}
