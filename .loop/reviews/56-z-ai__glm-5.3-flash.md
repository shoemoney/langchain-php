# Review 56 - z-ai/glm-5.3-flash
_asked 2026-09-29T21:27:55 - served by z-ai/glm-5.3-flash - 46s_

## 1. `Completions::encode()` does not exist — Anthropic path fatals on scalar message content
**Severity:** BLOCKER
**Evidence:** `Chat/Anthropic/Utils/MessageInputs.php` `stringify()` ends with `\LangChain\LanguageModels\Chat\OpenAI\Utils\Completions::encode($content)`; the full `Completions.php` in the packet defines no `encode()` method (it uses `Js::encode` internally). Verify in repo — packet shows the whole file.
**Why it matters:** Any message whose content is a non-string, non-null scalar (e.g. a numeric content) hits `formatContent()` → `stringify()` → "Call to undefined method" fatal. The suite is green only because no test exercises that branch.
**Suggested fix:** Change the call to `\LangChain\Utils\Js::encode($content)` (mirroring `Completions::stringifyContent`), and add a test with an integer-content message through `MessageInputs::convert()`.

## 2. Tools reach the wire unconverted on two paths
**Severity:** MAJOR
**Evidence:** `ChatOpenAI` constructor puts `tools` in the `kwargs` whitelist, and `invocationParams()` uses `$bound['tools']` raw — only the per-call path runs `Tools::convertAll()`. `ChatAnthropic::invocationParams()` uses `$this->pick($options, 'tools') ?? $bound['tools']` with no `MessageInputs::convertTool()` on either.
**Why it matters:** `new ChatOpenAI(['tools' => [$tool]])` or a per-call `tools` option on Anthropic sends a `StructuredTool` object / unshaped array straight to the provider — the exact "constructor blob on the wire" failure class already fixed for `bindTools`, with no error anywhere.
**Suggested fix:** Route `$bound['tools']` (both clients) and Anthropic's per-call `tools` through the same conversion as the bind/per-call OpenAI path; conversion is idempotent for already-shaped arrays.

## 3. Eager `post()` retries every `HttpException` regardless of status
**Severity:** MAJOR
**Evidence:** `ChatOpenAI::post()` catch of `HttpException` retries unconditionally until `maxRetries`; `postStream()` in the same class filters `$e->status === 0 || 429 || >= 500`. Same asymmetry in `ChatAnthropic::post()` vs `streamResponseChunks()`.
**Why it matters:** `HttpClient` is a public interface; a transport that raises `HttpException` with a 4xx status gets retried `maxRetries` times, contradicting the class docblock "a 4xx is not retried" and burning round trips on deterministic failures.
**Suggested fix:** Apply the same `$e->status === 0 || $e->status === 429 || $e->status >= 500` retryable predicate in both `post()` catch blocks.

## 4. `ChatAnthropic::$defaultHeaders` declared type contradicts its use
**Severity:** MINOR
**Evidence:** Property docblock is `@var list<array<string, mixed>>`, but `headers()` does `$this->defaultHeaders + ['x-api-key' => ..., ...]` — a name=>value map union.
**Why it matters:** A caller following the declared type passes `[['X-Foo' => 'bar']]`; the union then yields array-valued "headers" that Guzzle rejects or mis-sends at request time, far from the construction site.
**Suggested fix:** Correct the docblock to `array<string, string>` and cast/validate keys in the constructor (`array_map('strval', ...)`).

## 5. `bindTools()` records `strict` in `kwargs` where nothing reads it
**Severity:** MINOR
**Evidence:** `ChatOpenAI::bindTools()` loop copies every `$kwargs` key except `tools` into `$next->kwargs`, including `strict`; `invocationParams()` never reads a `strict` key (it was already consumed via `supportsStrictToolCalling`).
**Why it matters:** Same class as the documented dead-flag bugs (`streamUsage`, `user`/`seed`): a value serialized into every trace that changes nothing, inviting a caller to believe re-binding `strict` per call does something.
**Suggested fix:** Skip `strict` in the copy loop (as `ChatAnthropic::bindTools` already does) or read it in `invocationParams()`; add a regression assertion that `kwargs()` contains no `strict`.

That is five; all are read from the packet source, none appear in HANDOFF/PORT_STATUS tables. #1 is the one I'd verify first — it is the only outright fatal.