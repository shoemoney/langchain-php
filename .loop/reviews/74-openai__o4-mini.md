# Review 74 - openai/o4-mini
_asked 2026-09-30T01:01:56 - served by openai/o4-mini - 61s_

## 1. RunnableSequence ignores custom step names  
**Severity:** MINOR  
**Evidence:** `src/LangChain/Runnables/RunnableSequence.php` stores `$this->names` but `stepConfig()` always builds `'seq:step:'.($index+1)`  
**Why it matters:** Any custom labels passed into `RunnableSequence::from()` or via `pipe($name, $runnable)` are never used in trace `runName`s, losing meaningful step names in traces.  
**Suggested fix:** In `stepConfig()`, use `$this->names[$index]` when present, e.g.  
```php
$runName = $this->names[$index] ?? 'seq:step:'.($index+1);
```  

## 2. RunnableInterface has two conflicting docblocks for `batch()`  
**Severity:** MINOR  
**Evidence:** In `src/LangChain/Runnables/RunnableInterface.php` there are two separate docblocks above `public function batch(…)`  
**Why it matters:** Redundant and partially contradictory documentation increases maintenance burden and risks drifting from actual behavior.  
**Suggested fix:** Remove the outdated first docblock so there is a single, accurate description immediately above the method signature.  

## 3. RunnableBinding constructor’s `$config` parameter lacks phpdoc  
**Severity:** MINOR  
**Evidence:** `src/LangChain/Runnables/RunnableBinding.php` constructor signature has `?array $config` with no `@param` explaining its shape  
**Why it matters:** It is unclear to readers what keys/structure the `$config` array may contain before `RunnableConfig::fromArray()` runs, hindering correct use and extension.  
**Suggested fix:** Add a phpdoc above `__construct` such as:  
```php
/**  
 * @param array<string,mixed> $kwargs  
 * @param array<string,mixed>|null $config  Config overrides for RunnableConfig::fromArray()  
 */
```  

## 4. Composer.json does not require ext-pdo_sqlite yet SQLite saver is used  
**Severity:** MINOR  
**Evidence:** `composer.json` “require” section lists `ext-json` and `ext-mbstring` but omits `ext-pdo_sqlite`, and `src/LangGraph/Checkpoint/SqliteSaver.php` depends on PDO SQLite  
**Why it matters:** In environments where PHPʼs PDO SQLite extension is not enabled by default, installation or CI will break when running the SQLite checkpoint tests.  
**Suggested fix:** Add `"ext-pdo_sqlite": "*"` to the `require-dev` (or `require`) section of `composer.json`.  

## 5. Coverage job omits integration suite  
**Severity:** MINOR  
**Evidence:** `.github/workflows/ci.yml` “coverage” step runs `phpunit --testsuite unit --coverage-clover` only  
**Why it matters:** Integration tests (e.g. real HTTP + SSE parsing) are not measured for coverage, giving a false sense of coverage completeness.  
**Suggested fix:** Change the coverage command to include both suites, for example:  
```
phpunit --testsuite unit --testsuite integration --coverage-clover clover.xml
```