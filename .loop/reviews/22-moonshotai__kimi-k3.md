# Review 22 - moonshotai/kimi-k3
_asked 2026-09-30T11:36:27 - served by moonshotai/kimi-k3 - 55s_

## 1. `convertToChunk()` turns Function and Chat messages into `AIMessageChunk`
**Severity:** MAJOR
**Evidence:** `MessageUtils::convertToChunk()` — the final `match ($message->type)` has arms only for `ROLE_HUMAN` and `ROLE_SYSTEM`; `default => new AIMessageChunk($base)`.
**Why it matters:** `convertToChunk(new FunctionMessage(...))` or a `ChatMessage` returns a chunk whose `type` is `'ai'`. A round-trip message→chunk→fold silently relabels the role, and a checkpoint written from that chunk reads back as an AI message. Upstream has `FunctionMessageChunk`/`ChatMessageChunk` counterparts.
**Suggested fix:** Add arms for `ROLE_FUNCTION` → `FunctionMessageChunk` and `ROLE_CHAT` → `ChatMessageChunk` (carrying `role`), and throw on anything unmapped rather than defaulting to AI.

## 2. `convertToChunk()` encodes empty tool-call args as `"[]"`, not `"{}"`
**Severity:** MAJOR
**Evidence:** `MessageUtils::convertToChunk()` — `'args' => json_encode($call['args'] ?? [], JSON_UNESCAPED_SLASHES)`. PHP `json_encode([])` is `"[]"`.
**Why it matters:** This is the exact defect class already fixed in `Completions::toolCallToWire()` ("a no-argument tool call encoded as `{"arguments":"[]"}`"). Within PHP the round-trip is lossless, but the chunk's `args` string is the wire/checkpoint form — a JS reader decodes a positional list where upstream writes an object, contradicting the stated cross-runtime readability goal.
**Suggested fix:** Mirror `toolCallToWire()`: cast to `(object)` when the args array is a non-list (or empty), so empty args encode as `"{}"` while a genuine list stays `[]`.

## 3. `getBufferString()` fatals on a `FunctionMessage`
**Severity:** MINOR
**Evidence:** `MessageUtils::getBufferString()` — the `match ($m->type)` covers human/ai/system/tool/chat and `default => throw`. `ROLE_FUNCTION` (`'function'`) hits the throw.
**Why it matters:** `FunctionMessage` is a first-class ported class (constructor, coercion, stored-message mapping all accept it), but rendering any transcript containing one throws `InvalidArgumentException`. Inference: upstream's `getBufferString` may also throw — if so, the gap should be pinned as deliberate, not left to be discovered.
**Suggested fix:** Either add a `'Function'` prefix arm, or add a test pinning the throw as matching upstream's behaviour for function messages.

## 4. `BaseMessage` constructor mangles non-list `content`
**Severity:** MINOR
**Evidence:** `BaseMessage::__construct()` — `$this->content = is_array($f['content']) ? array_values($f['content']) : (string) $f['content'];`
**Why it matters:** Two silent coercions: a single assoc block passed as `content` (`['content' => ['type'=>'text','text'=>'hi']]`) is destroyed by `array_values` into `['text','hi']`, and a scalar `content` (int/float/bool) is stringified with no error. Both produce a plausible-looking message holding wrong data — the wrong-value-written-never-read class.
**Suggested fix:** Reject an assoc-array `content` (a block is not a block list) and reject non-string scalars, the same refuse-don't-empty rule already applied in `carriesIdentityButNoContent()`.

## 5. `updateId(null)` changes the serialized shape
**Severity:** MINOR
**Evidence:** `BaseMessage::updateId()` — `$this->kwargs['id'] = $value;` runs unconditionally, while the constructor only adds the `id` key `if ($this->id !== null)`.
**Why it matters:** `updateId(null)` produces `kwargs` containing `'id' => null`, a key a freshly built message never has. Since `toDict()` serializes straight from `kwargs`, the stored form of an id-cleared message differs from an id-less one — against the byte-compatible-payload claim, and a JS reader sees `id: null` where upstream omits the key.
**Suggested fix:** In `updateId()`, `unset($this->kwargs['id'])` when `$value === null`, else set it — matching the constructor's conditional.