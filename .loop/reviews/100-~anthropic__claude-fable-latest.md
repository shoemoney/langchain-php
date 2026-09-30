# Review 100 - ~anthropic/claude-fable-latest
_asked 2026-09-30T06:03:15 - served by anthropic/claude-fable-5.1 - 61s_

## 1. `RunnableSequence::stream()` forwards only the LAST chunk of the first step downstream
**Severity:** BLOCKER
**Evidence:** `RunnableSequence::stream()`: `if ($channel === self::CHANNEL_DEFAULT) { $lastOutput = $chunk; }` then `$step->invoke($lastOutput, …)` for every remaining step.
**Why it matters:** `$model->pipe($parser)->stream($x)` hands the parser the final token chunk, not the folded message. The parser returns a result built from one token; nothing throws, the answer is simply wrong. `invoke()` on the same chain is correct, so the two modes disagree.
**Suggested fix:** Fold chunks as upstream does (`concat`/`MessageMerge` for chunk objects, string concatenation for strings, replace otherwise) before invoking step 2 — or better, implement stream via chained `transform()` (finding 2), which removes the fold entirely.

## 2. "Only the first step streams" is a divergence from upstream presented as fidelity
**Severity:** MAJOR
**Evidence:** `RunnableSequence::stream()` docblock: "This mirrors the TS behaviour: only the *first* step streams". Upstream `RunnableSequence._streamIterator` (`runnables/base.ts`) does `steps[0].transform(...)` then `step.transform(prevGenerator, config)` for each subsequent step.
**Why it matters:** `prompt | model | parser` — the canonical LCEL chain — cannot stream tokens in this port: the prompt yields one `PromptValue`, then the model is invoked eagerly. Not recorded in the "Known non-exact behaviours" table, so the docblock is a false claim.
**Suggested fix:** Build the generator by threading `transform()` (which this port already has on every runnable) through steps 1..n; or, if kept, delete the "mirrors the TS" sentence and add a ledger row.

## 3. `RunnableSequence::from()` emits "Undefined array key 0" on a two-key map step
**Severity:** MAJOR
**Evidence:** `if (is_array($step) && count($step) === 2 && is_string($step[0]))` in `RunnableSequence::from()`.
**Why it matters:** `from([['context' => $retriever, 'question' => $pass], $prompt, $model])` — the canonical RAG map has exactly two keys and no key `0`, so `$step[0]` raises a PHP Warning before falling through. Under `failOnWarning="true"` any test using this shape fails; in production it logs a spurious warning per chain build. Behaviour is otherwise correct, which is why it is unlikely to be pinned.
**Suggested fix:** `is_array($step) && array_is_list($step) && count($step) === 2 && is_string($step[0])`, and add a test building a sequence from a two-key map.

## 4. `stream()` labels steps differently from `invoke()`
**Severity:** MINOR
**Evidence:** `stream()`: `$first = array_shift($steps);` … `$first->stream($input, $config)` … `foreach ($steps as $i => $step) { … $this->stepConfig($config, $i) }`. `invoke()` uses `stepConfig($config, $i)` over the un-shifted list.
**Why it matters:** `array_shift` reindexes, so the second step runs as `seq:step:1` and the first step gets no step config at all. Trace attribution of the same chain differs between the two modes — the first step's config (and any `forChild` callbacks) is skipped in stream.
**Suggested fix:** Pass `$this->stepConfig($config, 0)` to `$first->stream()` and use `$i + 1` (or iterate `$this->steps` from index 1 without shifting).

## 5. `RunnableBranch` conditions never receive the config
**Severity:** MINOR
**Evidence:** `RunnableBranch::invoke()`: `if ($condition($input)) {`. Upstream `RunnableBranch._invoke` calls `condition.invoke(input, patchConfig(config, …))`.
**Why it matters:** A routing condition that reads `configurable` (e.g. route by tenant or by a bound option) cannot in this port; the same class of dropped-config defect the ledger already fixed for `RunnableLambda::invoke()`. `RunnableBranch` also has no `stream()` override, so a branch whose chosen runnable streams is collapsed to one chunk.
**Suggested fix:** Apply the same reflection rule as `RunnableLambda` (pass `$config` when arity ≥ 2 or variadic), and add `stream()` that yields from the selected branch's `stream()`.