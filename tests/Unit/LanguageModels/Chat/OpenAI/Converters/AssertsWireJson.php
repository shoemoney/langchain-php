<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Converters;

use LangChain\Utils\Js;

/**
 * Compare converter output as the JSON the API would read, not as PHP arrays.
 *
 * PHP has one array type, so `[]` and `{}` are the same value in memory and
 * different values on the wire (the no-argument-tool bug class). Comparing the
 * decoded JSON keeps that distinction, while ignoring object key order the way
 * the upstream `toEqual` does.
 */
trait AssertsWireJson
{
    protected static function assertWire(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertJsonStringEqualsJsonString(Js::encode($expected), Js::encode($actual), $message);
    }

    /**
     * Run a callable and collect the E_USER_WARNINGs it raises (the port's `console.warn`).
     *
     * @return list<string>
     */
    protected static function captureWarnings(callable $fn): array
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;

            return true;
        }, E_USER_WARNING);

        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }
}
