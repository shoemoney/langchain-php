# Review 55 - anthropic/claude-sonnet-5.5
_asked 2026-09-29T14:50:36 - served by anthropic/claude-sonnet-5.5 - 13s_

I found four real findings from reading the packet, plus one that is an inference. The packet has no saver source (put/getTuple, SQLite/memory), so I could not trace the store or load half.

## 1. Empty map and empty list collapse to the same value in the checkpoint
**Severity:** MAJOR
**Evidence:** `JsonPlusEncoder::walk` (array branch, `$out[$key] = ...`) and `encode` → `json_encode`. `JsonPlusDecoder::revive` (`array_is_list`).
**Why it matters:** An empty map channel value or `versions_seen` entry (`{}`) is written as `[]`. On load it comes back as an empty list, and the JS side would read `[]` where it expects `{}`. Cross-runtime resume then sees a different type. `channel_versions` and `versions_seen` for a fresh checkpoint are exactly this case.
**Suggested fix:** In `Checkpoint::toArray`, wrap the map-typed fields (`channel_versions`, `versions_seen` and its inner maps) in `(object)` or `stdClass`, or make the encoder emit `{}` for them. Add a test that inspects the raw JSON of an empty checkpoint.

## 2. Set and Map are never written, so the decoder branches are dead and lossy
**Severity:** MAJOR
**Evidence:** `envelopeFor` has no Set or Map case. The docblock example shows `new Set([1,2])` → `[1,2]`. The decoder revives `Set` and `Map` records, and `Set` returns a plain list.
**Why it matters:** The docs claim the format matches JS "byte for byte". A JS-written `Map` decodes to a PHP array and cannot be told apart from ordinary data. A `Map` with non-string keys is coerced by `mapKey`: `true` becomes 1 and `null` becomes `''`. After a round trip, colliding keys silently overwrite each other.
**Suggested fix:** Detect key collisions in the `Map` branch and leave the record inert (return null) when they occur. Document that the round trip is one-way for these types.

## 3. Object serialisation drops the class, so objects come back as arrays
**Severity:** MAJOR
**Evidence:** `walk` object branch: `get_object_vars($value)` with no envelope. This covers any non-`Serializable` object, and any private state is lost. The loader registry (`LcConstructorLoader::REGISTRY`) covers messages only.
**Why it matters:** State objects held in channel values are written as plain maps and revived as arrays. A consumer expecting the object fails or reads wrong data after a resume, with no error at write time. The lossiness is silent.
**Suggested fix:** Throw at encode time for an unregistered object that is neither `JsonSerializable` nor `Serializable`, or emit an explicit marker record. Add a test that expects the failure.

## 4. Binary detection is a heuristic and misclassifies text
**Severity:** MINOR
**Evidence:** `isBinaryString` (`!mb_check_encoding`). A `Uint8Array` whose bytes happen to be valid UTF-8, such as ASCII, goes down the `json` path as a string. `dumpsTyped` writes `['bytes', ...]` only for invalid UTF-8.
**Why it matters:** A byte payload that is valid UTF-8 comes back as a string. This is harmless in PHP, but a JS reader sees a string, not a `Uint8Array`. Conversely, a corrupt text string is silently reclassified as bytes and encoded as an integer list.
**Suggested fix:** Document the limitation. Consider a `Bytes` wrapper type for values that must round-trip as bytes.

## 5. Silent defaults in `Checkpoint::fromArray` and `versionsOf`
**Severity:** MAJOR
**Evidence:** `fromArray`: `(int) ($data['v'] ?? CHECKPOINT_VERSION)`, `(string) ($data['id'] ?? '')`, `mapOf` returning `[]`. `versionsOf`: `(string) $version`.
**Why it matters:** A truncated or corrupt record loads as an empty checkpoint with `id ''` and no channel values, so the run resumes from blank state. A non-array `channel_values` also becomes `[]`. A version that arrived as a float (`3.0`) is stringified to `"3"` and then compared as a string, which breaks ordering against integer versions. There is no version-branch check on `v` either.
**Suggested fix:** Throw when `id` or `channel_values` is missing or has the wrong type, and when `v` is greater than the supported version. Reject non-int/string versions rather than casting them.