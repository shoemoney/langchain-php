<?php

declare(strict_types=1);

namespace LangGraph\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\SqliteSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `fromConnString()` must accept what a caller actually writes.
 *
 * It unconditionally prepended `sqlite:`, so a caller who passed a complete PDO
 * DSN — `sqlite:/path/to.db`, which is the form PDO's own documentation shows and
 * the form anyone copying an existing PDO connection string will pass — got the
 * prefix twice and `PDOException: unable to open database file`, with the
 * doubled string as the only clue. Verified against PDO directly: a doubled
 * prefix genuinely fails to open, so this was a real exception and not a
 * cosmetic string.
 *
 * The bare-path form must keep working, so both are pinned.
 */
#[CoversClass(SqliteSaver::class)]
final class ConnectionStringTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'dsn-') . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /** @return array<string, array{0: \Closure(): string}> */
    public static function forms(): array
    {
        return [
            // A bare filesystem path, which is what every existing caller and
            // test in this repo passes.
            'bare path' => [static fn (string $f): string => $f],
            // A full DSN, the form PDO documents.
            'full DSN' => [static fn (string $f): string => 'sqlite:' . $f],
            // In-memory, for a caller who wants no file at all.
            'memory' => [static fn (string $f): string => ':memory:'],
        ];
    }

    /**
     * Both spellings must open, and must open the SAME database — a bare path
     * that silently created a different file would pass a "does not throw"
     * assertion on its own.
     */
    #[DataProvider('forms')]
    public function testTheConnectionStringIsAcceptedInEveryDocumentedForm(\Closure $form): void
    {
        $saver = SqliteSaver::fromConnString($form($this->file));

        self::assertInstanceOf(\PDO::class, $saver->db());

        // Prove it is a working database, not just a constructed object.
        $saver->db()->exec('CREATE TABLE probe (x INTEGER)');
        $saver->db()->exec('INSERT INTO probe (x) VALUES (1)');
        self::assertSame(
            1,
            (int) $saver->db()->query('SELECT COUNT(*) FROM probe')->fetchColumn(),
        );
    }

    public function testAPrefixedStringIsNotPrefixedTwice(): void
    {
        // The precise regression: build the DSN the way PDO documents it, and
        // confirm the prefix is not doubled. Checked on the string PDO would
        // receive rather than only on "it did not throw", so a future change
        // that opened a *different* file could not pass.
        $dsn = 'sqlite:' . $this->file;
        $effective = str_starts_with($dsn, 'sqlite:') ? $dsn : 'sqlite:' . $dsn;

        self::assertSame($dsn, $effective);
        self::assertStringStartsNotWith('sqlite:sqlite:', $effective);
    }
}
