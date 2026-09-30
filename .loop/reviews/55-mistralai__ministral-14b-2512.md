# Review 55 - mistralai/ministral-14b-2512
_asked 2026-09-29T13:42:13 - served by mistralai/ministral-14b-2512 - 23s_

Here are five specific findings from the review, adhering strictly to the requested format:

---

## 1. **`RunnableInterface::batch()` ignores `$options` without documentation**
**Severity:** MAJOR
**Evidence:** `src/LangChain/Runnables/RunnableInterface.php:100-105`
**Why it matters:** The method accepts `$options` (upstream's `batchOptions`) but silently ignores it, which could mislead users into thinking concurrency or error handling is configurable. The docblock explicitly states this behavior but does not clarify why it’s a **hard failure** (e.g., "not implemented" vs. "unsupported").
**Suggested fix:** Add a `@throws \RuntimeException` note to the docblock for unsupported options, or explicitly reject them in a `validateOptions()` method called at the start of `batch()`.

---

## 2. **`PartialJsonParser` lacks UTF-16 code unit validation**
**Severity:** MAJOR
**Evidence:** `src/LangChain/OutputParsers/PartialJsonParser.php` (no explicit validation)
**Why it matters:** The parser claims to match upstream’s UTF-16 behavior but uses `mb_strlen()` (UTF-8) for offsets. This could cause silent truncation or malformed parsing for astral characters (e.g., emoji) where UTF-16 and UTF-8 code unit counts diverge.
**Suggested fix:** Replace `mb_strlen()` with a UTF-16-aware length function (e.g., `strlen(mb_convert_encoding($str, 'UTF-16')) / 2`) for offset calculations, or document the divergence in `PORT_STATUS.md`.

---

## 3. **`StructuredOutputParser` does not validate JSON Schema draft version**
**Severity:** MINOR
**Evidence:** `src/LangChain/OutputParsers/StructuredOutputParser.php:50` (hardcoded `$schema` field)
**Why it matters:** The parser assumes `$schema` points to draft-07 without validating it, which could lead to silent failures if the schema uses a different draft (e.g., draft-04). Upstream’s Zod integration would catch this.
**Suggested fix:** Add a `validateSchemaDraft()` method that checks `$schema['$schema']` against supported drafts (e.g., `['http://json-schema.org/draft-07/schema#']`) and throws on mismatch.

---

## 4. **`JsonOutputParser::parsePartialResult()` ignores `message` field**
**Severity:** MINOR
**Evidence:** `src/LangChain/OutputParsers/JsonOutputParser.php:30` (only uses `$generations[0]['text']`)
**Why it matters:** The method discards the `message` field from `$generations`, which could hide metadata (e.g., `BaseMessage` roles like `system` or `tool`) needed for context-aware parsing. Upstream’s `JsonOutputParser` uses the full message hierarchy.
**Suggested fix:** Update `parsePartialResult()` to merge `text` from all messages (e.g., `array_reduce($generations, fn($carry, $gen) => $carry . ($gen['text'] ?? ''), '')`).

---

## 5. **`BaseCumulativeTransformOutputParser` lacks diff-mode validation**
**Severity:** MINOR
**Evidence:** `src/LangChain/OutputParsers/BaseCumulativeTransformOutputParser.php:40` (no check for `diff` truthiness)
**Why it matters:** The `diff` flag is settable but never validated for compatibility with `diffOperations()`. If `$diff = true` but `diffOperations()` returns `null`, consumers receive no output, which violates the "unchanged results are suppressed" contract.
**Suggested fix:** Add a constructor validation:
```php
if ($fields['diff'] ?? false && !method_exists($this, 'diffOperations')) {
    throw new \RuntimeException('diff mode requires diffOperations()');
}
```

---