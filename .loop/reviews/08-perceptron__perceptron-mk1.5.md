# Review 8 - perceptron/perceptron-mk1.5
_asked 2026-09-29T09:31:28 - served by perceptron/perceptron-mk1.5 - 6s_

## 1. Missing `createReactAgent` and `ToolNode` implementation
**Severity:** BLOCKER
**Evidence:** PORT_STATUS.md shows `agents` subsystem as ⬜ (not started). The packet explicitly states "Do this first" as the recommended starting point.
**Why it matters:** Without these, users cannot build the most common agent pattern in LangChain. The entire dependency chain exists (BaseToolkit, ToolRuntime, provider clients), but the payoff feature is missing.
**Suggested port:** Port `createReactAgent` from `langgraph/prebuilt/createReactAgent.ts` and `ToolNode` from `langgraph/prebuilt/ToolNode.ts`, wiring them to the existing provider clients and tool runtime.

## 2. `HttpClient` named parameter binding vulnerability
**Severity:** MAJOR
**Evidence:** Packet lists "HttpClient call sites used named arguments" as a bug that was fixed. The architecture shows "HttpClient seam" as a critical component.
**Why it matters:** PHP named arguments bind to the implementing class's parameter name, causing "Unknown named parameter" errors when implementations use different parameter names. This breaks the HTTP abstraction layer.
**Suggested fix:** Use positional arguments only in `HttpClient` call sites, or standardize parameter names across all implementations.

## 3. `PregelLoop` generator error handling gap
**Severity:** MAJOR
**Evidence:** Packet mentions "No retry on stream establishment" and "try wrapped the generator call in both postStream() paths". The architecture shows "PregelLoop (Generator)" as a core component.
**Why it matters:** Streaming connect failures and non-2xx responses escape as bare `HttpException` with provider messages discarded. This is critical for the durable-execution engine.
**Suggested fix:** Add proper error handling in `PregelLoop` generator paths, implement retry logic for stream establishment failures.

## 4. `MessageMerge` streaming core vulnerability
**Severity:** MAJOR
**Evidence:** Packet states "The message merge algebra is the streaming core" and lists multiple bugs related to message handling. The architecture shows "Messages" as a core layer.
**Why it matters:** `MessageMerge` decides per-field whether to concatenate, sum, replace, recurse, or skip. Getting it wrong silently corrupts reconstructed streams.
**Suggested fix:** Add comprehensive tests for all merge operations, verify against upstream TypeScript behavior, ensure no silent data corruption.

## 5. `RunnableBinding` config precedence bug
**Severity:** MAJOR
**Evidence:** Packet documents "RunnableBinding merges bound kwargs into config->options" as a fixed bug. The architecture shows "RunnableBinding" in Core Abstractions.
**Why it matters:** Upstream passes bound kwargs last to a merge that lets later configs overwrite. This port stored them and never read them, making `bind()` inert.
**Suggested fix:** Ensure `RunnableBinding::mergeConfig()` properly merges bound kwargs into config->options with correct precedence matching upstream behavior.