<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\OutputParsers\JsonOutputParser;
use LangChain\OutputParsers\JsonPatch as OutputParsersJsonPatch;
use LangChain\Utils\JsonPatch;
use LangChain\Utils\JsonPatchError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `@langchain/core` ships no test file for `utils/fast-json-patch`, so the cases
 * here come from the RFC 6902 appendix A examples (the suite the vendored library
 * is written against) plus the library's own error taxonomy, pointer escaping and
 * `compare` rules, and a streaming round-trip through `JsonOutputParser`.
 */
#[CoversClass(JsonPatch::class)]
#[CoversClass(JsonPatchError::class)]
#[CoversClass(OutputParsersJsonPatch::class)]
final class JsonPatchTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $patch
     */
    private static function apply(mixed $document, array $patch, bool $validate = false): mixed
    {
        return JsonPatch::applyPatch($document, $patch, $validate)['newDocument'];
    }

    // ------------------------------------------------ RFC 6902 appendix A

    public function testA1AddingAnObjectMember(): void
    {
        $this->assertSame(
            ['foo' => 'bar', 'baz' => 'qux'],
            self::apply(['foo' => 'bar'], [['op' => 'add', 'path' => '/baz', 'value' => 'qux']]),
        );
    }

    public function testA2AddingAnArrayElement(): void
    {
        $this->assertSame(
            ['foo' => ['bar', 'qux', 'baz']],
            self::apply(['foo' => ['bar', 'baz']], [['op' => 'add', 'path' => '/foo/1', 'value' => 'qux']]),
        );
    }

    public function testA3RemovingAnObjectMember(): void
    {
        $this->assertSame(
            ['foo' => 'bar'],
            self::apply(['baz' => 'qux', 'foo' => 'bar'], [['op' => 'remove', 'path' => '/baz']]),
        );
    }

    public function testA4RemovingAnArrayElement(): void
    {
        $this->assertSame(
            ['foo' => ['bar', 'baz']],
            self::apply(['foo' => ['bar', 'qux', 'baz']], [['op' => 'remove', 'path' => '/foo/1']]),
        );
    }

    public function testA5ReplacingAValue(): void
    {
        $this->assertSame(
            ['baz' => 'boo', 'foo' => 'bar'],
            self::apply(['baz' => 'qux', 'foo' => 'bar'], [['op' => 'replace', 'path' => '/baz', 'value' => 'boo']]),
        );
    }

    public function testA6MovingAValue(): void
    {
        $doc = ['foo' => ['bar' => 'baz', 'waldo' => 'fred'], 'qux' => ['corge' => 'grault']];

        $this->assertSame(
            ['foo' => ['bar' => 'baz'], 'qux' => ['corge' => 'grault', 'thud' => 'fred']],
            self::apply($doc, [['op' => 'move', 'from' => '/foo/waldo', 'path' => '/qux/thud']]),
        );
    }

    public function testA7MovingAnArrayElement(): void
    {
        $this->assertSame(
            ['foo' => ['all', 'cows', 'eat', 'grass']],
            self::apply(['foo' => ['all', 'grass', 'cows', 'eat']], [['op' => 'move', 'from' => '/foo/1', 'path' => '/foo/3']]),
        );
    }

    public function testA8TestingAValueSuccess(): void
    {
        $doc = ['baz' => 'qux', 'foo' => ['a', 2, 'c']];
        $patch = [['op' => 'test', 'path' => '/baz', 'value' => 'qux'], ['op' => 'test', 'path' => '/foo/1', 'value' => 2]];

        $this->assertSame($doc, self::apply($doc, $patch));
    }

    public function testA9TestingAValueError(): void
    {
        try {
            self::apply(['baz' => 'qux'], [['op' => 'test', 'path' => '/baz', 'value' => 'bar']]);
            $this->fail('expected TEST_OPERATION_FAILED');
        } catch (JsonPatchError $e) {
            $this->assertSame('TEST_OPERATION_FAILED', $e->errorName);
            $this->assertSame(0, $e->index);
            $this->assertStringStartsWith('Test operation failed', $e->getMessage());
        }
    }

    public function testA10AddingANestedMemberObject(): void
    {
        $this->assertSame(
            ['foo' => 'bar', 'child' => ['grandchild' => []]],
            self::apply(['foo' => 'bar'], [['op' => 'add', 'path' => '/child', 'value' => ['grandchild' => []]]]),
        );
    }

    public function testA11IgnoringUnrecognizedElements(): void
    {
        $this->assertSame(
            ['foo' => 'bar', 'baz' => 'qux'],
            self::apply(['foo' => 'bar'], [['op' => 'add', 'path' => '/baz', 'value' => 'qux', 'xyz' => 123]]),
        );
    }

    public function testA12AddingToANonexistentTargetFailsValidation(): void
    {
        $this->expectValidationError('OPERATION_PATH_CANNOT_ADD', ['foo' => 'bar'], [['op' => 'add', 'path' => '/baz/bat', 'value' => 'qux']]);
    }

    public function testA14TildeEscapesInPointers(): void
    {
        $doc = ['/' => 9, '~1' => 10];

        $this->assertSame(9, JsonPatch::getValueByPointer($doc, '/~1'));
        $this->assertSame(10, JsonPatch::getValueByPointer($doc, '/~01'));
    }

    public function testA16AddingAnArrayValueWithAppend(): void
    {
        $this->assertSame(
            ['foo' => ['bar', ['abc', 'def']]],
            self::apply(['foo' => ['bar']], [['op' => 'add', 'path' => '/foo/-', 'value' => ['abc', 'def']]]),
        );
    }

    // ------------------------------------------------------------- operations

    public function testCopyDuplicatesAValueSoLaterEditsDoNotAlias(): void
    {
        $result = self::apply(
            ['a' => ['x' => 1]],
            [['op' => 'copy', 'from' => '/a', 'path' => '/b'], ['op' => 'replace', 'path' => '/b/x', 'value' => 2]],
        );

        $this->assertSame(['a' => ['x' => 1], 'b' => ['x' => 2]], $result);
    }

    public function testRootOperations(): void
    {
        $this->assertSame([1], self::apply('old', [['op' => 'add', 'path' => '', 'value' => [1]]]));
        $this->assertSame('new', self::apply('old', [['op' => 'replace', 'path' => '', 'value' => 'new']]));
        $this->assertNull(self::apply('old', [['op' => 'remove', 'path' => '']]));
        $this->assertSame(5, self::apply(['a' => 5], [['op' => 'copy', 'from' => '/a', 'path' => '']]));
    }

    public function testRootTestOperationFailureThrows(): void
    {
        $this->expectException(JsonPatchError::class);

        self::apply('old', [['op' => 'test', 'path' => '', 'value' => 'other']]);
    }

    public function testApplyOperationReportsTheRemovedValue(): void
    {
        $result = JsonPatch::applyOperation(['a' => 1, 'b' => 2], ['op' => 'remove', 'path' => '/a']);

        $this->assertSame(['b' => 2], $result['newDocument']);
        $this->assertSame(1, $result['removed']);

        $replaced = JsonPatch::applyOperation(['a' => 1], ['op' => 'replace', 'path' => '/a', 'value' => 9]);
        $this->assertSame(1, $replaced['removed']);
    }

    public function testTheCallersDocumentIsNeverMutated(): void
    {
        $doc = ['a' => ['b' => [1, 2]]];
        $copy = $doc;

        JsonPatch::applyPatch($doc, [['op' => 'add', 'path' => '/a/b/0', 'value' => 0], ['op' => 'remove', 'path' => '/a/b/2']]);

        $this->assertSame($copy, $doc);
    }

    public function testApplyReducerWorksWithArrayReduce(): void
    {
        $ops = [['op' => 'add', 'path' => '/a', 'value' => 1], ['op' => 'add', 'path' => '/b', 'value' => 2]];

        $this->assertSame(
            ['a' => 1, 'b' => 2],
            array_reduce(array_keys($ops), static fn (mixed $doc, int $i): mixed => JsonPatch::applyReducer($doc, $ops[$i], $i), []),
        );
    }

    public function testApplyReducerThrowsOnAFailedTest(): void
    {
        $this->expectException(JsonPatchError::class);

        JsonPatch::applyReducer(['a' => 1], ['op' => 'test', 'path' => '/a', 'value' => 2], 0);
    }

    public function testAnEmptyArrayAcceptsAnObjectKeyAndAnIndex(): void
    {
        $this->assertSame(['k' => 1], self::apply([], [['op' => 'add', 'path' => '/k', 'value' => 1]]));
        $this->assertSame(['x'], self::apply([], [['op' => 'add', 'path' => '/0', 'value' => 'x']]));
        $this->assertSame(['x'], self::apply([], [['op' => 'add', 'path' => '/-', 'value' => 'x']]));
    }

    public function testPrototypePollutionPathsAreBanned(): void
    {
        $this->expectException(\TypeError::class);

        self::apply([], [['op' => 'add', 'path' => '/__proto__/polluted', 'value' => true]]);
    }

    public function testPrototypeBanCanBeSwitchedOff(): void
    {
        $result = JsonPatch::applyPatch(['constructor' => []], [['op' => 'add', 'path' => '/constructor/prototype', 'value' => 1]], false, true, false);

        $this->assertSame(['constructor' => ['prototype' => 1]], $result['newDocument']);
    }

    // ----------------------------------------------------------- validation

    /**
     * @return array<string, array{string, mixed, list<mixed>}>
     */
    public static function invalidSequences(): array
    {
        return [
            'op not an object' => ['OPERATION_NOT_AN_OBJECT', null, ['nope']],
            'unknown op' => ['OPERATION_OP_INVALID', null, [['op' => 'bogus', 'path' => '/a']]],
            'path not a string' => ['OPERATION_PATH_INVALID', null, [['op' => 'remove', 'path' => 5]]],
            'path without slash' => ['OPERATION_PATH_INVALID', null, [['op' => 'remove', 'path' => 'a']]],
            'move without from' => ['OPERATION_FROM_REQUIRED', null, [['op' => 'move', 'path' => '/a']]],
            'add without value' => ['OPERATION_VALUE_REQUIRED', null, [['op' => 'add', 'path' => '/a']]],
            'replace missing path' => ['OPERATION_PATH_UNRESOLVABLE', ['a' => 1], [['op' => 'replace', 'path' => '/b', 'value' => 1]]],
            'remove missing path' => ['OPERATION_PATH_UNRESOLVABLE', ['a' => 1], [['op' => 'remove', 'path' => '/b']]],
            'move from missing' => ['OPERATION_FROM_UNRESOLVABLE', ['a' => 1], [['op' => 'move', 'from' => '/zzz', 'path' => '/b']]],
            'array index out of bounds' => ['OPERATION_VALUE_OUT_OF_BOUNDS', ['a' => [1]], [['op' => 'add', 'path' => '/a/5', 'value' => 1]]],
            'illegal array index' => ['OPERATION_PATH_ILLEGAL_ARRAY_INDEX', ['a' => [1]], [['op' => 'add', 'path' => '/a/x', 'value' => 1]]],
            'cannot descend into scalar' => ['OPERATION_PATH_UNRESOLVABLE', ['a' => 1], [['op' => 'add', 'path' => '/a/b', 'value' => 1]]],
        ];
    }

    /**
     * @param list<mixed> $sequence
     */
    #[DataProvider('invalidSequences')]
    public function testValidateReportsTheFirstProblem(string $expected, mixed $document, array $sequence): void
    {
        $error = JsonPatch::validate($sequence, $document);

        $this->assertInstanceOf(JsonPatchError::class, $error);
        $this->assertSame($expected, $error->errorName);
    }

    public function testValidateReturnsNullForAValidSequence(): void
    {
        $this->assertNull(JsonPatch::validate([['op' => 'add', 'path' => '/b', 'value' => 1]], ['a' => 1]));
        $this->assertNull(JsonPatch::validate([['op' => 'remove', 'path' => '/a']]));
    }

    public function testValidateRejectsANonListSequence(): void
    {
        $error = JsonPatch::validate(['op' => 'add']);

        $this->assertSame('SEQUENCE_NOT_AN_ARRAY', $error?->errorName);
    }

    public function testApplyPatchWithValidationThrowsTheTaxonomyError(): void
    {
        try {
            JsonPatch::applyPatch(['a' => 1], [['op' => 'replace', 'path' => '/b', 'value' => 1]], true);
            $this->fail('expected a validation error');
        } catch (JsonPatchError $e) {
            $this->assertSame('OPERATION_PATH_UNRESOLVABLE', $e->errorName);
            $this->assertStringContainsString('name: OPERATION_PATH_UNRESOLVABLE', $e->getMessage());
        }
    }

    public function testACustomValidatorIsCalledPerOperation(): void
    {
        $seen = [];
        $validator = static function (array $op, int $i, mixed $doc, ?string $existing) use (&$seen): void {
            $seen[] = $op['op'];
        };

        JsonPatch::applyPatch(['a' => 1], [['op' => 'add', 'path' => '/b', 'value' => 2], ['op' => 'remove', 'path' => '/a']], $validator);

        // applyOperation validates up front and the path walk validates again, as upstream does.
        $this->assertSame(['add', 'remove'], array_values(array_unique($seen)));
    }

    /**
     * @param list<array<string, mixed>> $patch
     */
    private function expectValidationError(string $name, mixed $document, array $patch): void
    {
        try {
            JsonPatch::applyPatch($document, $patch, true);
            $this->fail("expected $name");
        } catch (JsonPatchError $e) {
            $this->assertSame($name, $e->errorName);
        }
    }

    // ---------------------------------------------------------------- helpers

    public function testEscapeAndUnescapeRoundTrip(): void
    {
        $this->assertSame('a~1b~0c', JsonPatch::escapePathComponent('a/b~c'));
        $this->assertSame('a/b~c', JsonPatch::unescapePathComponent('a~1b~0c'));
        $this->assertSame('plain', JsonPatch::escapePathComponent('plain'));
    }

    public function testUnescapeOrderIsSlashThenTilde(): void
    {
        $this->assertSame('~1', JsonPatch::unescapePathComponent('~01'));
    }

    public function testIsIntegerMatchesUpstream(): void
    {
        $this->assertTrue(JsonPatch::isInteger('0'));
        $this->assertTrue(JsonPatch::isInteger('42'));
        $this->assertTrue(JsonPatch::isInteger(''));
        $this->assertFalse(JsonPatch::isInteger('-1'));
        $this->assertFalse(JsonPatch::isInteger('1.5'));
        $this->assertFalse(JsonPatch::isInteger('a'));
    }

    public function testGetPathFindsNestedValues(): void
    {
        // Upstream's trailing slash is preserved.
        $this->assertSame('/a/b/1/', JsonPatch::getPath(['a' => ['b' => ['x', 'target']]], 'target'));
        $this->assertSame('/', JsonPatch::getPath('same', 'same'));
        $this->expectException(\RuntimeException::class);
        JsonPatch::getPath(['a' => 1], 'missing');
    }

    public function testAreEqualsFollowsJavaScriptNumbersAndIgnoresKeyOrder(): void
    {
        $this->assertTrue(JsonPatch::areEquals(1, 1.0));
        $this->assertTrue(JsonPatch::areEquals(['a' => 1, 'b' => [1, 2]], ['b' => [1, 2], 'a' => 1.0]));
        $this->assertFalse(JsonPatch::areEquals(1, '1'));
        $this->assertFalse(JsonPatch::areEquals([1, 2], [2, 1]));
        $this->assertFalse(JsonPatch::areEquals(['a' => 1], [1]));
        $this->assertFalse(JsonPatch::areEquals(['a' => null], ['b' => null]));
    }

    public function testDeepEqualsIsStrictAboutTypes(): void
    {
        $this->assertTrue(JsonPatch::deepEquals(['a' => [1, 2]], ['a' => [1, 2]]));
        $this->assertFalse(JsonPatch::deepEquals(['a' => 1], ['a' => '1']));
        $this->assertFalse(JsonPatch::deepEquals(1, 1.0));
    }

    public function testIsFalsyTreatsEmptyContainersAsTruthy(): void
    {
        $this->assertTrue(JsonPatch::isFalsy(null));
        $this->assertTrue(JsonPatch::isFalsy(''));
        $this->assertTrue(JsonPatch::isFalsy(0));
        $this->assertFalse(JsonPatch::isFalsy([]));
        $this->assertFalse(JsonPatch::isFalsy('0'));
    }

    public function testDeepCloneFlattensObjects(): void
    {
        $this->assertSame(['a' => 1], JsonPatch::deepClone((object) ['a' => 1]));
        $this->assertSame([1, 2], JsonPatch::deepClone([1, 2]));
    }

    // ---------------------------------------------------------------- compare

    public function testCompareEmitsRemovalsReplacementsThenAdditions(): void
    {
        $ops = JsonPatch::compare(['a' => 1, 'b' => 2, 'c' => 3], ['a' => 9, 'c' => 3, 'd' => 4]);

        $this->assertSame([
            ['op' => 'remove', 'path' => '/b'],
            ['op' => 'replace', 'path' => '/a', 'value' => 9],
            ['op' => 'add', 'path' => '/d', 'value' => 4],
        ], $ops);
    }

    public function testCompareEscapesPathSegments(): void
    {
        $this->assertSame(
            [['op' => 'add', 'path' => '/a~1b', 'value' => 1]],
            JsonPatch::compare([], ['a/b' => 1]),
        );
    }

    public function testCompareRecursesIntoNestedContainers(): void
    {
        $this->assertSame(
            [['op' => 'replace', 'path' => '/a/b/0', 'value' => 'z']],
            JsonPatch::compare(['a' => ['b' => ['x']]], ['a' => ['b' => ['z']]]),
        );
    }

    public function testCompareOfEqualValuesIsEmpty(): void
    {
        $this->assertSame([], JsonPatch::compare(['a' => [1, 2]], ['a' => [1, 2]]));
    }

    public function testCompareInvertiblePrecedesEachChangeWithATest(): void
    {
        $ops = JsonPatch::compare(['a' => 1, 'b' => 2], ['a' => 5], true);

        $this->assertSame([
            ['op' => 'test', 'path' => '/b', 'value' => 2],
            ['op' => 'remove', 'path' => '/b'],
            ['op' => 'test', 'path' => '/a', 'value' => 1],
            ['op' => 'replace', 'path' => '/a', 'value' => 5],
        ], $ops);
    }

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function roundTrips(): array
    {
        return [
            'scalar change' => [['a' => 1], ['a' => 2]],
            'add and remove' => [['a' => 1, 'b' => 2], ['b' => 2, 'c' => 3]],
            'nested' => [['a' => ['b' => ['c' => 1]]], ['a' => ['b' => ['c' => 2, 'd' => [1]]]]],
            'list growth' => [['a' => [1, 2]], ['a' => [1, 2, 3, 4]]],
            'list shrink' => [['a' => [1, 2, 3]], ['a' => [1]]],
            'list to object' => [['a' => [1]], ['a' => ['k' => 1]]],
            'string growth' => [['s' => 'He'], ['s' => 'Hello']],
        ];
    }

    #[DataProvider('roundTrips')]
    public function testApplyingACompareResultReproducesTheTarget(mixed $from, mixed $to): void
    {
        $this->assertEquals($to, self::apply($from, JsonPatch::compare($from, $to)));
    }

    #[DataProvider('roundTrips')]
    public function testAnInvertiblePatchAppliesCleanlyWithValidation(mixed $from, mixed $to): void
    {
        $this->assertEquals($to, self::apply($from, JsonPatch::compare($from, $to, true), true));
    }

    // ------------------------------------------- the OutputParsers alias

    public function testTheOutputParsersAliasDelegatesToUtilsJsonPatch(): void
    {
        $prev = ['a' => 1];
        $next = ['a' => 2, 'b' => 3];

        $this->assertSame(JsonPatch::compare($prev, $next), OutputParsersJsonPatch::compare($prev, $next));
        $this->assertSame(JsonPatch::deepEquals($prev, $next), OutputParsersJsonPatch::deepEquals($prev, $next));
        $this->assertSame(JsonPatch::isFalsy([]), OutputParsersJsonPatch::isFalsy([]));
        $this->assertSame('a~1b', OutputParsersJsonPatch::escapePathComponent('a/b'));
    }

    public function testNothingOutsideTheAliasStillDefinesItsOwnDiff(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/LangChain/OutputParsers/JsonPatch.php');

        $this->assertStringContainsString('Utils\\JsonPatch', $source);
        $this->assertStringNotContainsString('function generate', $source);
    }

    // -------------------------------------------------------------- end to end

    /**
     * A real streaming run: tokens through `JsonOutputParser` in diff mode produce
     * patches, and replaying every patch with {@see JsonPatch::applyPatch()} must land
     * on exactly the document a one-shot parse gives.
     */
    public function testStreamedDiffPatchesReplayToTheFinalDocument(): void
    {
        $tokens = ['{"setup": "Why did', ' the bear', ' sit down?", "pun', 'chline": "Bare', ' with me", "tags": ["a",', ' "b"]}'];
        $parser = new JsonOutputParser(['diff' => true]);

        $document = null;
        $batches = 0;
        foreach ($parser->transform((static function () use ($tokens): \Generator {
            yield from $tokens;
        })()) as $operations) {
            $batches++;
            $document = self::apply($document, $operations, true);
        }

        $this->assertGreaterThan(1, $batches);
        $this->assertEquals($parser->parse(implode('', $tokens)), $document);
        $this->assertSame(['a', 'b'], $document['tags']);
    }
}
