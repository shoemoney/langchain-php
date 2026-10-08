<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Nodes;

use LangGraph\Agents\Nodes\Utils;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain/src/agents/nodes/tests/utils.test.ts` (`initializeMiddlewareStates`,
 * `derivePrivateState`) plus the other helpers of `nodes/utils.ts`.
 *
 * Upstream's Zod v3 / v4 / `StateSchema` variants of each case collapse to this port's two schema forms:
 * a JSON Schema array and an `AnnotationRoot`. `default` is the JSON Schema spelling of `.default(0)`.
 */
#[CoversClass(Utils::class)]
final class UtilsTest extends TestCase
{
    private const BASE_STATE = ['messages' => []];

    /** @param array<string, array<string, mixed>> $properties */
    private static function schema(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    // ---- initializeMiddlewareStates ------------------------------------------------------------

    public function testShouldWorkWithAJsonSchemaStateSchema(): void
    {
        $middleware = ['name' => 'test-middleware', 'stateSchema' => self::schema(['counter' => ['type' => 'number', 'default' => 0]])];

        self::assertSame(['counter' => 0], Utils::initializeMiddlewareStates([$middleware], self::BASE_STATE));
    }

    public function testShouldWorkWithAnAnnotationRootStateSchema(): void
    {
        $middleware = ['name' => 'test-middleware', 'stateSchema' => Annotation::root(['counter' => Annotation::last()])];

        self::assertSame(['counter' => 5], Utils::initializeMiddlewareStates([$middleware], ['messages' => [], 'counter' => 5]));
    }

    public function testShouldSkipMiddlewareWithoutStateSchema(): void
    {
        self::assertSame([], Utils::initializeMiddlewareStates([['name' => 'no-schema-middleware']], self::BASE_STATE));
    }

    public function testShouldThrowADescriptiveErrorWhenRequiredFieldsAreMissing(): void
    {
        $middleware = ['name' => 'test-middleware', 'stateSchema' => self::schema(['requiredField' => ['type' => 'string']], ['requiredField'])];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/requiredField/');
        $this->expectExceptionMessageMatches('/Middleware "test-middleware" has required state fields that must be initialized/');

        Utils::initializeMiddlewareStates([$middleware], self::BASE_STATE);
    }

    public function testProvidedValuesWinOverDefaultsAndUnknownKeysAreStripped(): void
    {
        $middleware = ['name' => 'm', 'stateSchema' => self::schema(['counter' => ['type' => 'number', 'default' => 0]])];

        self::assertSame(
            ['counter' => 9],
            Utils::initializeMiddlewareStates([$middleware], ['messages' => [], 'counter' => 9, 'stray' => true]),
        );
    }

    public function testPrivateRequiredFieldsAreOptional(): void
    {
        $middleware = ['name' => 'm', 'stateSchema' => self::schema(
            ['_hidden' => ['type' => 'string'], 'visible' => ['type' => 'string', 'default' => 'v']],
            ['_hidden', 'visible'],
        )];

        self::assertSame(['visible' => 'v'], Utils::initializeMiddlewareStates([$middleware], self::BASE_STATE));
    }

    public function testStatesOfSeveralMiddlewareAreMerged(): void
    {
        $a = ['name' => 'a', 'stateSchema' => self::schema(['x' => ['default' => 1]])];
        $b = (object) ['name' => 'b', 'stateSchema' => self::schema(['y' => ['default' => 2]])];

        self::assertSame(['x' => 1, 'y' => 2], Utils::initializeMiddlewareStates([$a, $b], self::BASE_STATE));
    }

    // ---- derivePrivateState --------------------------------------------------------------------

    public function testShouldReturnASchemaWithBuiltInFieldsWhenNoStateSchemaGiven(): void
    {
        $shape = Utils::getSchemaShape(Utils::derivePrivateState());

        self::assertArrayHasKey('messages', $shape);
        self::assertArrayHasKey('structuredResponse', $shape);
    }

    public function testShouldIncludePrivateFieldsFromAJsonSchema(): void
    {
        $stateSchema = self::schema(
            ['publicField' => ['type' => 'string', 'default' => 'pub'], '_privateField' => ['type' => 'string', 'default' => 'priv']],
            ['publicField', '_privateField'],
        );

        $derived = Utils::derivePrivateState($stateSchema);
        $shape = Utils::getSchemaShape($derived);

        self::assertArrayHasKey('_privateField', $shape);
        self::assertArrayHasKey('messages', $shape);
        // The private property is optional; the public one keeps its requirement.
        self::assertContains('publicField', $derived['required']);
        self::assertNotContains('_privateField', $derived['required']);
    }

    public function testShouldIncludePrivateFieldsFromAnAnnotationRoot(): void
    {
        $stateSchema = Annotation::root(['publicField' => Annotation::last(), '_privateField' => Annotation::last()]);

        $shape = Utils::getSchemaShape(Utils::derivePrivateState($stateSchema));

        self::assertArrayHasKey('_privateField', $shape);
        self::assertArrayHasKey('messages', $shape);
    }

    // ---- toPartialSchema -----------------------------------------------------------------------

    public function testToPartialSchemaMakesEveryFieldOptional(): void
    {
        $partial = Utils::toPartialSchema(self::schema(['a' => ['type' => 'string'], 'b' => ['type' => 'number']], ['a', 'b']));

        self::assertSame([], $partial['required']);
        self::assertSame(['a', 'b'], array_keys($partial['properties']));
    }

    public function testToPartialSchemaAcceptsAnAnnotationRootAndFallsBackToEmpty(): void
    {
        self::assertSame(['a'], array_keys(Utils::toPartialSchema(Annotation::root(['a' => Annotation::last()]))['properties']));
        self::assertSame([], Utils::toPartialSchema('not a schema')['properties']);
        self::assertSame([], Utils::toPartialSchema(null)['properties']);
    }

    // ---- parseJumpToTarget ---------------------------------------------------------------------

    public function testParseJumpToTargetMapsUserFacingLabels(): void
    {
        self::assertNull(Utils::parseJumpToTarget());
        self::assertNull(Utils::parseJumpToTarget(''));
        self::assertSame('model_request', Utils::parseJumpToTarget('model'));
        self::assertSame('model_request', Utils::parseJumpToTarget('model_request'));
        self::assertSame('tools', Utils::parseJumpToTarget('tools'));
        self::assertSame(Constants::END, Utils::parseJumpToTarget('end'));
        self::assertSame(Constants::END, Utils::parseJumpToTarget(Constants::END));
    }

    public function testParseJumpToTargetRejectsUnknownTargets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid jump target: nowhere, must be "model", "tools" or "end".');

        Utils::parseJumpToTarget('nowhere');
    }

    // ---- mergeAbortSignals ---------------------------------------------------------------------

    public function testMergeAbortSignalsReportsTheFirstAbortedSignal(): void
    {
        $live = static fn (): bool => false;
        $aborted = static fn (): bool => true;
        $flag = new class () {
            public bool $aborted = false;
        };

        $merged = Utils::mergeAbortSignals($live, $flag);
        self::assertFalse($merged());

        $flag->aborted = true;
        self::assertTrue($merged());
        self::assertTrue(Utils::mergeAbortSignals($live, $aborted)());
    }

    public function testMergeAbortSignalsIgnoresNonSignalsAndReportsReasons(): void
    {
        $reason = new \RuntimeException('cancelled');

        self::assertFalse(Utils::mergeAbortSignals(null, 'nope', 42, new \stdClass())());
        self::assertSame($reason, Utils::mergeAbortSignals(static fn (): \Throwable => $reason)());
        self::assertFalse(Utils::mergeAbortSignals()());
    }

    public function testIsAborted(): void
    {
        self::assertFalse(Utils::isAborted(null));
        self::assertFalse(Utils::isAborted(static fn (): bool => false));
        self::assertTrue(Utils::isAborted(static fn (): bool => true));
    }
}
