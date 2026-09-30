# Review 27 - ~openai/gpt-sol-latest
_asked 2026-09-30T12:45:51 - served by openai/gpt-6.1-sol - 16s_

## 1. SQLite history ignores the requested checkpoint ID
**Severity:** MAJOR
**Evidence:** `MemorySaver::list()` checks `$onlyCheckpointId`; `SqliteSaver::list()` never adds a predicate for `$configurable['checkpoint_id']`.
**Why it matters:** The same addressed history query returns one checkpoint in memory but unrelated checkpoints in SQLite.
**Suggested fix:** Add `checkpoint_id = ?` when that config key supplies an ID; test both savers with two checkpoints and a query naming just one.

## 2. SQLite metadata filters lose JSON type distinctions
**Severity:** MAJOR
**Evidence:** `SqliteSaver::list()` uses `json_extract(CAST(metadata AS TEXT), ?) = json_extract(?, ?)`.
**Why it matters:** SQLite extracts `true` as integer `1`, so those distinct values match; JSON null extracts as SQL NULL, so even a stored null cannot match a null filter.
**Suggested fix:** Compare extracted JSON types as well as values, using null-safe comparison; test `true` versus `1`, `false` versus `0`, and present-null versus missing.

## 3. Binary pending writes pass through a text-only projection
**Severity:** MAJOR
**Evidence:** `SqliteSaver::selectSql()` embeds `'value', CAST(pw.value AS TEXT)` in `json_object`; `JsonPlusSerializer::dumpsTyped()` explicitly stores invalid UTF-8 strings as `'bytes'`.
**Why it matters:** Arbitrary binary payloads are forced into JSON text before decoding, which can fail UTF-8 JSON decoding instead of returning the saved bytes.
**Suggested fix:** Fetch pending writes separately as raw payloads, preserving their tags; round-trip a write containing `"\xFF\x00"` through SQLite `getTuple()` and `list()`.

## 4. Metadata decoding discards the serializer’s type tag
**Severity:** MAJOR
**Evidence:** `SqliteSaver::put()` requires `$type === $metadataType` and stores `type`, but `rowToTuple()` calls `$this->serde->loadsTyped('json', (string) $row['metadata'])`.
**Why it matters:** An injected serializer using another tag can successfully save both records, then fail or misdecode metadata on retrieval.
**Suggested fix:** Decode SQLite metadata with the stored `$type`; have MemorySaver retain its metadata tag independently. Test an injected serializer whose map encoding uses a non-`json` tag.

## 5. Engine checkpoints bypass empty-map normalization
**Severity:** MAJOR
**Evidence:** Both savers’ `wireCheckpoint()` fallbacks emit `'channel_versions' => $checkpoint->channelVersions` and `'versions_seen' => $checkpoint->versionsSeen` without the map casts supplied by `Checkpoint::toArray()`.
**Why it matters:** A supported `PregelCheckpoint` with empty maps serializes those fields as `[]`, contradicting the documented cross-runtime checkpoint shape.
**Suggested fix:** Use one shared wire conversion for both checkpoint classes, casting these known map fields—including nested version maps—to objects; assert raw serialized output for an empty engine checkpoint.