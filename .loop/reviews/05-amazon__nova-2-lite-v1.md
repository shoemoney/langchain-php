# Review 5 - amazon/nova-2-lite-v1
_asked 2026-09-30T07:55:17 - served by amazon/nova-2-lite-v1 - 62s_

## 1. RunnableBinding merges `configurable` and `metadata` in wrong order  
**Severity:** MAJOR  
**Evidence:** `src/LangChain/Runnables/RunnableBinding.php` mergeConfig method  
**Why it matters:** Bound config values fail to override call-time values, breaking `bind()` semantics  
**Suggested fix:** Reverse order to `$bound->configurable + $merged->configurable` and `$bound->metadata + $merged->metadata`  

## 2. RunnableSequence stream ignores non-default channels  
**Severity:** BLOCKER  
**Evidence:** `src/LangChain/Runnables/RunnableSequence.php` stream method  
**Why it matters:** Chained streams with tool/retriever channels lose data between steps  
**Suggested fix:** Carry all channels forward by removing `$channel === self::CHANNEL_DEFAULT` guard  

## 3. composer.json autoload-dev omits integration test helpers  
**Severity:** MAJOR  
**Evidence:** `composer.json` autoload-dev section vs `tests/Integration` layout  
**Why it matters:** Integration tests cannot load utility classes, risking false failures  
**Suggested fix:** Add `"LangChain\\Tests\\": "tests/"` to autoload-dev psr-4  

## 4. RunnableBinding drops bound options layer  
**Severity:** MINOR  
**Evidence:** `src/LangChain/Runnables/RunnableBinding.php` mergeConfig method  
**Why it matters:** Bound call options never reach wrapped runnable invocations  
**Suggested fix:** Include `$bound->options` in `$merged->options` merge: `$this->kwargs + ($merged->options + $bound->options)`  

## 5. RunnableBranch conditions never receive config  
**Severity:** MINOR  
**Evidence:** `src/LangChain/Runnables/RunnableBranch.php` conditionMatches method  
**Why it matters:** Conditions cannot access runtime config/tracing data  
**Suggested fix:** Always pass `$config` as second argument after reflection check