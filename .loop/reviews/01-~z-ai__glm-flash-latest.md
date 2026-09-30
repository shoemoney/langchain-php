# Review 1 - ~z-ai/glm-flash-latest
_asked 2026-09-30T07:21:34 - served by z-ai/glm-5.3-flash - 170s_

## 1. `addConditionalEdges()` rejects START, but `compile()` was written to support it
**Severity:** MAJOR
**Evidence:** `StateGraph::addConditionalEdges()` opens with `if (!isset($this->nodes[$start])) { throw new \InvalidArgumentException('Node `' . $start . '` not found'); }` — START is never in `$this->nodes`. Yet `compile()`'s branch loop contains `if ($start === Constants::START) { $startNode->writers[] = $writer; }`, and `addEdge()` explicitly permits START (`if ($start !== Constants::START && !isset(...))`).
**Why it matters:** Conditional routing from the entry point (the standard fan-out-from-START pattern) is unreachable, and the compile-side support is dead code — the two halves of the same feature disagree.
**Suggested fix:** Relax the guard to `if ($start !== Constants::START && !isset($this->nodes[$start]))`, matching `addEdge()`, and add a test routing from START through a conditional path.

## 2. `mapOutputUpdates()` excludes ERROR/INTERRUPT only when they are the *first* write
**Severity:** MAJOR
**Evidence:** `IO::mapOutputUpdates()`: `if (($writes[0][0] ?? null) === Constants::ERROR) { continue; }` and the identical check for `INTERRUPT` — only index 0 is inspected.
**Why it matters:** A task that writes state and then records an error (or writes state after an interrupt marker) is reported in the `updates` stream as a normal state update; a failed/paused task is presented as having produced state. *(Inference on upstream: I believe upstream scans all writes, not just the first — verify against `io.ts` before acting.)*
**Suggested fix:** Skip the task if *any* write targets ERROR or INTERRUPT (`in_array(Constants::ERROR, array_column($writes, 0), true)`), or confirm upstream's first-write rule and pin it with a test.

## 3. `compile()` docblock states a writer order the code does not follow
**Severity:** MINOR
**Evidence:** The docblock: "1. **write-back** … 2. **the hidden `SELF` branch** … 3. **declared edges**". The code appends in the opposite order: edge writers are appended in the `foreach ($this->edges …)` loop, branch writers after, and the SELF branch last ("Appended last so a node's own declared routing has already fired").
**Why it matters:** This file's own comments are the spec a future editor ports from; the numbered contract contradicts both the code and the SELF-branch comment three paragraphs down, inviting a "correcting" edit that reorders writers.
**Suggested fix:** Rewrite the docblock list to write-back → declared edges → conditional branches → SELF, matching the append order.

## 4. `Algorithm::localRead()` accepts a `$config` it never reads
**Severity:** MINOR
**Evidence:** Signature ends `?RunnableConfig $config = null`; no reference to `$config` in the body. The only caller, `$readFn` in `buildTaskConfig()`, doesn't pass it either.
**Why it matters:** On the correctness core, a dead parameter that mirrors upstream's signature suggests config-sensitive behaviour that does not exist; a future caller passing it gets silence, not an error.
**Suggested fix:** Either remove the parameter or, if upstream's `_localRead` uses config for something (e.g. subgraph reads), implement that and say so in the docblock.

## 5. Two stacked docblocks sit above `RunnableInterface::batch()`
**Severity:** MINOR
**Evidence:** The packet's `RunnableInterface.php` shows a first `/** … @param list<mixed> $inputs … @param RunnableConfig|null $config … @return list<mixed> */` immediately followed by a second `/** Run several inputs. … */` on the same method. PHP binds only the last docblock. (Note: PORT_STATUS.md's `fix:0aa319e` row claims "there is exactly one" — if the packet is verbatim, that row is now stale.)
**Why it matters:** The first block's `@param RunnableConfig|null $config` is dead, so the generated docs/API dump lose the `$config` description; and the ledger's "exactly one" claim contradicts the shipped file, which is the docs-vs-reality drift this project guards against.
**Suggested fix:** Merge the two blocks into one (keep the second's explanatory text, restore the `$config` param line), and let `DocsMatchRealityTest` cover the ledger row.