# Review 102 - ~openai/gpt-mini-latest
_asked 2026-09-30T06:27:34 - served by openai/gpt-5.4-mini - 100s_

## 1. `text-plain` is dropped from `BaseMessage::text()`
**Severity:** MAJOR  
**Evidence:** `ContentBlock::textFrom()` only appends blocks where `self::isText($block)`; `MessageUtils::contentBlockToString()` separately handles `'text-plain' => ...`.  
**Why it matters:** A message containing `text-plain` blocks renders in transcripts but disappears from `BaseMessage::text()`, so callers get different text depending on which API they use.  
**Suggested fix:** Teach `textFrom()` to treat `text-plain` as textual too, or route both text extraction paths through one shared helper.  

## 2. `BaseMessage::contentBlocks()` does not normalize single-block arrays
**Severity:** MAJOR  
**Evidence:** `BaseMessage::contentBlocks()` does `return $this->content;`, while `MessageMerge::contentBlocks()` wraps non-lists with `[$content]`.  
**Why it matters:** A caller who passes a single block array gets an associative array back, so downstream iteration sees `type/text/...` values instead of one block and corrupts rendering/merging.  
**Suggested fix:** Mirror `MessageMerge::contentBlocks()` here: return `array_values()` for lists, and wrap any single block object in a one-element list.  

## 3. `ContentBlock::data()` lets extras overwrite required fields
**Severity:** MAJOR  
**Evidence:** `ContentBlock::data()` uses `array_merge([ 'type' => self::FILE, ...], $extra);`, while the other factories use `+ $extra`.  
**Why it matters:** A caller can accidentally or maliciously override `type`, `source_type`, `mime_type`, or `data`, producing a block that claims one shape but carries another.  
**Suggested fix:** Use `+ $extra` or explicitly reject reserved keys in `$extra` so the core fields stay immutable.  

## 4. `mergeStatus()` invents `success` when both inputs are unset
**Severity:** MINOR  
**Evidence:** `if ($left === 'error' || $right === 'error') { return 'error'; } return 'success';`  
**Why it matters:** A chunk with no status becomes indistinguishable from an explicit success, which can mark incomplete or status-less merges as finished.  
**Suggested fix:** Preserve nullability: return `null` when both sides are null, otherwise propagate the non-null status unless either side is `error`.  

## 5. `updateId(null)` leaves a null `id` in serialized kwargs
**Severity:** MINOR  
**Evidence:** `BaseMessage::updateId()` does `$this->kwargs['id'] = $value;` even though `$value` is `?string`.  
**Why it matters:** Clearing an id writes `id: null` into the serialized constructor, so a message that should serialize like a fresh instance now carries a stale null key.  
**Suggested fix:** On null, `unset($this->kwargs['id'])`; only write the key when an id is actually present.