<?php

declare(strict_types=1);

namespace LangChain\Load;

/**
 * Revive trusted constructor manifests using a fixed registry of shipped PHP factories.
 *
 * Trusted input only: a fixed class registry is not a sandbox for untrusted manifests.
 * Port of core load/index.ts. PHP arrays represent decoded objects and arrays. The 18 bundled
 * provider factories are a PHP adaptation of upstream's optional provider imports.
 * Secrets are supplied through secretsMap only; external import maps and environment lookup are
 * unsupported. Existing PHP serializers do not yet emit upstream's automatic literal escaping
 * (N19b): literal lc records in message additional_kwargs and tool arguments can revive constructors
 * or secrets. Do not use this loader in production consumers until those serializers are fixed.
 * Runtime hooks and non-string prompt partials are rejected. JSON decoding has a fixed depth of
 * 512 independent of the configurable revival maxDepth; Document identity/parallel serde is N27.
 */
final class Load
{
    /** @param array{secretsMap?: array<string, mixed>, maxDepth?: int, secretsFromEnv?: bool} $options */
    public static function load(string $text, array $options = []): mixed
    {
        if (($options['secretsFromEnv'] ?? false) !== false) {
            throw new \InvalidArgumentException('Environment secret resolution is not supported; supply secretsMap.');
        }
        foreach (['importMap', 'optionalImportsMap', 'optionalImportEntrypoints'] as $key) {
            if (array_key_exists($key, $options)) {
                throw new \InvalidArgumentException("Dynamic {$key} is not supported; the PHP load registry is fixed.");
            }
        }
        $depth = $options['maxDepth'] ?? 50;
        if (!is_int($depth) || $depth < 0) {
            throw new \InvalidArgumentException('maxDepth must be a non-negative integer.');
        }
        $secrets = $options['secretsMap'] ?? [];
        if (!is_array($secrets)) {
            throw new \InvalidArgumentException('secretsMap must be an array.');
        }

        return self::revive(json_decode($text, true, 512, JSON_THROW_ON_ERROR), $secrets, '$', 0, $depth);
    }

    private static function revive(mixed $value, array $secrets, string $path, int $depth, int $maxDepth): mixed
    {
        if ($depth > $maxDepth) {
            throw new \RuntimeException("Maximum recursion depth ({$maxDepth}) exceeded during deserialization at {$path}.");
        }
        if (!is_array($value)) {
            return $value;
        }
        if (count($value) === 1 && array_key_exists('__lc_escaped__', $value)) {
            // Contents are literal user data: do not revive or unwrap their nested markers.
            return $value['__lc_escaped__'];
        }
        if (($value['lc'] ?? null) === 1 && in_array($value['type'] ?? null, ['constructor', 'secret', 'not_implemented'], true)) {
            $id = $value['id'] ?? null;
            if (!is_array($id) || !array_is_list($id) || $id === [] || array_filter($id, static fn (mixed $part): bool => !is_string($part) || $part === '') !== []) {
                throw new \InvalidArgumentException("Malformed serialized identifier at {$path}.");
            }
            if ($value['type'] === 'secret') {
                if (count($id) !== 1) {
                    throw new \InvalidArgumentException("Malformed secret identifier at {$path}.");
                }
                if (!array_key_exists($id[0], $secrets)) {
                    throw new \RuntimeException('Missing secret "' . $id[0] . '" at ' . $path);
                }

                return $secrets[$id[0]];
            }
            if ($value['type'] === 'not_implemented') {
                throw new \RuntimeException('Trying to load an object that does not implement serialization at ' . $path . ': ' . implode('/', $id));
            }
            $factory = ImportMap::resolve($id);
            if (!array_key_exists('kwargs', $value) || !is_array($value['kwargs']) || ($value['kwargs'] !== [] && array_is_list($value['kwargs']))) {
                throw new \InvalidArgumentException("Malformed constructor kwargs at {$path}.");
            }
            $kwargs = self::revive($value['kwargs'], $secrets, $path . '.kwargs', $depth + 1, $maxDepth);
            if (!is_array($kwargs)) {
                throw new \InvalidArgumentException("Constructor kwargs must remain an array at {$path}.");
            }

            return $factory($kwargs);
        }
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::revive($item, $secrets, $path . '.' . $key, $depth + 1, $maxDepth);
        }

        return $result;
    }
}
