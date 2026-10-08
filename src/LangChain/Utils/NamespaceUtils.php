<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * Hierarchical branding for class families, so `isInstance()` works across
 * copies of a class.
 *
 * Port of `@langchain/core/utils/namespace` (`createNamespace`). `Namespace` is
 * a reserved word in PHP, hence the name.
 *
 * Upstream stamps a `Symbol.for(path)` on a branded class's prototype and lets
 * subclasses inherit it through the prototype chain. PHP has no prototypes and no
 * way to mint a subclass at runtime without `eval`, so the stamp is a registry
 * entry `symbol -> class name` and "inherits the stamp" becomes "some class in
 * the object's parent chain is registered under the symbol". The observable
 * contract is identical: a subclass of a branded class is recognised, a sibling
 * or a parent of a leaf is not, and the same dotted path always names the same
 * brand (like `Symbol.for`).
 *
 * An instance of this class is both a namespace (`sub()`, `brand()`) and the
 * checker for its own symbol (`isInstance()`); upstream's per-class
 * `static isInstance` is the same check against the brand's symbol, so
 * {@see self::brand()} returns that checker.
 */
final class NamespaceUtils
{
    /** @var array<string, array<class-string, true>> symbol => branded classes */
    private static array $registry = [];

    private static ?self $root = null;

    private function __construct(
        private readonly string $symbol,
        private readonly string $path,
    ) {
    }

    /**
     * Create a symbol-based namespace for hierarchical `isInstance` checking.
     *
     * @param string $path dot-separated path, e.g. `"langchain.error"`
     */
    public static function createNamespace(string $path): self
    {
        return new self($path, $path);
    }

    /** The base namespace used throughout LangChain (`ns` upstream). */
    public static function ns(): self
    {
        return self::$root ??= self::createNamespace('langchain');
    }

    /** The symbol this namespace checks for. */
    public function symbol(): string
    {
        return $this->symbol;
    }

    /**
     * Create a child namespace.
     */
    public function sub(string $childPath): self
    {
        return self::createNamespace($this->path . '.' . $childPath);
    }

    /**
     * Brand a class with this namespace's symbol.
     *
     * Without a marker the class becomes a namespace-level base (symbol = the
     * namespace path); with one it becomes a leaf (symbol = `path.marker`).
     * Subclasses are branded implicitly, exactly as prototype inheritance does
     * upstream.
     *
     * @param class-string $base
     *
     * @return self the checker for the brand's symbol (upstream's static `isInstance`)
     */
    public function brand(string $base, ?string $marker = null): self
    {
        if (!class_exists($base) && !interface_exists($base)) {
            throw new \InvalidArgumentException("Cannot brand unknown class {$base}");
        }

        $brandSymbol = ($marker !== null && $marker !== '') ? $this->path . '.' . $marker : $this->path;
        self::$registry[$brandSymbol][ltrim($base, '\\')] = true;

        return new self($brandSymbol, $this->path);
    }

    /**
     * Whether an object is branded under this symbol, at any level of its class chain.
     */
    public function isInstance(mixed $obj): bool
    {
        if (!is_object($obj)) {
            return false;
        }

        $branded = self::$registry[$this->symbol] ?? [];
        if ($branded === []) {
            return false;
        }

        foreach ([get_class($obj), ...array_values(class_parents($obj)), ...array_values(class_implements($obj))] as $class) {
            if (isset($branded[$class])) {
                return true;
            }
        }

        return false;
    }
}
