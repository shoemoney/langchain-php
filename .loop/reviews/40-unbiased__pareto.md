# Review 40 - unbiased/pareto
_asked 2026-09-29T12:12:30 - served by unbiased/pareto - 78s_

I'll review the packet carefully, checking each candidate finding against the known-defect tables before reporting it.# Advisory review — langchain-php (composition & config)

Checked every candidate against HANDOFF.md's bug table and PORT_STATUS.md's "Known non-exact behaviours" before reporting. Five findings:

## 1. `RunnableSequence::stream()` drops a legitimate `null` final output
**Severity:** MAJOR
**Evidence:** `src/LangChain/Runnables/RunnableSequence.php` — `if ($lastOutput !== null) { yield [self::CHANNEL_DEFAULT, $lastOutput]; }`
**Why it matters:** A chain whose final step legitimately returns `null` (or whose streamed chunks carry `null` on `default`) yields nothing, so a consumer cannot distinguish "empty result" from "no result emitted". Also, if the first step streams but a downstream step returns `null`, the sequence yields the intermediate chunks but never a final value — inconsistent with `invoke()`, which returns `null` faithfully.
**Suggested fix:** Track `$anyOutput` through the downstream steps too (or use a sentinel: `$lastOutput = new stdClass()` initialiser / `$hasOutput = false` flag) and yield whenever a final value was produced, regardless of it being `null`.

## 2. `RunnableSequence::stream()` ignores `$names` — trace labels are dead data
**Severity:** MINOR
**Evidence:** `RunnableSequence::__construct()` stores `$this->names`; neither `invoke()`, `stream()`, nor `getName()` ever reads it. The docblock claims "step names double as trace labels, so a chain reports as `RunnableSequence > prompt > model`".
**Why it matters:** The documented behaviour is false — same class of defect as the `ValueSet::key()` docblock bug in your history (documented guarantee, unwired code). `RunnableSequence::from(['label', $r])` silently discards the label at runtime.
**Suggested fix:** Either wire names into the config passed to each step (`runName`) in `invoke()`/`stream()`, or drop the `$names` property and the docblock claim. Inference: I cannot see the tracer code in this packet, so verify nothing else reads `$names` first.

## 3. `Runnable::pipeTo()` wraps the callable in an identity lambda — config and streaming semantics diverge from upstream
**Severity:** MAJOR
**Evidence:** `src/LangChain/Runnables/Runnable.php` — `pipeTo()` constructs `new RunnableLambda(fn ($input) => $input, ['func' => $fn])`.
**Why it matters:** The callable is passed as an *option* to an identity lambda rather than as the lambda's function. Whether `RunnableLambda` reads `['func' => …]` from options is not shown in the packet; if it only honours its constructor's function argument, `pipeTo()` produces a runnable that returns its input unchanged and never calls `$fn` — a silent no-op on every `pipeTo()` call. (Inference: `RunnableLambda` source is not in the packet; verify its options handling.)
**Suggested fix:** If `RunnableLambda` supports an options-injected func, add a named test proving `pipeTo` actually calls `$fn`. If it does not, construct it with the callable in the function position.

## 4. `RunnableBranch::when()` accepts `mixed` but `invoke()` calls `->invoke()` on it unconditionally
**Severity:** MINOR
**Evidence:** `RunnableBranch::when(callable $condition, mixed $runnable)` → `coerceToRunnable($runnable)`; but the constructor's `@param` declares `list<array{0: callable, 1: RunnableInterface}>` and `__construct` stores branches **without** running `coerceToRunnable` on them.
**Why it matters:** A caller using the constructor directly with a callable branch (the documented `RunnableBranch::branch(...)` path goes through `when()`, but the public constructor is also callable) stores a raw callable; `invoke()` then calls `->invoke()` on it and fatals with "Call to a member function on Closure". The two construction paths have different validation.
**Suggested fix:** Normalise in the constructor (`$this->branches = array_map(fn([$c, $r]) => [$c, coerceToRunnable($r)], $branches)`) so both paths are safe.

## 5. `RunnableBinding::mergeConfig()` — bound-kwargs-beats-call-time contradicts the stated upstream merge for `options`
**Severity:** MAJOR
**Evidence:** `RunnableBinding.php` — `$merged->options = $this->kwargs + ($merged->options + $bound->options);` with the comment claiming upstream passes kwargs last so they win.
**Why it matters:** This is documented as pinned behaviour, so I flag it as a **verification request, not a defect**: upstream `_mergeConfig` is `{...options, ...this.kwargs}` only for the *config* merge; but `RunnableBinding._mergeConfig(options, kwargs)` in `@langchain/core` merges kwargs into `config.configurable`, not `config.options`. If that is right, bound kwargs landing in `options` (and beating call-time options) is a port-introduced divergence not listed in the known-divergences table.
**Suggested fix:** Diff against upstream `RunnableBinding._mergeConfig` in `langchainjs` (`agentdesk/.runtime/js-sdks/langchainjs`); if kwargs belong in `configurable`, move them and add the divergence to PORT_STATUS.md; if `options` is correct, add a test comment citing the upstream line so the next reviewer does not re-litigate it.

---

**Not reported, deliberately:** the sequential-superstep divergence, UTF-16 splitters, `batch()` ignoring `$options`, `RunnableLambda`'s config-less signature, and the empty-map-as-`[]` serde behaviour are all in the known-divergences tables. The `RunnableParallel` scalar-input and `bind()` kwargs-merge items are also already documented as fixed.