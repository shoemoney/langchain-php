<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat;

/**
 * Wire-key canonicalisation and first-present option lookup, shared by the chat providers.
 *
 * `ChatAnthropic` and `ChatOpenAI` carried byte-identical bodies for both members — 460 measured the pairs,
 * 468 triaged them as REAL (unlike the list-parser pair, which was legitimate) — so the logic lived twice on
 * live provider config paths and could drift independently.
 *
 * **The alias TABLES stay in each class and differ**: Anthropic maps six wire keys, OpenAI maps ten, adding
 * `frequency_penalty`, `presence_penalty`, `parallel_tool_calls` and `response_format`. That difference is
 * the reason this is a trait at all rather than one shared constant: each class keeps its own table.
 *
 * **`self::`, not `static::`, and that is load-bearing.** A trait's `self::` resolves to the COMPOSING
 * class, which is what the original code did; `static::` would resolve to the RUNTIME class, and
 * `NoSleepChatAnthropic` — a test subclass that inherits `canonicalise()` without redeclaring the private
 * `KEY_ALIASES` — would raise `Undefined constant`. Measured: switching to `static::` produced 55 errors
 * and 26 failures across tests/Unit/LanguageModels, all that one cause.
 *
 * Note that `ChatAnthropic::KEY_ALIASES` carries a `@see ChatOpenAI::KEY_ALIASES()` annotation describing
 * "one shared table", which is misleading — the tables are per-provider and differently sized. 468 recorded
 * it; the annotation belongs with the table it actually describes.
 */
trait NormalisesProviderOptions
{
    /**
     * Copy each wire-form key to its camelCase alias when the camelCase form is absent.
     *
     * Wire-first on purpose: the camelCase spelling wins when a caller supplies both, so an explicit
     * camelCase option is never silently overwritten by a default.
     *
     * @param array<string, mixed> $bag
     * @return array<string, mixed>
     */
    public static function canonicalise(array $bag): array
    {
        foreach (self::KEY_ALIASES as $wire => $camel) {
            if (array_key_exists($wire, $bag) && !array_key_exists($camel, $bag)) {
                $bag[$camel] = $bag[$wire];
            }
        }

        return $bag;
    }

    /**
     * The first key present and non-null, or null.
     *
     * `null` is skipped rather than returned so an explicitly-null option falls through to the next
     * spelling — the falsy-versus-absent distinction this port has been bitten by repeatedly.
     *
     * @param array<string, mixed> $options
     */
    private function pickOption(array $options, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                return $options[$key];
            }
        }

        return null;
    }
}
