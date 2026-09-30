# Review 97 - ~openai/gpt-sol-latest
_asked 2026-09-30T04:57:13 - served by openai/gpt-6.1-sol - 22s_

## 1. Root-state graphs reject whole-value updates
**Severity:** MAJOR
**Evidence:** `StateGraph.php` selects `self::ROOT` as output, but `getUpdates()` accepts only commands and associative arrays; scalars reach `throw new InvalidUpdateError(...)`.
**Why it matters:** A graph with a single `__root__` channel cannot accept scalar input or scalar node results; list-valued results also fail.
**Suggested fix:** Implement upstream `_getRoot` handling before record-shaped validation: write whole values to `__root__`, preserving Command updates and parent routing; test scalar and list round trips.

## 2. Conditional route maps are discarded
**Severity:** MAJOR
**Evidence:** `StateGraph::addConditionalEdges()` uses only `array_values($pathMap)[0]` as `$branchName` and stores only `$path`.
**Why it matters:** A route returning a label cannot resolve that label through the supplied map. Calls without a map also share `Constants::SELF`, silently replacing earlier conditional branches.
**Suggested fix:** Store each branch’s runnable and destination map; resolve returned labels through that map. Derive distinct runnable branch names and reject duplicate names rather than overwriting.

## 3. Conditional entry routing is unreachable
**Severity:** MAJOR
**Evidence:** `addConditionalEdges()` requires `isset($this->nodes[$start])`, while `compile()` explicitly handles conditional branches where `$start === Constants::START`.
**Why it matters:** Adding a conditional edge from START always throws, so a graph cannot choose its initial node from its input through this API.
**Suggested fix:** Allow `Constants::START` in the source validation, as `addEdge()` does; test input-dependent entry routing to two different nodes.

## 4. Fresh reads discard available null values
**Severity:** MAJOR
**Evidence:** `Algorithm::channelValuesFor()` saves a channel checkpoint only under `if ($value !== null)`; `localRead(..., fresh: true)` uses that snapshot to reconstruct channels.
**Why it matters:** Inference: an available null-valued channel becomes absent in the throwaway copy, so a reducer can receive different prior state during fresh reads than during committed writes.
**Suggested fix:** Retain null checkpoints and distinguish absence solely through `EmptyChannelError`; test a null-valued reducer whose update distinguishes null from an absent initial value.

## 5. Failed cache-key encoding produces collisions
**Severity:** MINOR
**Evidence:** `Algorithm::buildCacheKey()` uses `(string) json_encode($input)` and hashes that string without checking encoding failure.
**Why it matters:** Different inputs containing invalid UTF-8, recursion, or unsupported values all yield the hash of `''`. Inference: a cache consuming these keys can reuse unrelated task writes.
**Suggested fix:** Use `JSON_THROW_ON_ERROR` and surface a contextual cache-key error, or use the upstream-compatible deterministic serializer; test that two encoding failures cannot silently produce the same key.