# Review 90 - ~moonshotai/kimi-latest
_asked 2026-09-30T03:36:07 - served by moonshotai/kimi-k3 - 88s_

## 1. Measured-state table contradicts HANDOFF on whether the suite even runs
**Severity:** BLOCKER
**Evidence:** Packet header: "Suite | **2252 tests, 0 assertions** (NOT GREEN)" vs HANDOFF.md: "**2252 passing, 6317 assertions**".
**Why it matters:** One of the two is a false claim. If the suite genuinely collects 0 assertions, every "tests exist and pass" row in PORT_STATUS.md is unverified; if it is green, the packet's own measured state lies to every reviewer.
**Suggested fix:** Re-run `composer test`, capture the real PHPUnit summary line, and make whichever document is wrong match it. Add the assertion count to the existing `DocsMatchRealityTest` guard so the two cannot drift again.

## 2. `RunnableInterface::batch()` still carries two stacked docblocks
**Severity:** MINOR
**Evidence:** The source shows a full `/** … @return list<mixed> */` block immediately followed by a second `/** Run several inputs. … */` block before `public function batch`. PORT_STATUS claims "there is exactly one."
**Why it matters:** Only the second docblock attaches to the method; the first is dead text whose `@param`/`@return` tags no tool reads, and the ledger row asserting this was fixed is now itself inaccurate — the exact docs-vs-code class this project tracks.
**Suggested fix:** Delete the first docblock, merge its `@param` tags into the second, and correct the ledger entry.

## 3. `convertToChunk()` silently maps FunctionMessage (and any unknown type) to AIMessageChunk
**Severity:** MAJOR
**Evidence:** The `match ($message->type)` in `MessageUtils::convertToChunk()` has arms for `ROLE_HUMAN` and `ROLE_SYSTEM` only; `default => new AIMessageChunk($base)`. A `FunctionMessage` falls into `default` and comes back as an AI chunk.
**Why it matters:** A function-result message round-tripped through a chunk changes role — a downstream fold reconstructs it as AI output, corrupting history with no error. (Upstream comparison is inference: I could not see the TS `convertToChunk` in the packet, but upstream throws for unhandled types rather than relabelling.)
**Suggested fix:** Add explicit arms for `ROLE_FUNCTION` (its own chunk class or a faithful mapping) and make `default` throw `\InvalidArgumentException` naming the type.

## 4. `getBufferString()` fatals on a first-class message type
**Severity:** MINOR
**Evidence:** The `match ($m->type)` in `MessageUtils::getBufferString()` covers human/ai/system/tool/chat and throws on `default`; `ROLE_FUNCTION` and `ROLE_REMOVE` are declared constants on `BaseMessage` and `FunctionMessage` is a ported class.
**Why it matters:** Any memory/transcript render over a history containing a legacy `FunctionMessage` — which this port still constructs and coerces — raises `InvalidArgumentException` instead of rendering.
**Suggested fix:** Add `BaseMessage::ROLE_FUNCTION => 'Function'` (matching upstream's label) to the match; keep the throw for genuinely unknown types.

## 5. A single associative content block is misread as a field map and its content dropped
**Severity:** MINOR
**Evidence:** `BaseMessage::looksLikeFieldMap()` returns true on key `'type'`, so `new HumanMessage(['type' => 'text', 'text' => 'hi'])` is treated as a field map with no `content` key → `$this->content = []`. Meanwhile `MessageMerge::contentBlocks()` explicitly wraps exactly this single-block shape, so the shape circulates in the codebase.
**Why it matters:** The message constructs without error and its text is silently empty — a wrong value written and never flagged. (Inference that this input occurs: the merge layer's own handling says single blocks arrive from Anthropic-shaped serializers.)
**Suggested fix:** In `looksLikeFieldMap()`, treat `['type' => …, 'text' => …]` (a valid content block per `ContentBlock::isBlock`) as bare content, or reject a field map lacking `content` with a clear exception rather than defaulting to `[]`.