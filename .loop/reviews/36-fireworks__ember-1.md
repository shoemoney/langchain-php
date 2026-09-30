# Review 36 - fireworks/ember-1
_asked 2026-09-30T14:59:37 - served by fireworks/ember-1 - 32s_

## 1. RunnableInterface::batch() really does have two stacked docblocks — the ledger row denying it is now false
**Severity:** MAJOR
**Evidence:** `src/LangChain/Runnables/RunnableInterface.php` — two consecutive `/** ... */` blocks precede `public function batch(` (the first ends `@return list<mixed>`, the second re-documents it). PORT_STATUS row `fix:0aa319e` states "there is exactly one" and calls both prior reports invented.
**Why it matters:** The ledger asserts a fix that the shipped source contradicts — the exact "ledger claims done, tree disagrees" defect class this project treats as most dangerous, and it tells the next reviewer the question is settled.
**Suggested fix:** Merge the two docblocks into one and correct the `0aa319e` row to record that the duplication was real and is now removed.

## 2. RunnableSequence::stream() docblock describes the old, fixed behaviour
**Severity:** MINOR
**Evidence:** `RunnableSequence::stream()` docblock: "Stream from the first step that can stream, then feed the rest eagerly… only the *first* step streams" — the body below it streams every step (`foreach ($steps as $i => $step) { … $step->stream(…)`).
**Why it matters:** Present-tense comment describing the pre-fix behaviour; this project's own history shows such comments get read as current state and generate false defect reports or wrong "fixes".
**Suggested fix:** Rewrite the docblock to state every step is streamed and the default-channel chunk is carried forward; move the history into the existing fenced HISTORY note.

## 3. RunnableSequence::stream() feeds a stale/null input to the next step when a step emits no default-channel chunk
**Severity:** MAJOR
**Evidence:** In `stream()`, `$lastOutput` is only updated `if ($channel === self::CHANNEL_DEFAULT)`, but `$stepInput = $lastOutput;` runs unconditionally. A step yielding only non-default chunks (e.g. `tool`) leaves `$stepInput` at the *previous* step's output (or `null` for step 1).
**Why it matters:** A mid-chain step that emits progress on a custom channel silently hands the wrong input downstream; `invoke()` would pass that step's real output, so `stream()` and `invoke()` disagree — the exact mismatch the trailing `$haveOutput` comment claims to guard.
**Suggested fix:** Track per-step output; if a streamed step produced no default chunk, fall back to `$step->invoke($stepInput, $stepConfig)` for the carried value (or pass `null` explicitly, matching upstream's `undefined`), and pin with a test using a custom-channel step.

## 4. The `$haveOutput` sentinel in RunnableSequence::stream() is dead
**Severity:** MINOR
**Evidence:** `$haveOutput` is set twice and read nowhere; the function ends with `unset($haveOutput);` while the comment above it describes an emit-decision ("Emitted on the 'did a value come out' question") that no code performs.
**Why it matters:** The comment promises a null-vs-absent guard that does not exist; a reader trusting it believes `stream()` handles a null-returning final step when nothing does. This is the comment-vs-code trap in reverse.
**Suggested fix:** Either implement the final emit the comment describes (yield `[default, null]` when the chain produced a null result) or delete the flag and the comment.

## 5. RunnableBranch::when()/default() mutate and return `$this`, so a "derived" branch corrupts the original
**Severity:** MINOR
**Evidence:** `when()` does `$this->branches[] = …; return $this;` and `default()` sets `$this->default; return $this;`. `$b2 = $b1->when($c, $r)` adds the condition to `$b1` too. (Inference about upstream: TS `RunnableBranch` is constructed with its full branch list and has no mutating builder.)
**Why it matters:** Reusing a branch as a base for two routes silently adds conditions to the shared instance; evaluation order is load-bearing, so the first-built route's behaviour changes when the second is defined.
**Suggested fix:** Clone in `when()`/`default()` (`$copy = clone $this; … return $copy;`), or document the mutation on both methods and pin it with a test.