# Review 93 - ~anthropic/claude-sonnet-latest
_asked 2026-09-30T04:14:04 - served by anthropic/claude-sonnet-5.5 - 33s_

## 1. `RunnableSequence::stream()` mislabels every step's `seq:step:N`
**Severity:** MAJOR
**Evidence:** `$first = array_shift($steps);` followed by `foreach ($steps as $i => $step) { ... $this->stepConfig($config, $i)`. The first step streams with the raw `$config`.
**Why it matters:** `array_shift` renumbers the keys, so the second step gets `seq:step:1`, the third `seq:step:2`, and so on. The first step gets no tag at all. Streamed traces are off by one, unlike `invoke()`, which is correct.
**Suggested fix:** Use `foreach ($steps as $i => $step) { ... stepConfig($config, $i + 1) }` and pass `stepConfig($config, 0)` to `$first->stream()`. Add a test that compares the `runName`s seen by each step in `stream()` against `invoke()`.

## 2. `RunnableSequence::stream()` emits the first step's chunks and then a duplicate "final" value
**Severity:** MAJOR
**Evidence:** `yield $pair;` inside the first-step loop, then unconditionally `yield [self::CHANNEL_DEFAULT, $lastOutput];`.
**Why it matters:** A one-step sequence yields its last chunk twice. A multi-step sequence yields the first step's raw chunks and then the last step's output as if both were the sequence's output. A consumer that concatenates `default` chunks gets corrupted text. Downstream steps also receive only the last chunk of the first step (`$lastOutput = $chunk`), not the folded output.
**Suggested fix:** Yield the first step's chunks only when it is the last step. Otherwise fold the chunks (`MessageMerge` or `concat`) before invoking the remaining steps, and yield only the final step's result. The `$anyOutput` fallback also calls `$first->invoke()` a second time, which re-runs the step's side effects. That is worth checking too.

## 3. `Runnable::pipeTo()` never uses `$this`
**Severity:** MAJOR
**Evidence:** `return new RunnableLambda(fn (mixed $input): mixed => $input, ['func' => $fn]);` The body never references `$this`, although the docblock says "Feed this runnable's output into a callback".
**Why it matters:** The returned runnable is detached from the receiver, so the receiver is silently never run. This is the same shape as the removed `Runnable::map()`. I could not see what `RunnableLambda` does with the `func` option, so that part is inference. The method has no upstream counterpart, which the project rules forbid.
**Suggested fix:** Delete `pipeTo`. If a shorthand is wanted, use `$this->pipe(new RunnableLambda($fn))`. Add a test that the receiver actually runs.

## 4. `RunnableBranch` drops config and is more lenient than upstream
**Severity:** MINOR
**Evidence:** `if ($condition($input))` (no config passed). The constructor takes `?RunnableInterface $default = null`, and `invoke` ends with `throw new \RuntimeException('No branch matched and no default branch was set ...')`.
**Why it matters:** Conditions never see the call config, so per-call options and callbacks are lost. From memory of upstream (inference, not in the packet), the default branch is mandatory at construction and conditions are invoked with the config. Here a missing default fails only at run time, and PORT_STATUS.md does not list this as a divergence.
**Suggested fix:** Pass `$config` to conditions that accept it, as `RunnableLambda` does via reflection. Either require a default at construction or record the divergence in PORT_STATUS.md.

## 5. Docs and config disagree with the code
**Severity:** MINOR
**Evidence:** `RunnableInterface` has two stacked docblocks directly above `batch()`, the first "Run this component over many inputs" with `@return list<mixed>`, and the second also carries the `\\Throwable` double-backslash typo. PORT_STATUS.md says "there is exactly one". In `composer.json`, `autoload-dev` maps `"LangGraph\\Tests\\": "tests/Unit/LangGraph/"`, but the test layout has no `tests/Unit/LangGraph` directory.
**Why it matters:** The first docblock is orphaned, so tooling reads only the second, which has no `@return`. The ledger's denial of the duplicate is contradicted by the packet's own source. The dead autoload path would silently fail to load any test class placed there.
**Suggested fix:** Merge the two docblocks into one with `@return list<mixed>`, and fix `\\Throwable`. Remove or correct the `LangGraph\Tests` mapping, and correct the ledger sentence.