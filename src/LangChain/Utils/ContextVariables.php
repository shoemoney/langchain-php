<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * Context variables, configure hooks and the `context` template tag.
 *
 * Port of two upstream files that share the name "context":
 *
 * - `singletons/async_local_storage/context.ts`: {@see self::setContextVariable()},
 *   {@see self::getContextVariable()}, {@see self::registerConfigureHook()}. Variables
 *   live in the global {@see AsyncLocalStorage}, so a variable set inside a
 *   {@see AsyncLocalStorage::run()} frame is visible to everything that frame calls and
 *   gone when it returns; set outside any frame it is global.
 * - `utils/context.ts`: the tagged template {@see self::context()}.
 *
 * `setContextVariable` needs a real storage instance: like upstream it throws when
 * none was initialized. `getContextVariable` just returns null.
 */
final class ContextVariables
{
    private const CONFIGURE_HOOKS_KEY = 'lc:configure_hooks';

    private function __construct()
    {
    }

    /**
     * Set a context variable for the current frame and everything it calls.
     *
     * @throws \LogicException when no global storage instance has been initialized
     */
    public static function setContextVariable(string|int $name, mixed $value): void
    {
        $storage = AsyncLocalStorage::getGlobalInstance();
        if ($storage === null) {
            throw new \LogicException('Internal error: Global shared async local storage instance has not been initialized.');
        }

        $store = $storage->getStore();
        $store = is_array($store) ? $store : [];
        $variables = $store[AsyncLocalStorage::CONTEXT_VARIABLES_KEY] ?? [];
        $variables[$name] = $value;
        $store[AsyncLocalStorage::CONTEXT_VARIABLES_KEY] = $variables;

        $storage->enterWith($store);
    }

    /**
     * Read a context variable; null when unset or when no storage is installed.
     */
    public static function getContextVariable(string|int $name): mixed
    {
        $storage = AsyncLocalStorage::getGlobalInstance();
        if ($storage === null) {
            return null;
        }
        $store = $storage->getStore();

        return is_array($store) ? ($store[AsyncLocalStorage::CONTEXT_VARIABLES_KEY][$name] ?? null) : null;
    }

    /**
     * The registered configure hooks (upstream `_getConfigureHooks`).
     *
     * @return list<array{contextVar?: string, inheritable?: bool, handlerClass?: class-string, envVar?: string}>
     */
    public static function getConfigureHooks(): array
    {
        $hooks = self::getContextVariable(self::CONFIGURE_HOOKS_KEY);

        return is_array($hooks) ? $hooks : [];
    }

    /**
     * Register a callback configure hook, either by context variable or by env var plus handler class.
     *
     * @param array{contextVar?: string, inheritable?: bool, handlerClass?: class-string, envVar?: string} $config
     *
     * @throws \InvalidArgumentException when `envVar` is set without `handlerClass`
     */
    public static function registerConfigureHook(array $config): void
    {
        if (!empty($config['envVar']) && empty($config['handlerClass'])) {
            throw new \InvalidArgumentException('If envVar is set, handlerClass must also be set to a non-None value.');
        }
        self::setContextVariable(self::CONFIGURE_HOOKS_KEY, [...self::getConfigureHooks(), $config]);
    }

    /**
     * The `context` tagged template: strip common indentation, trim, align multi-line values.
     *
     * PHP has no tagged templates, so the call takes what JavaScript hands the tag:
     * the template's RAW string parts and the interpolated values, one fewer value than
     * parts. Raw means escapes are still text: a backslash before a newline is a line
     * continuation, `\`` `\$` `\{` are literals, and a literal backslash-n becomes a
     * newline at the very end.
     *
     * Non-string values are JSON-encoded (`30` becomes `30`, `['a']` becomes `["a"]`).
     *
     * @param list<string> $strings the raw template parts
     * @param list<mixed>  $values  the interpolated values
     */
    public static function context(array $strings, array $values = []): string
    {
        $result = '';

        foreach ($strings as $i => $raw) {
            $next = preg_replace('/\\\\\n[ \t]*/', '', $raw) ?? $raw;
            $next = str_replace(['\\`', '\\$', '\\{'], ['`', '$', '{'], $next);

            $result .= $next;

            if ($i < count($values)) {
                $value = self::alignValue($values[$i], $result);
                $result .= is_string($value) ? $value : self::stringify($value);
            }
        }

        $result = self::stripIndent($result);
        $result = trim($result);

        return str_replace('\\n', "\n", $result);
    }

    private static function alignValue(mixed $value, string $precedingText): mixed
    {
        if (!is_string($value) || !str_contains($value, "\n")) {
            return $value;
        }

        $currentLine = substr($precedingText, (int) strrpos("\n" . $precedingText, "\n"));
        if (preg_match('/^(\s+)/u', $currentLine, $match) === 1) {
            return str_replace("\n", "\n" . $match[1], $value);
        }

        return $value;
    }

    private static function stripIndent(string $text): string
    {
        $lines = explode("\n", $text);

        $minIndent = null;
        foreach ($lines as $line) {
            if (preg_match('/^(\s+)\S+/u', $line, $match) === 1) {
                $indent = mb_strlen($match[1]);
                $minIndent = $minIndent === null ? $indent : min($minIndent, $indent);
            }
        }

        if ($minIndent === null) {
            return $text;
        }

        return implode("\n", array_map(
            static fn (string $line): string => ($line !== '' && ($line[0] === ' ' || $line[0] === "\t"))
                ? mb_substr($line, $minIndent)
                : $line,
            $lines,
        ));
    }

    private static function stringify(mixed $value): string
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return Js::encode($value);
    }
}
