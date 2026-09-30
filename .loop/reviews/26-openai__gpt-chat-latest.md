# Review 26 - openai/gpt-chat-latest
_asked 2026-09-29T10:59:42 - served by openai/gpt-chat-latest - 32s_

## 1. Stop silently corrupting tool-call arguments
**Severity:** BLOCKER
**Evidence:** `Completions::toolCallToWire()` uses `json_encode(..., JSON_PARTIAL_OUTPUT_ON_ERROR)`.
**Why it matters:** Unencodable PHP values can be replaced in the JSON instead of failing, so the provider receives different tool arguments than the caller supplied.
**Suggested fix:** Remove `JSON_PARTIAL_OUTPUT_ON_ERROR`, use `JSON_THROW_ON_ERROR`, and convert `JsonException` to an actionable model/tool-call exception.

## 2. Fail locally when provider request JSON cannot be encoded
**Severity:** MAJOR
**Evidence:** `ChatOpenAI::post()/postStream()` and `ChatAnthropic::post()/streamResponseChunks()` cast unchecked `json_encode()` results to string.
**Why it matters:** `json_encode()` can return `false`; the cast turns that into `''`, causing an empty request to hit the provider and disguising local data loss as an HTTP/API failure.
**Suggested fix:** Encode with `JSON_THROW_ON_ERROR` and wrap `JsonException` in the corresponding provider exception before invoking `HttpClient`.

## 3. Normalize Anthropic transport failures to AnthropicException
**Severity:** MAJOR
**Evidence:** `ChatAnthropic::post()` catches `HttpException` and, after retries, executes `throw $e`; non-2xx and streaming failures become `AnthropicException`.
**Why it matters:** A caller catching `AnthropicException` misses connection failures specifically, despite using the provider abstraction; OpenAI already normalizes this path.
**Suggested fix:** On exhausted transport retries, throw `AnthropicException::fromResponse($e->body, $e->status, $this->url(), ...)`, preserving `$e` as the previous cause if supported.

## 4. Repair the authoritative port-status accounting
**Severity:** BLOCKER
**Evidence:** `PORT_STATUS.md` calls itself “Live accounting” and its ledger totals 1904 tests, while the packet's measured live suite is 1991 tests.
**Why it matters:** The document designated authoritative cannot account for 87 passing tests, so subsystem/test-conversion status derived from it is currently a false claim.
**Suggested fix:** Reconcile the missing suites/tests into the ledger and generate or CI-check the total against PHPUnit so this cannot silently drift again.

## 5. Test the PHP version the project is actually running
**Severity:** MINOR
**Evidence:** `.github/workflows/ci.yml` tests PHP 8.2/8.3/8.4; measured state says PHP 8.5.11 locally and `composer.json` declares `php >=8.2`.
**Why it matters:** PHP 8.5 is within the advertised constraint but can regress without CI noticing, despite being the development runtime.
**Suggested fix:** Add PHP 8.5 to the CI matrix (or explicitly cap the Composer constraint below 8.5 if support is not intended).