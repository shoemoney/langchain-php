<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * A runnable that passes its input through unchanged, or with keys added.
 *
 * Port of `RunnablePassthrough` from `@langchain_core/runnables`.
 *
 * Instantiated directly it is the identity, which is useful as one branch of a
 * {@see RunnableParallel}: a map of `{question: new RunnablePassthrough(),
 * context: $retriever}` is how the canonical RAG chain forwards a field it does
 * not want to compute.
 *
 * {@see self::assign()} is the interesting half — it layers computed keys onto
 * the input instead of replacing it, so a step can enrich a record without
 * having to restate the fields it did not touch.
 */
class RunnablePassthrough extends Runnable
{
    /**
     * Optional side-effecting transform applied to the input.
     *
     * The result is discarded: the original input is what passes through. This
     * exists so a chain can fire an observer in the middle of a pipeline.
     *
     * @var (callable(mixed): mixed)|null
     */
    public $func;

    /** @param array{func?: callable(mixed): mixed} $fields */
    public function __construct(array $fields = [])
    {
        $this->func = $fields['func'] ?? null;
    }

    public function getName(): string
    {
        return 'RunnablePassthrough';
    }

    /**
     * Layer `$mapping`'s results onto the input.
     *
     * @param array<string, mixed> $mapping Branch name to runnable, closure, or
     *                                        literal. Each branch receives the
     *                                        whole input, so later keys can read
     *                                        earlier ones only through what they
     *                                        were handed — not through each
     *                                        other. To read a sibling's output,
     *                                        sequence two steps.
     */
    public static function assign(array $mapping): RunnableAssign
    {
        return new RunnableAssign(RunnableParallel::from($mapping));
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        if ($this->func !== null) {
            ($this->func)($input);
        }

        return $input;
    }

    public function pipe(RunnableInterface $next): RunnableSequence
    {
        return new RunnableSequence([$this, $next]);
    }
}
