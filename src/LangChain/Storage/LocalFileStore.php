<?php

declare(strict_types=1);

namespace LangChain\Storage;

use LangChain\Stores\BaseStore;

/**
 * File system implementation of {@see BaseStore}: one `<key>.txt` file per key
 * under a root directory. Values are byte strings.
 *
 * Port of `LocalFileStore` from `langchain/storage/file_system`.
 *
 * Security: this store can alter any text file in the provided directory and its
 * subfolders. Point it at a directory that holds nothing else.
 *
 * Known non-exact behaviour: upstream serialises concurrent writes to one key
 * with a promise-chain lock (`keyLocks`). Every operation here is synchronous,
 * so two writes can never overlap and there is nothing to lock; writes are still
 * atomic (temp file, then rename).
 *
 * @template-extends BaseStore<string, string>
 */
class LocalFileStore extends BaseStore
{
    public string $rootPath;

    /** @param array{rootPath: string} $fields */
    public function __construct(array $fields)
    {
        $this->kwargs = $fields;
        $this->rootPath = $fields['rootPath'];
    }

    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['langchain', 'storage'];
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return array_merge(static::lcNamespace(), ['LocalFileStore']);
    }

    /**
     * @param list<string> $keys
     * @return list<string|null>
     */
    public function mget(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[] = $this->getParsedFile($key);
        }

        return $values;
    }

    /**
     * The last value for a duplicated key wins.
     *
     * @param list<array{0: string, 1: string}> $keyValuePairs
     */
    public function mset(array $keyValuePairs): void
    {
        $deduped = [];
        foreach ($keyValuePairs as [$key, $value]) {
            $deduped[$key] = $value;
        }

        foreach ($deduped as $key => $value) {
            $this->setFileContent($value, (string) $key);
        }
    }

    /** @param list<string> $keys */
    public function mdelete(array $keys): void
    {
        foreach ($keys as $key) {
            $path = $this->getFullPath($key);
            // Missing files are ignored so deletes stay idempotent.
            if (is_file($path) && !@unlink($path) && is_file($path)) {
                throw new \RuntimeException("Error deleting file at path: {$path}");
            }
        }
    }

    /** @return \Generator<int, string> */
    public function yieldKeys(?string $prefix = null): \Generator
    {
        $files = scandir($this->rootPath);
        if ($files === false) {
            throw new \RuntimeException("Error reading directory: {$this->rootPath}");
        }

        foreach ($files as $file) {
            if (!str_ends_with($file, '.txt')) {
                continue;
            }
            $key = substr($file, 0, -4);
            if ($prefix === null || str_starts_with($key, $prefix)) {
                yield $key;
            }
        }
    }

    /**
     * Initialise a store, creating the directory when it does not exist and
     * removing `.tmp` files orphaned by an interrupted atomic write.
     */
    public static function fromPath(string $rootPath): static
    {
        if (!is_dir($rootPath) || !is_readable($rootPath) || !is_writable($rootPath)) {
            if (!@mkdir($rootPath, 0777, true) && !is_dir($rootPath)) {
                throw new \RuntimeException("An error occurred creating directory at: {$rootPath}");
            }
        }

        foreach (@scandir($rootPath) ?: [] as $entry) {
            if (str_ends_with($entry, '.tmp')) {
                @unlink($rootPath . \DIRECTORY_SEPARATOR . $entry);
            }
        }

        return new static(['rootPath' => $rootPath]);
    }

    private function getParsedFile(string $key): ?string
    {
        // Validate the key to prevent path traversal.
        if (preg_match('/^[a-zA-Z0-9_\-:.]+$/', $key) !== 1) {
            throw new \RuntimeException(
                'Invalid key. Only alphanumeric characters, underscores, hyphens, colons, and periods are allowed.'
            );
        }

        $path = $this->getFullPath($key);
        if (!is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Error reading and parsing file at path: {$this->rootPath}.");
        }

        return $content;
    }

    private function setFileContent(string $content, string $key): void
    {
        $fullPath = $this->getFullPath($key);
        try {
            $this->writeFileAtomically($content, $fullPath);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error writing file at path: {$fullPath}.\nError: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The file that holds a key's value. Resolved lexically so `..` segments
     * that stay inside the root are fine and any that escape it (including into
     * a sibling that merely shares the root's name prefix) are refused.
     */
    private function getFullPath(string $key): string
    {
        if (preg_match('/^[a-zA-Z0-9_.\-\/]+$/', $key) !== 1) {
            throw new \RuntimeException("Error getting full path for key: {$key}.\nError: Invalid characters in key: {$key}");
        }
        // Upstream resolves an absolute key and only then checks containment;
        // an absolute key has no legitimate meaning for a store, so it is refused outright.
        if (str_starts_with($key, '/')) {
            throw new \RuntimeException("Error getting full path for key: {$key}.\nError: Invalid key: {$key}. Key should be relative to the root path.");
        }

        $segments = [];
        foreach (explode('/', $key . '.txt') as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new \RuntimeException(
                        "Error getting full path for key: {$key}.\nError: Invalid key: {$key}. "
                        . "Key should be relative to the root path. Root path: {$this->rootPath}"
                    );
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return rtrim($this->rootPath, '/\\') . \DIRECTORY_SEPARATOR . implode(\DIRECTORY_SEPARATOR, $segments);
    }

    private function writeFileAtomically(string $content, string $fullPath): void
    {
        $directory = dirname($fullPath);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create directory {$directory}");
        }

        $tempPath = sprintf('%s.%d-%s.tmp', $fullPath, (int) (microtime(true) * 1000), bin2hex(random_bytes(4)));

        try {
            if (file_put_contents($tempPath, $content) === false) {
                throw new \RuntimeException("Could not write {$tempPath}");
            }
            if (!@rename($tempPath, $fullPath)) {
                // Windows can refuse to replace a locked destination.
                if (file_put_contents($fullPath, $content) === false) {
                    throw new \RuntimeException("Could not write {$fullPath}");
                }
                @unlink($tempPath);
            }
        } catch (\Throwable $e) {
            @unlink($tempPath);

            throw $e;
        }
    }
}
