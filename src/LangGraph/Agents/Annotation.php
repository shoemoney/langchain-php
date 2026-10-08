<?php

declare(strict_types=1);

namespace LangGraph\Agents;

use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\UntrackedValue;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\State\Annotation as StateAnnotation;
use LangGraph\State\AnnotationRoot;

/**
 * Builds the state schemas an agent graph runs on.
 *
 * Port of `createAgentState` from `langchain/src/agents/annotation.ts`.
 *
 * Upstream builds three `StateSchema`s from a mix of Zod objects, `StateSchema`s and reducer metadata.
 * This port is JSON-Schema-native: a user or middleware schema is an {@see AnnotationRoot} (its channels,
 * reducers included, are reused) or a JSON Schema array whose `properties` become last-value channels.
 * Zod, `StateSchema`/`ReducedValue` and the Zod v4 reducer registry have no PHP form, so their cases are
 * covered through `AnnotationRoot`, whose reducer channels play the `ReducedValue` role.
 */
final class Annotation
{
    private function __construct()
    {
    }

    /**
     * Create the `state`, `input` and `output` schemas of an agent.
     *
     * - `state` has `messages`, the internal `jumpTo` navigation field, and every user and middleware field.
     * - `input` and `output` leave out underscore-prefixed (private) fields: private state persists in the
     *   graph but is neither accepted nor returned.
     * - `output` gains `structuredResponse` when `$hasStructuredResponse` is set.
     * - A field already defined is never overwritten: the user's schema wins over middleware, and the
     *   first middleware wins over the next.
     *
     * @param bool                                         $hasStructuredResponse Whether a `responseFormat` is configured.
     * @param AnnotationRoot|array<string, mixed>|null     $stateSchema           User-provided state schema.
     * @param iterable<array<string, mixed>|object>        $middlewareList
     * @return array{state: AnnotationRoot, input: AnnotationRoot, output: AnnotationRoot}
     */
    public static function createAgentState(
        bool $hasStructuredResponse = true,
        AnnotationRoot|array|null $stateSchema = null,
        iterable $middlewareList = [],
    ): array {
        /** @var array<string, BaseChannel> $stateFields */
        $stateFields = ['jumpTo' => new UntrackedValue()];
        /** @var array<string, BaseChannel> $inputFields */
        $inputFields = [];
        /** @var array<string, BaseChannel> $outputFields */
        $outputFields = [];

        $applySchema = static function (mixed $schema) use (&$stateFields, &$inputFields, &$outputFields): void {
            foreach (self::channelsOf($schema) as $key => $channel) {
                if (isset($stateFields[$key])) {
                    continue;
                }

                $stateFields[$key] = $channel;

                // Private state persists in the graph state but is not exposed as input/output channels.
                if (str_starts_with($key, '_')) {
                    continue;
                }

                $inputFields[$key] = $channel;
                $outputFields[$key] = $channel;
            }
        };

        if (self::channelsOf($stateSchema) !== []) {
            $applySchema($stateSchema);
        }

        foreach ($middlewareList as $middleware) {
            $middlewareSchema = Utils::middlewareValue($middleware, 'stateSchema');
            if (self::channelsOf($middlewareSchema) !== []) {
                $applySchema($middlewareSchema);
            }
        }

        // Only include structuredResponse when responseFormat is defined.
        if ($hasStructuredResponse) {
            $outputFields['structuredResponse'] = new UntrackedValue();
        }

        return [
            'state' => StateAnnotation::root([...self::messages(), ...$stateFields]),
            'input' => StateAnnotation::root([...self::messages(), ...$inputFields]),
            'output' => StateAnnotation::root([...self::messages(), ...$outputFields]),
        ];
    }

    /**
     * A fresh `messages` channel with the message-merging reducer (upstream's `MessagesValue`).
     *
     * @return array<string, BaseChannel>
     */
    private static function messages(): array
    {
        return ['messages' => MessagesAnnotation::root()->spec['messages']];
    }

    /**
     * The channels a schema declares: an AnnotationRoot's own, or one last-value channel per JSON Schema property.
     *
     * @return array<string, BaseChannel>
     */
    private static function channelsOf(mixed $schema): array
    {
        if (AnnotationRoot::isInstance($schema)) {
            return $schema->spec;
        }

        $keys = Utils::schemaKeys($schema);
        if ($keys === null) {
            return [];
        }

        $channels = [];
        foreach ($keys as $key) {
            $channels[$key] = StateAnnotation::last();
        }

        return $channels;
    }
}
