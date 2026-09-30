# Review 50 - ~openai/gpt-astra-latest
_asked 2026-09-30T17:59:37 - served by openai/gpt-6-astra - 28s_

## 1. Root-state graphs lack whole-value write-back
**Severity:** MAJOR
**Evidence:** `StateGraph.php` selects `self::ROOT` as output, but `getUpdates()` returns early for `[]`, accepts associative maps, and otherwise throws `InvalidUpdateError`.
**Why it matters:** A graph declaring `__root__` cannot accept ordinary scalar or list input; an empty-list update is silently discarded.
**Suggested fix:** Implement the upstream `_getRoot` write path when the sole channel is `__root__`; test scalar, non-empty-list, and empty-list input and node returns through a compiled graph.

## 2. Conditional-edge path maps are discarded
**Severity:** MAJOR
**Evidence:** `StateGraph::addConditionalEdges()` uses only `array_values($pathMap)[0]` for `$branchName`, then stores `$this->branches[$start][$branchName] = $path`.
**Why it matters:** A mapping such as `['approved' => 'publish']` never reaches execution; the branch cannot translate its returned label into the registered destination.
**Suggested fix:** Preserve the destination mapping with the branch and apply it when resolving path results, following upstream `langgraph-core/src/graph/graph.ts`; test label-to-node and label-to-END routing.

## 3. PULL tasks acknowledge only one advanced trigger
**Severity:** MAJOR
**Evidence:** `Algorithm::prepareSingleTask()` breaks after the first advanced channel and sets `triggers: [$trigger]`; `applyWrites()` updates `versionsSeen` only for those triggers.
**Why it matters:** With two advanced, persistent trigger channels, one remains unseen after execution. A subsequent full scheduling scan can run the node again without any new channel update.
**Suggested fix:** Collect all advanced, available triggers and carry them into the task and metadata. Add a two-`LastValue`-trigger regression asserting that a second scheduling scan produces no task after applying the first task’s writes.