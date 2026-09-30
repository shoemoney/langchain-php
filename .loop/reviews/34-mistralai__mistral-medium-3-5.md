# Review 34 - mistralai/mistral-medium-3-5
_asked 2026-09-30T14:30:17 - served by mistralai/mistral-medium-3-5 - 131s_

## 1. `mapOutputValues` fails on `false` pending writes
**Severity:** MAJOR
**Evidence:** `IO.php:mapOutputValues` with `$pendingWrites === false` causes `foreach` to fail on non-array
**Why it matters:** PHP warning under `failOnWarning="true"` breaks the test suite
**Suggested fix:** Treat `false` as empty array: `if ($pendingWrites === true) { ... } elseif (is_array($pendingWrites)) { ... }`

---

## 2. `localWrite` accepts null for PUSH/TASKS channels
**Severity:** MAJOR
**Evidence:** `Algorithm.php:localWrite` skips validation when `$value === null` for `PUSH`/`TASKS`
**Why it matters:** Invalid null `Send` packets silently accepted, corrupting task scheduling
**Suggested fix:** Explicitly reject null: `if ($channel === Constants::PUSH || $channel === Constants::TASKS) { if ($value === null) throw new InvalidUpdateError(...); ... }`

---
## 3. `shouldInterrupt` misuses channel name as node name
**Severity:** BLOCKER
**Evidence:** `Algorithm.php:shouldInterrupt` uses `$checkpoint->versionsSeen[Constants::INTERRUPT]` but `INTERRUPT` is a channel, not a node
**Why it matters:** Reads wrong checkpoint data; may fail to interrupt when required, causing silent state corruption
**Suggested fix:** Check versions seen by actual task nodes for the interrupt channel (e.g., iterate `$tasks` and inspect their `versionsSeen`)

---
## 4. `prepareSingleTask` uses unsafe `json_encode` for task ID
**Severity:** MAJOR
**Evidence:** `Algorithm.php:prepareSingleTask` calls `json_encode` without `JSON_THROW_ON_ERROR` on namespace/step/name
**Why it matters:** Malformed UTF-8 produces empty string via `(string) false`, causing task ID collision
**Suggested fix:** Add `JSON_THROW_ON_ERROR` flag to `json_encode`

---
## 5. `addEdge` allows edge from invalid start node to END
**Severity:** MAJOR
**Evidence:** `StateGraph.php:addEdge` for `$end === Constants::END` does not validate `$start` node existence before `$this->nodes[$start]->writers[]`
**Why it matters:** Undefined index notice or fatal error when `$start` is not a registered node
**Suggested fix:** Add `if ($start !== Constants::START && !isset($this->nodes[$start])) { throw ... }` before accessing `$this->nodes[$start]`