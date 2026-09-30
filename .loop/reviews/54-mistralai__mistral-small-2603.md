# Review 54 - mistralai/mistral-small-2603
_asked 2026-09-29T12:51:03 - served by mistralai/mistral-small-2603 - 82s_

## 1. MemorySaver and SqliteSaver store invalid namespace strings
**Severity:** MAJOR
**Evidence:** MemorySaver.php:240, SqliteSaver.php:260
**Why it matters:** When `checkpoint_ns` is not a string (e.g., an array), both savers cast it to the literal string `"Array"`, which can collide with a legitimate namespace and cause checkpoints to be stored in the wrong place or overwritten, leading to data loss on resume.
**Suggested fix:** Replace direct casting with `self::stringOrNull($configurable['checkpoint_ns'] ?? null) ?? ''` in both savers, ensuring only valid string namespaces are used.

## 2. SqliteSaver::putWrites() replaces entire task writes when any channel is special
**Severity:** MAJOR
**Evidence:** SqliteSaver.php:340
**Why it matters:** If a task writes to both a regular channel and a special channel (e.g., error), the `OR REPLACE` clause replaces all writes for that task, not just the special-channel writes. This causes loss of regular-channel writes from previous attempts, corrupting the checkpoint state and leading to incorrect state on resume.
**Suggested fix:** Split writes into special and regular, and use `OR REPLACE` only for special-channel writes. Issue two INSERT statements: one with `OR REPLACE` for special channels and one with `OR IGNORE` for regular channels.

## 3. BaseCheckpointSaver::getDeltaChannelHistory() ignores empty channel list edge case
**Severity:** MINOR
**Evidence:** BaseCheckpointSaver.php:240
**Why it matters:** The method returns early for empty `$channels`, but if `$channels` is `null` or malformed, it throws a type error. The engine should never pass invalid input, but defensive code prevents future breakage.
**Suggested fix:** Add `if (!is_array($channels)) { return []; }` before the empty check to harden against invalid input.

## 4. SqliteSaver metadata filter uses fragile string comparison for JSON values
**Severity:** MINOR
**Evidence:** SqliteSaver.php:200-207
**Why it matters:** The filter compares JSON-encoded values as strings, so numeric `1` and string `"1"` are treated as equal. This can cause metadata filters to match unintended checkpoints or miss intended ones when types differ, leading to incorrect filtering.
**Suggested fix:** Use SQLite's `json_type()` to compare types explicitly in the WHERE clause, e.g., `json_type(json_extract(metadata, '$.key')) = json_type(?) AND json_extract(metadata, '$.key') = ?`.

## 5. MemorySaver::list() does not short-circuit on empty namespace list
**Severity:** MINOR
**Evidence:** MemorySaver.php:180-182
**Why it matters:** When `$namespaces` is explicitly empty (e.g., `['']`), the loop still iterates over it, performing unnecessary work. While not incorrect, it's inefficient and could mask configuration errors in long-running processes.
**Suggested fix:** Add `if ($threadNamespaces === []) { continue; }` immediately after determining `$threadNamespaces` to skip empty namespace lists early.