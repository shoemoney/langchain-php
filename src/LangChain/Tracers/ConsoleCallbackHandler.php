<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * A tracer that prints each run to a stream as it happens.
 *
 * Port of `ConsoleCallbackHandler` from `@langchain/core/tracers/console`.
 *
 * Every message carries a breadcrumb path — `2:chain:RunnableSequence >
 * 3:llm:ChatOpenAI` — because a console trace is read out of order and without
 * it there is no way to tell which of a dozen interleaved runs a line belongs
 * to. The breadcrumb is built by walking parents rather than by carrying the
 * path along, so it stays correct no matter the order events arrive in.
 *
 * Output goes to a stream rather than straight to stdout so tests can capture
 * it: writing to real stdout would break `beStrictAboutOutputDuringTests`.
 */
final class ConsoleCallbackHandler extends BaseTracer
{
    public string $name = 'console_callback_handler';

    /** @var resource The destination. Defaults to stdout. */
    private $stream;

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [], mixed $stream = null)
    {
        parent::__construct($fields);
        $this->stream = $stream ?? (defined('STDOUT') ? STDOUT : fopen('php://output', 'wb'));
    }

    /**
     * Nothing to persist — this tracer's storage IS the stream.
     */
    protected function persistRun(Run $run): void
    {
    }

    /**
     * This run's ancestors, nearest first.
     *
     * @return list<Run>
     */
    public function getParents(Run $run): array
    {
        $parents = [];
        $currentRun = $run;

        while ($currentRun->parentRunId !== null) {
            $parent = $this->runMap[$currentRun->parentRunId] ?? null;
            if ($parent === null) {
                // The parent already ended and was filed; the chain is broken
                // here and there is nothing further to walk.
                break;
            }
            $parents[] = $parent;
            $currentRun = $parent;
        }

        return $parents;
    }

    /**
     * The run's lineage as a `parent > child > … > run` string.
     *
     * The current run is bolded: it is the one the reader is looking at, and
     * every other segment is dimmed context.
     */
    public function getBreadcrumbs(Run $run): string
    {
        $chain = array_reverse($this->getParents($run));
        $chain[] = $run;

        $last = count($chain) - 1;
        $parts = [];
        foreach ($chain as $i => $parent) {
            $name = sprintf('%d:%s:%s', $parent->executionOrder, $parent->runType, $parent->name());
            $parts[] = $i === $last ? self::bold($name) : $name;
        }

        return self::grey(implode(' > ', $parts));
    }

    /**
     * Milliseconds between start and end, or `''` for an open run.
     */
    private static function elapsed(Run $run): string
    {
        if ($run->endTime === null) {
            return '';
        }
        $elapsed = $run->endTime - $run->startTime;

        return $elapsed < 1000
            ? sprintf('%dms', $elapsed)
            : sprintf('%.2fs', $elapsed / 1000);
    }

    // ---- logging ----------------------------------------------------------

    protected function onChainStart(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] Entering Chain run with input: %s',
            self::green('[chain/start]'),
            $this->getBreadcrumbs($run),
            self::json($run->inputs, '[inputs]'),
        ));
    }

    protected function onChainEnd(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] [%s] Exiting Chain run with output: %s',
            self::cyan('[chain/end]'),
            $this->getBreadcrumbs($run),
            self::elapsed($run),
            self::json($run->outputs, '[outputs]'),
        ));
    }

    protected function onChainError(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] [%s] Chain run errored with error: %s',
            self::red('[chain/error]'),
            $this->getBreadcrumbs($run),
            self::elapsed($run),
            self::json($run->error, '[error]'),
        ));
    }

    protected function onLLMStart(Run $run): void
    {
        // Prompts are trimmed for display: the leading indentation a prompt
        // template leaves behind makes the console output unreadable, and the
        // indentation is not information.
        $inputs = $run->inputs;
        if (isset($inputs['prompts']) && is_array($inputs['prompts'])) {
            $inputs = ['prompts' => array_map(
                static fn (mixed $p): mixed => is_string($p) ? trim($p) : $p,
                $inputs['prompts'],
            )];
        }

        $this->log(sprintf(
            '%s [%s] Entering LLM run with input: %s',
            self::green('[llm/start]'),
            $this->getBreadcrumbs($run),
            self::json($inputs, '[inputs]'),
        ));
    }

    protected function onLLMEnd(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] [%s] Exiting LLM run with output: %s',
            self::cyan('[llm/end]'),
            $this->getBreadcrumbs($run),
            self::elapsed($run),
            self::json($run->outputs, '[response]'),
        ));
    }

    protected function onLLMError(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] [%s] LLM run errored with error: %s',
            self::red('[llm/error]'),
            $this->getBreadcrumbs($run),
            self::elapsed($run),
            self::json($run->error, '[error]'),
        ));
    }

    protected function onToolStart(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] Entering Tool run with input: "%s"',
            self::green('[tool/start]'),
            $this->getBreadcrumbs($run),
            self::kvItem($run->inputs['input'] ?? null),
        ));
    }

    protected function onToolEnd(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] [%s] Exiting Tool run with output: "%s"',
            self::cyan('[tool/end]'),
            $this->getBreadcrumbs($run),
            self::elapsed($run),
            self::kvItem($run->outputs['output'] ?? null),
        ));
    }

    protected function onToolError(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] [%s] Tool run errored with error: %s',
            self::red('[tool/error]'),
            $this->getBreadcrumbs($run),
            self::elapsed($run),
            self::json($run->error, '[error]'),
        ));
    }

    protected function onRetrieverStart(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] Entering Retriever run with input: %s',
            self::green('[retriever/start]'),
            $this->getBreadcrumbs($run),
            self::json($run->inputs, '[inputs]'),
        ));
    }

    protected function onRetrieverEnd(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] [%s] Exiting Retriever run with output: %s',
            self::cyan('[retriever/end]'),
            $this->getBreadcrumbs($run),
            self::elapsed($run),
            self::json($run->outputs, '[outputs]'),
        ));
    }

    protected function onRetrieverError(Run $run): void
    {
        $this->log(sprintf(
            '%s [%s] [%s] Retriever run errored with error: %s',
            self::red('[retriever/error]'),
            $this->getBreadcrumbs($run),
            self::elapsed($run),
            self::json($run->error, '[error]'),
        ));
    }

    protected function onAgentAction(Run $run): void
    {
        $actions = $run->actions;
        $this->log(sprintf(
            '%s [%s] Agent selected action: %s',
            self::blue('[agent/action]'),
            $this->getBreadcrumbs($run),
            self::json($actions === [] ? null : $actions[count($actions) - 1], '[action]'),
        ));
    }

    // ---- output helpers ---------------------------------------------------

    private function log(string $message): void
    {
        if (is_resource($this->stream)) {
            fwrite($this->stream, $message . "\n");
        }
    }

    /**
     * Pretty-printed JSON, or the fallback when the value cannot be encoded.
     */
    private static function json(mixed $value, string $fallback): string
    {
        $encoded = json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? $fallback : $encoded;
    }

    /**
     * A value from an inputs/outputs map, rendered for inline display.
     *
     * Strings pass through trimmed; everything else is JSON. This exists because
     * a tool's input is usually a short string that should read inline, while a
     * structured input needs to be expanded.
     */
    private static function kvItem(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if ($value === null) {
            return 'null';
        }

        return self::json($value, '');
    }

    private static function bold(string $text): string
    {
        return "\u{1b}[1m" . $text . "\u{1b}[22m";
    }

    private static function grey(string $text): string
    {
        return "\u{1b}[90m" . $text . "\u{1b}[39m";
    }

    private static function green(string $text): string
    {
        return "\u{1b}[32m" . $text . "\u{1b}[39m";
    }

    private static function cyan(string $text): string
    {
        return "\u{1b}[36m" . $text . "\u{1b}[39m";
    }

    private static function red(string $text): string
    {
        return "\u{1b}[31m" . $text . "\u{1b}[39m";
    }

    private static function blue(string $text): string
    {
        return "\u{1b}[34m" . $text . "\u{1b}[39m";
    }
}
