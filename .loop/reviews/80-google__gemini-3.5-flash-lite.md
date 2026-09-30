# Review 80 - google/gemini-3.5-flash-lite
_asked 2026-09-30T02:15:58 - served by google/gemini-3.5-flash-lite - 28s_

## 1. SqliteSaver metadata filter breaks on non-string values
**Severity:** MAJOR
**Evidence:** `src/LangGraph/Checkpoint/SqliteSaver.php`: `'json_quote(json_extract(CAST(metadata AS TEXT), ?)) = ?'`
**Why it matters:** `json_quote()` wraps scalar extractions in JSON string quotes (`'"1"'`), causing numeric or boolean metadata filters to fail SQLite matching against `json_encode()` payloads.
**Suggested fix:** Remove `json_quote()` so extracted values compare directly with the bound parameter values.

## 2. MemorySaver deleteThread does an O(N) linear scan over all writes
**Severity:** MINOR
**Evidence:** `src/LangGraph/Checkpoint/MemorySaver.php`: `foreach (array_keys($this->writes) as $key) { $parsed = json_decode($key, true); ... }`
**Why it matters:** Deleting a thread decodes every stored write key in memory, degrading performance linearly as total thread and write counts grow.
**Suggested fix:** Maintain a secondary index mapping `thread_id` to its write keys so deletion can target them directly without decoding JSON keys.

## 3. BaseCheckpointSaver checkpointNamespace requires manual unwrapping
**Severity:** MINOR
**Evidence:** `src/LangGraph/Checkpoint/BaseCheckpointSaver.php`: `$ns = $config['checkpoint_ns'] ?? null;`
**Why it matters:** Passing a standard nested config array without pre-unwrapping via `configurable()` silently yields an empty namespace, causing checkpoints to be misfiled.
**Suggested fix:** Automatically apply `static::configurable($config)` at the start of `checkpointNamespace()`.