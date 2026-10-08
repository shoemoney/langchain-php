<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Memory;

use LangChain\ChatHistory\InMemoryChatMessageHistory;
use LangChain\Memory\BaseMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Own tests for `memory.ts` (upstream has none).
 */
#[CoversClass(BaseMemory::class)]
final class BaseMemoryTest extends TestCase
{
    public function testGetInputValueReturnsTheOnlyValue(): void
    {
        self::assertSame('hello', BaseMemory::getInputValue(['input' => 'hello']));
    }

    public function testGetInputValueUsesTheNamedKey(): void
    {
        self::assertSame('b', BaseMemory::getInputValue(['x' => 'a', 'y' => 'b'], 'y'));
    }

    public function testGetInputValueIsAmbiguousWithManyKeysAndNoName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('input values have 2 keys, you must specify an input key or pass only 1 key as input');
        BaseMemory::getInputValue(['x' => 'a', 'y' => 'b']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function falsyInputs(): array
    {
        return ['empty string' => [''], 'null' => [null], 'zero' => [0], 'false' => [false]];
    }

    #[DataProvider('falsyInputs')]
    public function testGetInputValueRejectsFalsyValuesLikeUpstream(mixed $value): void
    {
        $this->expectException(\RuntimeException::class);
        BaseMemory::getInputValue(['input' => $value]);
    }

    public function testGetInputValueAcceptsStringZeroAndEmptyArray(): void
    {
        self::assertSame('0', BaseMemory::getInputValue(['input' => '0']));
        self::assertSame([], BaseMemory::getInputValue(['input' => []]));
    }

    public function testGetInputValueNamedKeyThatIsMissingThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        BaseMemory::getInputValue(['x' => 'a'], 'nope');
    }

    public function testGetOutputValueAllowsAnEmptyString(): void
    {
        self::assertSame('', BaseMemory::getOutputValue(['output' => '']));
    }

    public function testGetOutputValueReturnsTheOnlyValueOrTheNamedOne(): void
    {
        self::assertSame('out', BaseMemory::getOutputValue(['output' => 'out']));
        self::assertSame('2', BaseMemory::getOutputValue(['a' => '1', 'b' => '2'], 'b'));
    }

    public function testGetOutputValueIsAmbiguousWithManyKeysAndNoName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('output values have 2 keys, you must specify an output key or pass only 1 key as output');
        BaseMemory::getOutputValue(['a' => '1', 'b' => '2']);
    }

    public function testGetOutputValueRejectsNull(): void
    {
        $this->expectException(\RuntimeException::class);
        BaseMemory::getOutputValue(['output' => null]);
    }

    public function testGetPromptInputKeyExcludesMemoryVariablesAndStop(): void
    {
        self::assertSame(
            'question',
            BaseMemory::getPromptInputKey(['question' => 'q', 'history' => 'h', 'stop' => ['x']], ['history']),
        );
    }

    public function testGetPromptInputKeyNeedsExactlyOneKey(): void
    {
        try {
            BaseMemory::getPromptInputKey(['a' => 1, 'b' => 2], []);
            self::fail('two candidate keys must throw');
        } catch (\RuntimeException $e) {
            self::assertSame('One input key expected, but got 2', $e->getMessage());
        }

        $this->expectExceptionMessage('One input key expected, but got 0');
        BaseMemory::getPromptInputKey(['history' => 'h'], ['history']);
    }

    public function testAConcreteMemoryBackedByChatHistoryLoadsAndSaves(): void
    {
        $memory = new class (new InMemoryChatMessageHistory()) extends BaseMemory {
            public function __construct(private InMemoryChatMessageHistory $history)
            {
            }

            public function memoryKeys(): array
            {
                return ['history'];
            }

            public function loadMemoryVariables(array $values): array
            {
                return ['history' => implode("\n", array_map(
                    static fn ($m): string => $m->type . ': ' . $m->content,
                    $this->history->getMessages(),
                ))];
            }

            public function saveContext(array $inputValues, array $outputValues): void
            {
                $this->history->addUserMessage((string) self::getInputValue($inputValues));
                $this->history->addAIMessage((string) self::getOutputValue($outputValues));
            }
        };

        $memory->saveContext(['input' => 'hi'], ['output' => 'hello']);
        $memory->saveContext(['input' => 'bye'], ['output' => 'later']);

        self::assertSame(['history' => "human: hi\nai: hello\nhuman: bye\nai: later"], $memory->loadMemoryVariables([]));
        self::assertSame(['history'], $memory->memoryKeys());
    }
}
