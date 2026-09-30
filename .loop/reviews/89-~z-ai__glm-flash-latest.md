# Review 89 - ~z-ai/glm-flash-latest
_asked 2026-09-30T03:25:54 - served by z-ai/glm-5.3-flash - 224s_

## 1. Non-leading system message is sent as a `"role":"system"` turn
**Severity:** MAJOR
**Evidence:** `MessageInputs::convert()` hoists only the leading run; `convertMessage()` match arm `'system' => 'system'` then emits it inside `messages`. The class docblock claims the provider "applies it from that point on".
**Why it matters:** Anthropic's Messages API accepts only `user`/`assistant` roles in `messages` — a mid-conversation system turn is a 400 at send time (API behaviour is inference from the wire contract; verify against the upstream oracle). Either it fails or it diverges from upstream's handling.
**Suggested fix:** Check upstream `message_inputs.ts` for the non-leading-system branch and match it (convert to a user turn or throw a named local error); pin with a converted test.

## 2. `defaultHeaders` silently override the pinned `anthropic-version` and `x-api-key`
**Severity:** MINOR
**Evidence:** `headers()` returns `$this->defaultHeaders + ['x-api-key' => ..., 'anthropic-version' => self::API_VERSION, ...]` — PHP union keeps the LEFT side, so a caller's `defaultHeaders['anthropic-version']` wins.
**Why it matters:** Directly contradicts the const docblock ("Pinned, not configurable-by-default… a silently negotiated version would make the same code behave differently on two days"); also lets a stray header key drop auth.
**Suggested fix:** Either put the auth/version map on the left of the union, or explicitly refuse `anthropic-version`/`x-api-key` in `defaultHeaders` at construction.

## 3. Stream `catch (\Throwable)` misattributes non-transport failures as HTTP errors
**Severity:** MINOR
**Evidence:** In `ChatAnthropic::streamResponseChunks()` the `try` encloses `yield from $this->consume(...)`, which includes `MessageOutputs` translation and `$runManager?->handleLLMNewToken(...)`. A throwing callback handler or a `TypeError` in translation is caught by `catch (\Throwable $e)` and rethrown as `'The HTTP transport raised ' . $e::class`.
**Why it matters:** A caller debugging "The HTTP transport raised TypeError" chases the network when the bug is local; the same wrap also swallows the retry distinction the comment promises.
**Suggested fix:** Narrow the drain that sits inside the try (wrap only `$this->http()->postStream(...)` and the byte reads), or rethrow non-`HttpException` non-`AnthropicException` throwables unwrapped.

## 4. `RunnableInterface::transform()` docblock cites a PORT_STATUS row that does not exist
**Severity:** MINOR
**Evidence:** Docblock: "That is a deliberate divergence… See PORT_STATUS.md." The "Known non-exact behaviours" table in the packet's PORT_STATUS.md contains no row about `transform`/buffered streaming.
**Why it matters:** The docblock appeals to an authority that doesn't record it — the exact docs-vs-ledger drift class this project guards against elsewhere (`DocsMatchRealityTest`).
**Suggested fix:** Add the divergence row to PORT_STATUS.md, or drop the citation.

## 5. Per-call `tools: []` silently falls back to bound tools in both clients
**Severity:** MINOR
**Evidence:** Both `invocationParams()` use `'tools' => convertTools($this->pick($options, 'tools')) ?? convertTools($bound['tools'] ?? null)`, and `convertTools()` returns `null` for `[]` — so an explicit empty per-call list is indistinguishable from an absent one and the bound tools are sent.
**Why it matters:** A caller who binds tools then tries to suppress them per call ("this request, no tools") gets the bound tools offered anyway, with no error — the same recorded-but-not-honoured shape as past `kwargs` defects.
**Suggested fix:** Distinguish present-empty from absent: `array_key_exists('tools', $options)` with an empty list should yield no `tools` key, not the bound set.

Five findings; all quoted from the packet source. Finding 1's API-side claim is the only inference and is labelled as such.