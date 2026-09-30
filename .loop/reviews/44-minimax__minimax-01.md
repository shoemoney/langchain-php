# Review 44 - minimax/minimax-01
_asked 2026-09-29T12:26:47 - served by minimax/minimax-01 - 17s_

## 1. Title
Uncaught exceptions in `TextSplitter::mergeSplits()` are silent

**Severity:** MAJOR

**Evidence:** `src/LangChain/TextSplitters/TextSplitter.php:132`

**Why it matters:** An exception thrown by `self::joinDocs()` is caught and logged but not re-thrown, so a caller receives `null` instead of an exception and has no signal that merging failed.

**Suggested fix:** Remove the `try`/`catch` and let the exception propagate.

## 2. Title
`TextSplitter::warnOversizedChunk()` is not tested

**Severity:** MINOR

**Evidence:** `src/LangChain/TextSplitters/TextSplitter.php:148`

**Why it matters:** The method has an untested side effect (`trigger_error()`), which means a test suite cannot distinguish between a splitter that warns and one that does not.

**Suggested fix:** Add a test asserting on `trigger_error()` when a chunk exceeds `chunkSize`.

## 3. Title
`TextSplitter::splitOnSeparator()` mishandles UTF-8 in `$separator`

**Severity:** MAJOR

**Evidence:** `src/LangChain/TextSplitters/TextSplitter.php:95`

**Why it matters:** A non-UTF-8 `$separator` makes `preg_quote()` bail out, falling back to `explode()` without error — which can produce invalid UTF-8 in the output, breaking the caller.

**Suggested fix:** Throw an exception when `preg_quote()` fails.

## 4. Title
`RecursiveCharacterTextSplitter` does not document its recursion

**Severity:** MINOR

**Evidence:** `src/LangChain/TextSplitters/RecursiveCharacterTextSplitter.php`

**Why it matters:** The class docblock does not explain how the recursion works or when it happens, which makes the splitter's behaviour harder to understand.

**Suggested fix:** Add a "Recursion" section to the class docblock explaining the split/merge strategy.

## 5. Title
`RecursiveCharacterTextSplitter::fromLanguage()` throws on unknown language

**Severity:** MINOR

**Evidence:** `src/LangChain/TextSplitters/RecursiveCharacterTextSplitter.php:181`

**Why it matters:** Throwing an exception on an unknown language makes the method harder to use in a dynamic context, where the language may not be known in advance.

**Suggested fix:** Return `null` or an empty separator list instead of throwing.