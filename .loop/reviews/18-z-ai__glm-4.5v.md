# Review 18 - z-ai/glm-4.5v
_asked 2026-09-30T10:58:44 - served by z-ai/glm-4.5v - 111s_

## 1. Sequence steps overwrite the caller's `runName`
**Severity:** MAJOR
**Evidence:** PORT_STATUS.md — *"A sequence step does not clobber the caller's `runName"* — marked **"Not fixed yet"** and labelled *"Highest-value item outstanding."* The entry states `RunnableSequence::invoke` calls `stepConfig($config, $i)` which **overwrites** `runName`, while upstream's `patchConfig` puts the step tag into child callbacks and leaves `runName` alone.
**Why it matters:** Every named pipeline that passes through a `RunnableSequence` loses its name in traces, falling back to the component ID. A bound `StructuredOutput` pipeline still traces as `ChatOpenIO` after the bind-slot correction. Tracing semantics are broken for all sequences.
**Suggested fix:** Change `stepConfig()` to write the `seq:step:N` tag into `$config->callbacks` (or a metadata field) instead of `$config->runName`. Upstream: `langchain-core/src/runnables/base.ts:1982-1985`.

## 2. Algorithm dedup guard has no functional test coverage
**Severity:** MAJOR
**Evidence:** PORT_STATUS.md — *"Not mutation-verified: restoring the divergent form turns the suite red, but the only failing assertion is `DocsMatchRealityTest` noticing the file grew — `AlgorithmTest` exercises `hasSuccessfulWrite` in isolation and never reaches this branch."*
**Why it matters:** The guard in `prepareSingleTask()` that prevents re-executing a node within a superstep (by checking whether a task id was already issued) is dead code as far as the suite is concerned. The bug it fixed — divergent probe/id construction causing silent double-write on resume — could regress invisibly.
**Suggested fix:** Add a test that constructs a `StateGraph` with a channel consumed by a node that gets re-scheduled within a superstep, and assert `hasSuccessfulWrite()` fires. The branch must be reachable.

## 3. Interrupt resume-with-values path is unported
**Severity:** MAJOR
**Evidence:** PORT_STATUS.md langgraph-core table — `interrupt` status is 🟡 with note: *"`GraphInterrupt`/`NodeInterrupt`/`interrupt()` exist and are thrown by the loop; the resume-with-values path is unported"*. Also "Known non-exact behaviours": *"`interrupt()` reaches task config through a static restored in `finally`; JS uses `AsyncLocalStorage`."*
**Why it matters:** The human-in-the-loop pattern — LangGraph's primary differentiator — is half-implemented. A graph can throw `GraphInterrupt`, but a caller cannot resume it with approved values. This blocks every real agent use case requiring approval gates.
**Suggested fix:** Port the `Command(resume=value)` handling from upstream's `Pregel` loop. The static in `PregelScratchpad::withConfig()` already carries config into the node body; the resume path must accept a `Command` payload and inject it via the same mechanism.

## 4. StructuredOutput bound pipeline name never reaches `Run::name()`
**Severity:** MINOR
**Evidence:** PORT_STATUS.md — *"'Residual, unfixed:' even bound that way the name does not reach `Run::name()` — bound directly on the model, `name()` still returns the component id. The model does pass `$config->runName` (`BaseChatModel.php:303`, `:408`), so the break is between the merged binding config and the config the model receives."*
**Why it matters:** A pipeline created with `withStructuredOutput($schema, ['runName' => 'MyAgent'])` traces as `ChatOpenAI` (its serialized ID) instead of `MyAgent`. The loss is silent because `name()` falls back plausibly. Trace UIs show wrong names.
**Suggested fix:** Trace the config flow: `RunnableBinding::mergeConfig()` → `RunnableSequence::stepConfig()` → `BaseChatModel::invoke()`. The name is lost at one of these boundaries. The fix for Finding #1 (stopping `stepConfig` from clobbering `runName`) may resolve this too — verify after fixing #1.