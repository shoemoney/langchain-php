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

            if ($reflection === null
                || $reflection->isVariadic()
                || $reflection->getNumberOfParameters() >= 2
            ) {
                return $func($input, $config);
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
