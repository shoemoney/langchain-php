<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Outputs;

/**
 * Everything one model invocation produced.
 *
 * Port of the `LLMResult` type from `@langchain/core/outputs`.
 *
 * `generations` is a list-of-lists: one inner list per input prompt, each
 * holding that prompt's completions (length 1 unless `n > 1`). The outer index
 * is what pairs a result back to the input that caused it, which is why a batch
 * that returns results out of order is a bug and not a feature.
 *
 * {@see self::$runIds} carries the callback run ids, which is how a caller
 * correlates a result with what a tracer recorded. In the TypeScript original
 * this hangs off a non-enumerable `__run` symbol so it does not leak into a
 * serialized payload; here it is simply a property that {@see self::toArray()}
 * leaves out for the same reason.
 */
final class LLMResult
{
    /** The property name the TypeScript original hides behind `RUN_KEY`. */
    public const RUN_KEY = '__run';

    /**
     * @param list<list<Generation|ChatGeneration>> $generations
     * @param array<string, mixed>                   $llmOutput
     * @param list<string>|null                      $runIds
     */
    public function __construct(
        public array $generations = [],
        public array $llmOutput = [],
        public ?array $runIds = null,
    ) {
    }

    /**
     * The single completion for the first (and usually only) prompt.
     *
     * The narrowing that `invoke()` performs. It is `null` rather than a thrown
     * error because a subclass that returns an empty result is a legitimate
     * outcome the caller must still handle, not an invariant violation.
     */
    public function firstGeneration(): Generation|ChatGeneration|null
    {
        $first = $this->generations[0] ?? null;
        if (!is_array($first) || $first === []) {
            return null;
        }

        return $first[0];
    }

    /**
     * The first generation's text.
     */
    public function firstText(): ?string
    {
        $generation = $this->firstGeneration();

        return $generation?->text;
    }

    /**
     * The serializable form — deliberately omits {@see self::$runIds}.
     *
     * `llmOutput` is omitted when empty rather than emitted as `{}`. A provider
     * that reported nothing should be indistinguishable in a payload from one
     * that was never asked, and an explicit empty object reads as "we looked and
     * found nothing", which is a stronger claim.
     *
     * @return array{generations: list<list<array<string, mixed>>>, llmOutput?: array<string, mixed>}
     */
    public function toArray(): array
    {
        $generations = [];
        foreach ($this->generations as $group) {
            $row = [];
            foreach ($group as $generation) {
                $row[] = ['text' => $generation->text, 'generationInfo' => $generation->generationInfo];
            }
            $generations[] = $row;
        }

        $out = ['generations' => $generations];
        if ($this->llmOutput !== []) {
            $out['llmOutput'] = $this->llmOutput;
        }

        return $out;
    }
}
