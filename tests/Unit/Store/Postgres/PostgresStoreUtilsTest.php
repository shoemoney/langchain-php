<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Postgres;

use LangGraph\Store\InvalidNamespaceError;
use LangGraph\Store\Postgres\Modules\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `store/modules/utils.test.ts` from `@langchain/langgraph-checkpoint-postgres`.
 */
#[CoversClass(Utils::class)]
final class PostgresStoreUtilsTest extends TestCase
{
    public function testAcceptsASimpleWellFormedNamespace(): void
    {
        Utils::validateNamespace(['tenants', 'acme', 'users']);
        $this->addToAssertionCount(1);
    }

    public function testRejectsEmptyNamespaceArrays(): void
    {
        $this->expectException(InvalidNamespaceError::class);
        $this->expectExceptionMessageMatches('/cannot be empty/');
        Utils::validateNamespace([]);
    }

    public function testRejectsEmptyStringLabels(): void
    {
        $this->expectExceptionMessageMatches('/cannot be empty strings/');
        Utils::validateNamespace(['tenants', '']);
    }

    public function testRejectsLabelsContainingPeriods(): void
    {
        $this->expectExceptionMessageMatches('/cannot contain periods/');
        Utils::validateNamespace(['a.b']);
    }

    public function testRejectsTheReservedLanggraphRootLabel(): void
    {
        $this->expectExceptionMessageMatches('/Root label.*cannot be "langgraph"/');
        Utils::validateNamespace(['langgraph', 'users']);
    }

    public function testRejectsNonStringLabels(): void
    {
        $this->expectExceptionMessageMatches('/Namespace labels must be strings/');
        Utils::validateNamespace(['valid', 123]);
    }

    /**
     * @param list<string> $labels
     */
    #[DataProvider('likeSpecialNamespaces')]
    public function testRejectsNamespacesWithLikeSpecialLabels(array $labels): void
    {
        $this->expectException(InvalidNamespaceError::class);
        $this->expectExceptionMessageMatches('/SQL LIKE wildcards.*backslash/');
        Utils::validateNamespace($labels);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function likeSpecialNamespaces(): iterable
    {
        yield 'percent' => [['%']];
        yield 'underscore' => [['_']];
        yield 'backslash' => [['\\']];
        yield 'embedded percent' => [['acme%']];
        yield 'embedded underscore' => [['acme_users']];
        yield 'embedded backslash' => [['acme\\users']];
        yield 'second label' => [['users', '%']];
    }

    public function testDoesNotRejectBenignCharactersThatLookSimilar(): void
    {
        // The colon is the namespace path separator; hyphens, digits and unicode are fine.
        Utils::validateNamespace(['tenant-1', 'user:42', 'プロジェクト']);
        $this->addToAssertionCount(1);
    }
}
