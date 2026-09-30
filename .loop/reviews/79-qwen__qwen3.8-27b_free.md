# Review 79 - qwen/qwen3.8-27b:free
_asked 2026-09-30T02:00:33 - served by qwen/qwen3.8-27b:free - 413s_

## 1. `hasSuccessfulWrite` probe uses the wrong last path segment
**Severity:** MAJOR
**Evidence:** In `Algorithm::prepareSingleTask()`, the PULL dedup probe builds its UUID from `[ns, step, $name, PULL, $name]` but the actual task ID is built from `[ns, step, $name, PULL, $trigger]`. In a `StateGraph` the trigger is `"branch:to:<node>"` while `$name` is the bare node name, so the two UUIDs never match.
**Why it matters:** The guard "a task that already produced a non-error write this step is done" is dead code. If the version-comparison logic ever allows a re-schedule (e.g. after a resume or a channel that was consumed but not re-versioned), the node re-executes and its writes are applied twice.
**Suggested fix:** Change the probe's last element from `$name` to `$trigger` so it matches the ID the task will actually receive. The trigger is already in scope at that point (it is computed a few lines below); move the probe after the trigger is resolved, or restructure so both use the same value.

## 2. `getUpdates()` fatals on a scalar inside a Command-containing array
**Severity:** MINOR
**Evidence:** `StateGraph::getUpdates()` docblock states "Non-`Command` entries become whole-value updates," but the code path for a non-Command, non-array item falls through to `throw new InvalidUpdateError('Expected node … to return an object or an array containing at least one Command object, received ' . $typeOf)`. A node returning `[new Command(goto: 'next'), 'fallback']` hits the throw.
**Why it matters:** The documented fan-in contract is violated: a mixed array that upstream treats as "Commands for routing + scalars for state" is rejected instead of partially applied.
**Suggested fix:** In the `else` branch of the Command-array loop, if `$item` is not an array and not a Command, emit `[[self::ROOT, $item]]` (the whole-value write) rather than recursing into `getUpdates` which has no path for scalars.

## 3. `RunnableInterface::batch()` carries two docblocks
**Severity:** MINOR
**Evidence:** The source shows a short stub (`@param list<mixed> $inputs … @return list<mixed>`) immediately followed by the detailed docblock, both preceding `public function batch(array $inputs, …)`. The first is a leftover that omits the `$options` parameter and its non-use.
**Why it matters:** IDEs and static-analysis tools pick up the first docblock; a developer reading the stub sees no mention that `$options` is ignored, which is the exact class of "recorded but never read" surprise this project has hit repeatedly.
**Suggested fix:** Delete the first (short) docblock so only the detailed one remains.

## 4. `IO::mapCommand` resume comment names the wrong element
**Severity:** MINOR
**Evidence:** The docblock says the new value is appended "to the MOST RECENT resume that task already has," but the code is `array_slice($existing, 0, 1)` — it keeps the **first** (oldest) entry, not the most recent. The code matches upstream's `.slice(0, 1)`; the prose does not.
**Why it matters:** A future editor "fixing" the code to match the comment (taking the last element instead of the first) would introduce a real divergence from upstream's truncation semantics.
**Suggested fix:** Change the comment to "appending to the **first** queued resume that task already has" (or "the oldest"), matching what `array_slice($existing, 0, 1)` actually selects.

## 5. `StateGraph::compile()` gives `__start__` a default-guard `EphemeralValue`
**Severity:** MINOR
**Evidence:** `$channels[Constants::START] = new EphemeralValue();` uses the default `guard: true`, while every `branch:to:<node>` channel is explicitly `new EphemeralValue(guard: false)`. The start channel is written once by the loop to seed input, but a `Command` with `update: ['__start__' => …]` or a second `Send` targeting the start node would write to it a second time and fatal with a guard violation.
**Why it matters:** The failure mode is a hard exception mid-superstep with no diagnostic pointing at the offending `Command`; the user sees an opaque `EphemeralValue` guard error rather than "you cannot write to `__start__` after the run has begun."
**Suggested fix:** Either use `guard: false` (matching the branch channels, since `__start__` is a routing/trigger channel, not a state channel) or add a compile-time check that rejects any edge or `Command` whose destination is `__start__`, with a clear error message.