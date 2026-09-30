# Review 15 - anthropic/claude-opus-5.5
_asked 2026-09-30T10:03:35 - served by anthropic/claude-opus-5.5 - 39s_

I verified four findings against the packet source. I've left out a fifth rather than pad.

## 1. Malformed-UTF-8 fallback silently deletes separators when `keepSeparator` is true
**Severity:** MAJOR
**Evidence:** `TextSplitter::splitOnSeparator()`: when `preg_split('/(?=…)/u', …)` returns `false`, the code falls back to `$splits = explode($separator, $text);`. `RecursiveCharacterTextSplitter` then merges with `$mergeSeparator = $this->keepSeparator ? '' : $separator;`.
**Why it matters:** `explode` consumes the separator, and the merge re-joins with `''`. With the default `keepSeparator=true`, text that is invalid UTF-8 loses every separator: `"a b c"` becomes `"abc"`. Nothing errors, so the chunks are silently corrupted. That is the opposite of the "keeps the splitter usable" promise in the comment.
**Suggested fix:** Fall back to a byte-level cut that keeps the separator attached. For example, split with `explode`, then prefix `$separator` to every piece after the first when `keepSeparator` is set. Add a test on `"a\xFF b c"` asserting the spaces survive.

## 2. Oversized-chunk notices accumulate in a process-global static forever
**Severity:** MINOR
**Evidence:** `private static array $oversizedChunkWarnings = [];` is appended to in `warnOversizedChunk()`. Nothing trims it; only the explicit `clearOversizedChunkWarnings()` resets it.
**Why it matters:** In a long-running worker (queue consumer, Octane, RoadRunner) every oversized chunk from every splitter instance is retained, so memory grows without bound. Warnings from unrelated splitters and requests are also mixed together. Tests that forget to clear the list can leak notices into later assertions.
**Suggested fix:** Make the store per-instance (`private array $oversizedChunkWarnings`), or cap it as a ring buffer. Optionally route it through the existing `LangChain\Utils\Notice` / a `psr/log` logger, which is closer to upstream's `console.warn`.

## 3. `warnOversizedChunk()` docblock still says it uses `trigger_error()`
**Severity:** MINOR
**Evidence:** The docblock says "Routed through `trigger_error()` so the condition is observable by anything reading the error log". The body only appends to `self::$oversizedChunkWarnings`, and its own comment explains why `trigger_error` was removed.
**Why it matters:** This is the docs-contradict-code pattern this project guards against. An editor "restoring" the documented behaviour reintroduces `E_USER_WARNING`, which `failOnWarning="true"` turns into a suite failure. Someone watching the error log will also never see the notice.
**Suggested fix:** Rewrite the docblock to say the notice is recorded and readable via `oversizedChunkWarnings()`, and is not emitted to the error log.

## 4. `batch()` carries two stacked docblocks, and the first is dead
**Severity:** MINOR
**Evidence:** In `RunnableInterface.php`, `/** Run this component over many inputs. … @return list<mixed> */` is immediately followed by `/** Run several inputs. … */` and then `public function batch(...)`. The ledger row for fix `0aa319e` asserts "there is exactly one", but the shipped source shows two.
**Why it matters:**
- `ReflectionMethod::getDocComment()` and most static analysers read only the last block, so the `@return list<mixed>` and the `$config` param doc are silently lost.
- The ledger makes a false claim about the tree, and it is exactly the kind of claim reviewers are told to trust.
**Suggested fix:** Merge both blocks into one, keeping `@return list<mixed>`. Correct the `0aa319e` ledger row. Add a guard test that no method in `src/` is preceded by two consecutive docblocks.

I did not find a fifth finding I could ground in the packet source, so I've stopped at four.