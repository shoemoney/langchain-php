# Review 35 - openai/gpt-chat-latest
_asked 2026-09-30T14:39:31 - served by openai/gpt-chat-latest - 14s_

## 1. Pending writes cannot safely carry binary values
**Severity:** BLOCKER
**Evidence:** `SqliteSaver::selectSql()` puts `CAST(pw.value AS TEXT)` inside `json_object(...)`, although `JsonPlusSerializer::dumpsTyped()` can return `['bytes', $data]`.
**Why it matters:** Arbitrary binary pending writes are forced through SQLite JSON/text, so checkpoint reads can fail or corrupt bytes.
**Suggested fix:** Fetch pending-write rows separately as BLOBs and pass their raw `value` directly to `loadsTyped()`.

## 2. SQLite aggregate does not guarantee pending-write order
**Severity:** MAJOR
**Evidence:** `json_group_array(json_object(...))` is aggregated in the correlated subquery, with `ORDER BY pw.task_id, pw.idx` applied outside the aggregate input.
**Why it matters:** Resume can replay writes in an order different from live execution, producing different reconstructed state.
**Suggested fix:** Feed `json_group_array` from an inner `SELECT ... FROM writes ... ORDER BY task_id, idx`, or fetch and order the rows directly.

## 3. Malformed namespaces bypass the shared validation in list()
**Severity:** MAJOR
**Evidence:** Both savers use `(string) $configurable['checkpoint_ns']` in `list()`, while `checkpointNamespace()` explicitly rejects non-strings instead of casting them.
**Why it matters:** An array namespace emits a warning under this project's PHPUnit policy and can query the literal `"Array"` namespace instead of the namespace other saver operations use.
**Suggested fix:** In both `list()` implementations, use `self::checkpointNamespace($configurable)` whenever `checkpoint_ns` is present.

## 4. Non-string thread IDs silently collide
**Severity:** MAJOR
**Evidence:** `BaseCheckpointSaver::stringOrNull()` accepts any scalar: `is_scalar($value) && (string) $value !== '' ? (string) $value : null`.
**Why it matters:** Distinct malformed IDs can address the same persisted thread, e.g. integer `1`, float `1.0`, boolean `true`, and string `"1"`.
**Suggested fix:** Accept only non-empty strings for `thread_id`, matching the method's own contract; reject invalid IDs on writes and treat them as unaddressable on reads.

## 5. Duplicate batch indexes silently discard regular writes
**Severity:** MAJOR
**Evidence:** `MemorySaver::putWrites()` skips existing `$taskId . ',' . $index`; SQLite uses `INSERT OR IGNORE` with primary key `(thread_id, checkpoint_ns, checkpoint_id, task_id, idx)`.
**Why it matters:** If one task calls `putWrites()` again with a changed regular value at the same position, both savers retain the old value with no error, potentially replaying stale output.
**Suggested fix:** Verify this against upstream retry semantics; if changed writes are invalid, detect the conflicting payload and throw rather than silently retaining stale data.