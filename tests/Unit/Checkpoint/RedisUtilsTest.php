<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Redis\RedisUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/utils.test.ts` from `@langchain/langgraph-checkpoint-redis`.
 */
#[CoversClass(RedisUtils::class)]
final class RedisUtilsTest extends TestCase
{
    // ---- escapeRediSearchTagValue -----------------------------------------

    public function testShouldReturnPlaceholderForEmptyString(): void
    {
        self::assertSame('__EMPTY_STRING__', RedisUtils::escapeRediSearchTagValue(''));
    }

    public function testShouldEscapeBackslashes(): void
    {
        self::assertSame('foo\\\\bar', RedisUtils::escapeRediSearchTagValue('foo\\bar'));
    }

    public function testShouldEscapeSpecialCharacters(): void
    {
        self::assertSame('hello\\-world', RedisUtils::escapeRediSearchTagValue('hello-world'));
        self::assertSame('foo\\.bar', RedisUtils::escapeRediSearchTagValue('foo.bar'));
        self::assertSame('test\\:value', RedisUtils::escapeRediSearchTagValue('test:value'));
        self::assertSame('key\\=value', RedisUtils::escapeRediSearchTagValue('key=value'));
        self::assertSame('a\\|b', RedisUtils::escapeRediSearchTagValue('a|b'));
        self::assertSame('\\(test\\)', RedisUtils::escapeRediSearchTagValue('(test)'));
        self::assertSame('\\{test\\}', RedisUtils::escapeRediSearchTagValue('{test}'));
        self::assertSame('\\[test\\]', RedisUtils::escapeRediSearchTagValue('[test]'));
    }

    public function testShouldEscapeSpaces(): void
    {
        self::assertSame('hello\\ world', RedisUtils::escapeRediSearchTagValue('hello world'));
    }

    public function testShouldNotModifyStringsWithoutSpecialCharacters(): void
    {
        self::assertSame('simple', RedisUtils::escapeRediSearchTagValue('simple'));
        self::assertSame('CamelCase', RedisUtils::escapeRediSearchTagValue('CamelCase'));
        self::assertSame('under_score', RedisUtils::escapeRediSearchTagValue('under_score'));
    }

    public function testShouldPreventRediSearchOrInjectionAttempts(): void
    {
        // The payload that could escape thread boundaries.
        $escaped = RedisUtils::escapeRediSearchTagValue('x}) | (@thread_id:{*');

        self::assertSame('x\\}\\)\\ \\|\\ \\(\\@thread_id\\:\\{\\*', $escaped);
        // No raw pipe, brace or parenthesis survives unescaped.
        self::assertSame(0, preg_match('/(?<!\\\\)[|{}()]/', $escaped));
    }

    public function testShouldPreventRediSearchKeyInjectionAttempts(): void
    {
        self::assertSame(
            '\\}\\)\\|\\(\\@thread_id\\:\\{\\*\\}\\)\\|\\(\\@x',
            RedisUtils::escapeRediSearchTagValue('})|(@thread_id:{*})|(@x'),
        );
    }

    public function testShouldHandleMultipleConsecutiveSpecialCharacters(): void
    {
        self::assertSame('\\{\\{\\}\\}', RedisUtils::escapeRediSearchTagValue('{{}}'));
        self::assertSame('\\|\\|\\|', RedisUtils::escapeRediSearchTagValue('|||'));
        self::assertSame('\\.\\.\\.', RedisUtils::escapeRediSearchTagValue('...'));
    }

    public function testShouldHandleMixedContent(): void
    {
        self::assertSame('user\\@example\\.com', RedisUtils::escapeRediSearchTagValue('user@example.com'));
        self::assertSame('price\\:\\ \\$100', RedisUtils::escapeRediSearchTagValue('price: $100'));
    }

    // ---- assertSafeKeyComponent -------------------------------------------

    public function testAcceptsANormalIdentifier(): void
    {
        RedisUtils::assertSafeKeyComponent('thread_id', 'tenant-a-thread-1');
        RedisUtils::assertSafeKeyComponent('checkpoint_id', '01HZX9V7EKJ1B0PNMY7MX3X3KB');

        $this->addToAssertionCount(2);
    }

    public function testAcceptsTheDocumentedEmptyCheckpointNsWhenAllowEmptyIsSet(): void
    {
        RedisUtils::assertSafeKeyComponent('checkpoint_ns', '', allowEmpty: true);

        $this->addToAssertionCount(1);
    }

    public function testRejectsTheEmptyStringWhenAllowEmptyIsNotSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty string is not permitted/');

        RedisUtils::assertSafeKeyComponent('thread_id', '');
    }

    /** The `deleteThread` wipe vector. */
    public function testRejectsTheRedisGlobWildcard(): void
    {
        foreach (['*', 'tenant-*'] as $value) {
            try {
                RedisUtils::assertSafeKeyComponent('thread_id', $value);
                self::fail("Expected {$value} to be rejected");
            } catch (\InvalidArgumentException $e) {
                // Even a single `*` anywhere in the value is rejected.
                self::assertMatchesRegularExpression('/Redis pattern meta-character/', $e->getMessage());
            }
        }
    }

    public function testRejectsTheRedisGlobSingleCharacter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Redis pattern meta-character/');

        RedisUtils::assertSafeKeyComponent('thread_id', 'tenant-?');
    }

    public function testRejectsTheRedisGlobCharacterClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Redis pattern meta-character/');

        RedisUtils::assertSafeKeyComponent('thread_id', 'tenant-[ab]');
    }

    public function testRejectsBackslash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Redis pattern meta-character/');

        RedisUtils::assertSafeKeyComponent('thread_id', 'tenant\\a');
    }

    /**
     * LangGraph builds subgraph namespaces as `name:taskId` joined by `|`. A colon is only
     * ever a literal in the key, so rejecting it would throw on every subgraph checkpoint.
     */
    public function testAcceptsAColonInCheckpointNs(): void
    {
        RedisUtils::assertSafeKeyComponent('checkpoint_ns', 'agent:01HZX9V7EKJ1B0PNMY7MX3X3KB', allowEmpty: true);
        RedisUtils::assertSafeKeyComponent(
            'checkpoint_ns',
            'agent:01HZX9V7EKJ1B0PNMY7MX3X3KB|tool:01HZX9V7EKJ1B0PNMY7MX3X3KC',
            allowEmpty: true,
        );

        $this->addToAssertionCount(2);
    }

    /** The NoSQL-style operator injection attempt. */
    public function testRejectsAnObjectValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/expected a string identifier/');

        RedisUtils::assertSafeKeyComponent('thread_id', (object) ['$ne' => null]);
    }

    public function testRejectsAnArrayValueWithThePreciseDiagnostic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/got array/');

        RedisUtils::assertSafeKeyComponent('thread_id', ['a']);
    }

    /**
     * JavaScript distinguishes `null` from `undefined`; PHP has no `undefined`, so that
     * case of the upstream test is the same input as `null` and is not repeated.
     */
    public function testRejectsNullNumberBooleanWithPreciseDiagnostics(): void
    {
        $expected = ['null' => null, 'number' => 42, 'boolean' => true];
        foreach ($expected as $label => $value) {
            try {
                RedisUtils::assertSafeKeyComponent('thread_id', $value);
                self::fail("Expected {$label} to be rejected");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("got {$label}", $e->getMessage());
            }
        }
    }

    public function testIncludesTheFieldNameInEveryErrorSoCallersCanSurfaceIt(): void
    {
        try {
            RedisUtils::assertSafeKeyComponent('checkpoint_id', '*');
            self::fail('Expected rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"checkpoint_id"', $e->getMessage());
        }

        try {
            RedisUtils::assertSafeKeyComponent('task_id', ['$gt' => '']);
            self::fail('Expected rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"task_id"', $e->getMessage());
        }
    }
}
