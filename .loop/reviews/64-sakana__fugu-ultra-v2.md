# Review 64 - sakana/fugu-ultra-v2
_asked 2026-10-01T02:56:32 - served by sakana/fugu-ultra-v2 - 154s_

## 1. `ChatAnthropic::generate()` reports empty text for any multi-block response
**Severity:** MAJOR
**Evidence:** `ChatAnthropic::generate()` — `$text = is_string($message->content) ? $message->content : '';`. PORT_STATUS states `MessageOutputs::contentOf()` returns a string **only** when there is exactly one text block, otherwise the raw block array.
**Why it matters:** A two-paragraph or text+thinking answer yields `new ChatGeneration($message, '', ...)`, so every string-consuming consumer (`StrOutputParser`, trace text) sees an empty answer while `$message->content` holds it. `ChatOpenAI::generate()` uses `Completions::stringifyContent($message->content)`.
**Suggested fix:** Concatenate the `text` of each `type: text` block (skipping `thinking`/`tool_use`) into `$text`; pin with a two-text-block response asserting non-empty text. *(Inference on `contentOf()`: that file is not in the packet; the `is_string` guard itself is read directly.)*

## 2. Anthropic tool input encodes a no-argument call as `[]`, not `{}`
**Severity:** MAJOR
**Evidence:** `MessageInputs::convertMessage()` builds `'input' => $call['args'] ?? []`. The OpenAI side explicitly guards this with `is_array($args) && $args !== [] && Js::isList($args) ? $args : (object) $args` in `Completions::toolCallToWire()`.
**Why it matters:** The same defect class already fixed twice in this repo (`toolCallToWire`, `MessageUtils::convertToChunk`), un-fixed on the Anthropic path: an empty arg map serialises as `"input":[]`, an array where Anthropic's schema expects an object.
**Suggested fix:** Apply the identical cast in `convertMessage()`'s `tool_use` map, and assert the raw request body contains `"input":{}` for a no-arg call.

## 3. `convertTool()` returns an unconverted array whenever `name` is present
**Severity:** MAJOR
**Evidence:** `MessageInputs::convertTool()` — `if (isset($tool['input_schema']) || isset($tool['name'])) { return $tool; }`, placed *before* the `schema`/`parameters` normalisation below it.
**Why it matters:** `['name' => 'lookup', 'parameters' => {...}]` or `['name' => ..., 'schema' => ...]` short-circuits on `name` alone and reaches Anthropic with **no `input_schema`** — the exact "model never told what arguments the tool takes" failure already recorded in HANDOFF for the outer-`parameters` bug, through a different door.
**Suggested fix:** Narrow the early return to arrays that have a non-empty `name` **and** an `input_schema`; otherwise fall through to the existing normalisation and the `$name === ''` guard.

## 4. `generate()`'s own error paths use bare `json_encode`, which the rest of the client forbids
**Severity:** MINOR
**Evidence:** `ChatOpenAI::generate()` — `throw new OpenAIException('OpenAI returned no choices for this request.', 0, json_encode($payload));` and the identical shape in the "no usable choices" throw. Every other encode in both clients is `Js::encode` with a four-line comment on why the bare form hid a defect.
**Why it matters:** `json_encode` returns `false` on malformed UTF-8 — plausible in exactly the broken payload that triggered the throw — so the diagnostic body becomes `false`/`''` and the only evidence about the bad response is discarded.
**Suggested fix:** Route both through a small helper that attempts `Js::encode` and falls back to a fixed placeholder string on `JsonException`, so the error never replaces the error.

## 5. Constructor-supplied Anthropic `strict` is recorded in `kwargs` and never sent
**Severity:** MINOR
**Evidence:** `ChatAnthropic::invocationParams()` calls `self::convertTools($bound['tools'] ?? null)` with **no second argument**, so `$strict` defaults to `null`; `bindTools()` is the only path that passes it. `convertTool()` emits `'strict'` only `if ($strict !== null)`.
**Why it matters:** Tools reaching the wire via the constructor/bound-kwargs route lose the strictness flag — the same "recorded in kwargs, never read" class as the fixed `user`/`seed`/`responseFormat` defect. Note `strict` is also absent from Anthropic's constructor `kwargs` whitelist, so only the bind path stores it.
**Suggested fix:** Pass the resolved value: `self::convertTools($bound['tools'] ?? null, isset($bound['strict']) ? (bool) $bound['strict'] : null)`, and assert the request body for a constructor-route tool.