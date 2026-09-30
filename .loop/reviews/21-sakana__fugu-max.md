# Review 21 - sakana/fugu-max
_asked 2026-09-30T11:19:33 - served by sakana/fugu-max - 237s_

## 1. `mergeSplits()` measures the separator in code points, not with the configured length function
**Severity:** MAJOR
**Evidence:** `TextSplitter::mergeSplits()` — the condition `$total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize` and the identical term in the pop loop. Upstream is `this.lengthFunction(separator) * currentDoc.length` (`text_splitter.ts`).
**Why it matters:** the class docblock invites "a token-based splitter [to] swap in a token counter here without touching the merging logic", but the merge then adds a token count for splits to a code-point count for separators. Chunk boundaries and the oversized-chunk warning silently disagree with upstream for any non-default `$lengthFunction` (a token counter scores `"\n\n"` as 1, `mb_strlen` as 2).
**Suggested fix:** use `$this->lengthOf($separator)` in both places.

## 2. `warnOversizedChunk()`'s docblock promises `trigger_error()`, the body appends to a process-global static
**Severity:** MINOR
**Evidence:** docblock "Routed through `trigger_error()` so the condition is observable by anything reading the error log" against the body `self::$oversizedChunkWarnings[] = "Created a chunk of size {$size}, ..."`, backed by `private static array $oversizedChunkWarnings`.
**Why it matters:** the notice never reaches a log an operator reads, and because the buffer is static it is shared by every splitter instance and every test in the process — an assertion on `oversizedChunkWarnings()` can pass on a previous test's leftovers, the "green for the wrong reason" shape.
**Suggested fix:** correct the docblock to "recorded"; make the buffer an instance property, or document that `clearOversizedChunkWarnings()` must be called in `tearDown` and pin that with a test.

## 3. CI never validates the autoloader, the only check that catches a PSR-4-misplaced file
**Severity:** MAJOR
**Evidence:** `.github/workflows/ci.yml` runs `composer validate --strict`, `find src tests -name '*.php' | xargs php -l`, then PHPUnit. No classmap build. HANDOFF records five files written to `…/Chat/` instead of `…/Chat/{OpenAI,Anthropic}/` with identical FQCNs — "never autoloaded (so the suite stayed green)".
**Why it matters:** PHPUnit loads test files by path and `php -l` checks syntax only, so a new file in the wrong subtree stays invisible to CI and breaks only a consumer running an optimised autoloader. The current guard is a manual instruction in HANDOFF.
**Suggested fix:** add a CI step `composer dump-autoload --optimize --strict-psr --strict-ambiguous` (confirm the flag spelling for the pinned Composer 2), which fails on ambiguous and PSR-4-violating classes.

## 4. `batch()` carries two stacked docblocks while PORT_STATUS says there is exactly one
**Severity:** MINOR
**Evidence:** the packet's `src/LangChain/Runnables/RunnableInterface.php` shows a block ending `@return list<mixed>` immediately followed by a second block beginning `Run several inputs.` on `batch()`. PORT_STATUS (`fix:0aa319e`) states a duplicated batch docblock "does not exist; there is exactly one".
**Why it matters:** PHP binds only the nearest docblock, so the first block's `@param list<mixed> $inputs` / `$config` / `$options` tags are dead text — the signature's only parameter documentation. And a ledger that denies a shape the shipped file still has is the "ledger asserts a fix not in the tree" class the project calls most dangerous. One of the two is stale.
**Suggested fix:** read the two blocks directly above `public function batch(` and delete the orphaned first one, or correct the ledger row.

## 5. `tests/Integration` is outside the `autoload-dev` PSR-4 mapping
**Severity:** MINOR
**Evidence:** `composer.json` maps `"LangChain\\Tests\\": "tests/Unit/"`; the integration suite lives in `tests/Integration` and CI runs it as its own suite. *Inference — the integration test namespaces are not in this packet.*
**Why it matters:** if those classes declare `LangChain\Tests\Integration\…`, the mapping resolves them to `tests/Unit/Integration/…`, so the autoloader can never load them; PHPUnit's path-based loading hides it — the same way it hid the dead `LangGraph\Tests\` mapping the ledger already had to remove.
**Suggested fix:** check with `grep -rh '^namespace' tests/Integration | sort -u`, then add the matching prefix (e.g. `"LangChain\\Tests\\Integration\\": "tests/Integration/"`) and let finding 3's classmap step hold it.