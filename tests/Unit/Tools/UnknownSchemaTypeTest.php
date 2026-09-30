<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Tools\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A type this validator cannot check is not a type it can call valid.
 *
 * `matchesType()` handled all seven JSON Schema types explicitly and then
 * returned `true` for anything else, so an unrecognised type accepted every
 * input. Measured before the fix: a schema of `{"type":"strng"}` validated
 * `["a" => 42]`, `[]` and `null` without complaint.
 *
 * That is the fail-open direction, and the expensive one: the schema LOOKS
 * declared, the caller believes a constraint applies, and nothing enforces it.
 * A typo in one string is enough to switch a tool's validation off.
 *
 * Upstream parses with zod, where an unknown type is a parse error rather than
 * a silent pass, so rejecting is also the faithful translation.
 */
#[CoversClass(Schema::class)]
final class UnknownSchemaTypeTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function unrecognisedTypes(): array
    {
        return [
            'typo string' => ['strng'],
            'typo object' => ['objct'],
            'wrong case' => ['Object'],
            'invented type' => ['money'],
        ];
    }

    #[DataProvider('unrecognisedTypes')]
    public function testAnUnknownTypeValidatesNothing(string $type): void
    {
        $schema = Schema::from(['type' => $type]);

        foreach ([['a' => 42], [], null, 'text', 1.5] as $value) {
            self::assertNotSame(
                [],
                $schema->errors($value),
                sprintf('type %s is not implemented, so it must not silently accept %s', $type, json_encode($value)),
            );
        }
    }

    /**
     * The seven real types must be untouched — a fix that hardened the default
     * arm by breaking the known ones would be worse than the bug.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function realTypes(): array
    {
        return [
            'string' => ['string', 's'],
            'integer' => ['integer', 1],
            'integer as 3.0' => ['integer', 3.0],
            'number' => ['number', 1.5],
            'boolean' => ['boolean', true],
            'null' => ['null', null],
            'array' => ['array', [1]],
            'object' => ['object', ['k' => 'v']],
        ];
    }

    #[DataProvider('realTypes')]
    public function testAKnownTypeStillAcceptsItsOwnValue(string $type, mixed $value): void
    {
        self::assertSame(
            [],
            Schema::from(['type' => $type])->errors($value),
            sprintf('type %s must still accept %s', $type, json_encode($value)),
        );
    }

    public function testAKnownTypeStillRejectsTheWrongValue(): void
    {
        self::assertNotSame([], Schema::from(['type' => 'string'])->errors(42));
        self::assertNotSame([], Schema::from(['type' => 'integer'])->errors(1.5));
        self::assertNotSame([], Schema::from(['type' => 'boolean'])->errors('true'));
    }

    /** A schema with no `type` at all is not an unknown TYPE and must still validate. */
    public function testATypelessSchemaIsUnaffected(): void
    {
        self::assertSame([], Schema::from([])->errors(['anything' => 'goes']));
        self::assertSame([], Schema::from(['properties' => ['a' => ['type' => 'string']]])->errors([]));
    }
}
