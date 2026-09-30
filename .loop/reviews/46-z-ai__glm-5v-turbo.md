# Review 46 - z-ai/glm-5v-turbo
_asked 2026-09-30T17:23:49 - served by z-ai/glm-5v-turbo - 136s_

## 1. SqliteSaver::put() orphaned writes on checkpoint replacement
**Severity:** MINOR
**Evidence:** `SqliteSaver::put()` executes `'INSERT OR REPLACE INTO checkpoints'` with no corresponding `DELETE FROM writes WHERE thread_id=? AND checkpoint_ns=? AND checkpoint_id=?'`
**Why it matters:** A re-inserted checkpoint (same primary key) replaces the row but leaves its `writes` rows behind — orphaned data that accumulates on every retry or clock-skew UUID collision, never queried and never cleaned.
**Suggested fix:** Before the `INSERT OR REPLACE`, emit `DELETE FROM writes WHERE thread_id = ? AND checkpoint_ns = ? AND checkpoint_id = ?` using the same bound values.

## 2. `wireCheckpoint()` duplicated identically in both savers
**Severity:** MINOR
**Evidence:** `SqliteSaver::wireCheckpoint()` and `MemorySaver::wireCheckpoint()` contain the same ternary: `$checkpoint instanceof Checkpoint ? $checkpoint->toArray() : ['v' => $checkpoint->v, 'id' => $checkpoint->id, 'ts' => $checkpoint->ts, 'channel_values' => ..., 'channel_versions' => ..., 'versions_seen' => ...]`
**Why it matters:** A future field added to `PregelCheckpoint` must be wired in both places; missing one produces a silent serialisation gap where one saver drops data the other preserves.
**Suggested fix:** Move the method to `PregelMemorySaver` (the common parent) or to `BaseCheckpointSaver` as a `final static` helper, since it is pure function of its argument with no instance state.

## 3. MemorySaver::deleteThread() scans every write key via JSON parsing
**Severity:** MINOR
**Evidence:** `MemorySaver::deleteThread()` iterates `array_keys($this->writes)` and calls `json_decode($key, true)` on each one to test `$parsed[0] === $threadId`
**Why it matters:** Deleting one thread walks *all* writes for *all* threads, parsing each key as JSON — O(n) where n is the total write count across the entire process, not just the target thread.
**Suggested fix:** Store writes keyed first by thread_id (`$this->writes[$threadId][$namespaceAndCheckpoint][$innerKey]`) so deletion is `unset($this->writes[$threadId])` — O(1).

## 4. getDeltaChannelHistory() walks parent chain without depth bound
**Severity:** MINOR
**Evidence:** `BaseCheckpointSaver::getDeltaChannelHistory()` loops `while ($cursor !== null && $cursorId !== null && $remaining !== [])` with no counter or max-depth guard
**Why it matters:** A corrupted checkpoint chain with a circular `parentConfig` reference (or an accidentally self-referencing tuple) sends this into an infinite loop. The suite's green run proves only that current tests produce acyclic chains.
**Suggested fix:** Add `$depth = 0` before the loop and `if (++$depth > 1000) throw new \RuntimeException('Checkpoint parent chain exceeds maximum depth')` inside.

## 5. SqliteSaver::fromConnString() silently accepts empty string
**Severity:** MINOR
**Evidence:** `SqliteSaver::fromConnString()` computes `$dsn = str_starts_with($connString, 'sqlite:') ? $connString : 'sqlite:' . $connString` with no guard on empty input
**Why it matters:** An empty string becomes DSN `sqlite:` — which PDO resolves differently per platform (may open a temporary database, may throw, may create a file named `""`). The method's own docblock says "`:memory:` gives a private database" but does not mention the empty-string case, so a caller passing `''` gets undefined behaviour with no signal.
**Suggested fix:** Guard with `if ($connString === '') { throw new \InvalidArgumentException('Connection string must not be empty.'); }` before the prefix check.