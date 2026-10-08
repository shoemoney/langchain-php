<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Support;

use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Testing\FakeToolCallingChatModel;

/**
 * Stands in for `vi.spyOn(model, "invoke")` plus `vi.spyOn(model, "_generate").mockImplementation(...)`: a scripted
 * model that records what `invoke()` was given and fails its first `$failFirst` generations.
 *
 * The record is shared by every copy `bindTools()` makes, so a call made through the model an agent bound is seen
 * on the model the test holds.
 */
final class FlakyChatModel extends FakeToolCallingChatModel
{
    /** @var \ArrayObject<string, mixed> `invokeCalls` (the inputs) and `generateCalls` (a count). */
    public \ArrayObject $record;

    public int $failFirst = 0;

    public string $failMessage = 'simulated failure';

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct(['sleep' => 0, ...$fields]);
        $this->record = new \ArrayObject(['invokeCalls' => [], 'generateCalls' => 0]);
        $this->failFirst = (int) ($fields['failFirst'] ?? 0);
        $this->failMessage = (string) ($fields['failMessage'] ?? $this->failMessage);
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $calls = $this->record['invokeCalls'];
        $calls[] = $input;
        $this->record['invokeCalls'] = $calls;

        return parent::invoke($input, $config);
    }

    /** @return list<mixed> */
    public function invokeCalls(): array
    {
        return $this->record['invokeCalls'];
    }

    public function generateCalls(): int
    {
        return $this->record['generateCalls'];
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $this->record['generateCalls'] = $this->record['generateCalls'] + 1;
        if ($this->record['generateCalls'] <= $this->failFirst) {
            throw new \Exception($this->failMessage);
        }

        return parent::generate($messages, $options, $runManager);
    }
}
