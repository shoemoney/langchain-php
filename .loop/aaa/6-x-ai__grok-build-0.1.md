# Triple-A cycle 6 - x-ai/grok-build-0.1
_asked 2026-10-01T06:32:03 - 50s - max_tokens 24,000_

## 1. Bound runName placed in options instead of config
**Severity:** MINOR
**Where:** `src/LangChain/Runnables/RunnableBinding.php:105`
**Input → Output:** `$model->bind([], ['runName' => 'my-pipeline'])->invoke($input)` produces a Run whose name falls back to the component id (e.g. "ChatOpenAI") because the merged config carries runName only under options.
**Should be:** runName present in the config object passed to the model so `Run::name()` receives it.
**Why it outs the port:** Observability consumers (traces, LangSmith-style viewers) see only generic component names for every invocation of a bound pipeline, making it impossible to correlate a production trace with the specific named flow that produced it.

## 2. Sequence stepConfig overwrites caller runName before it reaches the model
**Severity:** MINOR
**Where:** `src/LangChain/Runnables/RunnableSequence.php` (stepConfig path that calls `forChild`)
**Input → Output:** A sequence containing a bound model with runName set yields step runs whose name is "seq:step:1" (or similar) rather than the name supplied at bind or invoke time.
**Should be:** the caller's runName is left intact on the config passed to the step; the seq:step tag belongs only in child callbacks or tags.
**Why it outs the port:** Any consumer that assembles a pipeline (the most common LCEL pattern) loses the ability to name the overall run; every step trace becomes anonymous, defeating the point of naming for debugging or cost attribution.

## 3. Empty map fields in engine-written checkpoints still reach storage as arrays on at least one code path
**Severity:** MAJOR
**Where:** `src/LangGraph/Checkpoint/Serde/JsonPlusEncoder.php:130`
**Input → Output:** A brand-new thread's first checkpoint (empty `channel_versions` / `versions_seen`) is written with `"channelVersions":[]` (and the same for versions_seen) in the raw JSON bytes.
**Should be:** `"channelVersions":{}` (and likewise for versions_seen) because every consumer of the checkpoint treats those two fields as maps.
**Why it outs the port:** A cross-runtime reader (or a resume after the first write) receives an array where an object is required; the checkpoint is either rejected or treated as "no prior state", so the first real run after a fresh thread cannot be resumed and history is lost. The integration test asserts the correct shape only for the non-Pregel Checkpoint class; the engine's path is the one that actually hits storage on first write.