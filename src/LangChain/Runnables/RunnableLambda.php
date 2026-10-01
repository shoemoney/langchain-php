<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Wrap a plain PHP callable as a {@see Runnable}.
 *
 * Port of `RunnableLambda` from `@langchain_core/runnables`.
 *
 * The interesting part is argument binding. In the TypeScript original a lambda
 * may declare a multi-argument function and receive its arguments from bound
 * kwargs rather than from the chain input:
 *
 * ```ts
 * const r = RunnableLambda.from(
 *   (a: number, b: number) => a + b,
 *   { a: 1, b: 2 }
 * );
 * ```
 *
 * PHP's named arguments make the same thing natural, so the port splats bound
 * kwargs into a callable that declares more than one parameter, and passes the
 * single chain input to a callable that declares one. That covers both shapes
 * without a special-case API.
 */
class RunnableLambda extends Runnable
{
    /** @var callable */
    private $func;

    /** @var array<string, mixed> */
    public array $bound;

    /**
     * @param callable            $func
     * @param array<string,mixed> $bound
     */
    public function __construct(callable $func, array $bound = [])
    {
        $this->func = $func;
        $this->bound = $bound;
    }

    public static function from(callable $func, array $kwargs = []): self
    {
        return new self($func, $kwargs);
    }

    public function getName(): string
    {
        return 'RunnableLambda';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $func = $this->func;

        if ($this->bound === []) {
            // Upstream's lambda signature is `(input, config?, ...rest)`, so a
            // lambda that declares a second parameter can read the run config —
            // `config->options` is where a call-time `temperature` or
            // `max_tokens` override lives, and without this a pipeline built
            // from lambdas silently dropped them.
            //
            // Passed only when the callable can actually receive it. PHP permits
            // extra positional arguments to a userland function, but handing a
            // config to a lambda whose second parameter means something else
            // would be a silent behaviour change for a case that is currently
            // unambiguous, so the arity is checked first.
            $reflection = $this->reflectCallable($func);

            // Upstream's `(input, config?)` typing makes "the second parameter is the config
            // channel" unambiguous at the type level, and the TYPE is the discriminator here too —
            // not optionality. `fn(mixed $x, ?RunnableConfig $c = null)` is a config slot that happens
            // to be defaulted; `fn(array $xs, string $sep = ' ')` is the caller's own data. Counting
            // parameters, or asking whether the second one is optional, conflates the two: the old
            // `getNumberOfParameters() >= 2` gate sent the config into `$sep` and a legal closure died
            // with a TypeError.
            //
            // An UNTYPED second parameter is treated as the config slot, matching the long-standing
            // behaviour where anything untyped was assumed to want the config.
            $reflection = $this->reflectCallable($func);
            $takesConfig = true;

            if ($reflection !== null && !$reflection->isVariadic()) {
                $params = $reflection->getParameters();
                if (count($params) < 2) {
                    $takesConfig = false;
                } elseif ($params[1]->hasType()) {
                    $type = $params[1]->getType();
                    $names = $type instanceof \ReflectionNamedType && $type->isBuiltin()
                        ? []
                        : array_map(
                            static fn (\ReflectionNamedType $t): string => $t->getName(),
                            $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType
                                ? $type->getTypes()
                                : [$type],
                        );
                    $names[] = $type instanceof \ReflectionNamedType ? $type->getName() : '';
                    $takesConfig = $names === [] || in_array(RunnableConfig::class, $names, true)
                        || in_array('mixed', $names, true);
                }
            }

            if ($takesConfig) {
                // A lambda that DOES take the config gets a real one even when the caller passed
                // none. Upstream's `ensureConfig` always yields a config, so handing a lambda `null`
                // in the slot it declared as its config channel was a second, separate defect: the
                // measured failure was 'must be of type string, null given'.
                return $func($input, $config ?? new RunnableConfig());
            }

            return $func($input);
        }

        $reflection = $this->reflectCallable($func);
        $paramCount = $reflection?->getNumberOfParameters() ?? 1;

        if ($paramCount > 1) {
            // Splat the bound values in declaration order, letting the input
            // fill the first (usually the "input"-ish) parameter.
            $args = array_values($this->bound);
            if ($reflection !== null && $reflection->getNumberOfParameters() === count($args)) {
                return $func(...$args);
            }

            return $func($input, ...$args);
        }

        // Single-parameter callable: pass bound kwargs as named arguments so a
        // lambda can declare `fn (mixed $x, string $prefix)` and still receive
        // a single positional input.
        if ($reflection !== null) {
            $names = array_map(
                static fn (\ReflectionParameter $p): string => $p->getName(),
                $reflection->getParameters()
            );
            if (count($names) > 1 && array_is_list($this->bound)) {
                $named = array_combine($names, array_slice($this->bound, 0, count($names)));
                if ($named !== false) {
                    return $func(...$named);
                }
            }
        }

        return $func($input);
    }

    private function reflectCallable(callable $func): ?\ReflectionFunctionAbstract
    {
        try {
            if (is_array($func)) {
                return new \ReflectionMethod($func[0], $func[1]);
            }
            if (is_string($func) && str_contains($func, '::')) {
                /** @var class-string $class */
                $class = strstr($func, '::', true);
                $method = substr($func, strpos($func, '::') + 2);

                return new \ReflectionMethod($class, $method);
            }
            if ($func instanceof \Closure) {
                return new \ReflectionFunction($func);
            }
            if (is_string($func) && function_exists($func)) {
                return new \ReflectionFunction($func);
            }
        } catch (\ReflectionException) {
            return null;
        }

        return null;
    }
}
