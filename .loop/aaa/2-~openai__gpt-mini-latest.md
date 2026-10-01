# Triple-A cycle 2 - ~openai/gpt-mini-latest
_asked 2026-10-01T05:14:27 - 95s - max_tokens 24,000_

## 1. Sequence step names are still clobbered
**Severity:** MAJOR
**Where:** `src/LangChain/Runnables/RunnableSequence.php:158`
**Input → Output:** Build a sequence with `runName = "pull_person"` and invoke it under structured output. The step config comes out with `runName = "seq:step:1"` instead of preserving the caller’s name.
**Should be:** The caller’s `runName` should survive, and the step marker should live in tags or equivalent child-run metadata.
**Why it outs the port:** Traces are mislabelled, so consumers lose the name they set to follow a run end-to-end.

## 2. SQLite checkpoint history ignores `checkpoint_id`
**Severity:** MAJOR
**Where:** `src/LangGraph/Checkpoint/SqliteSaver.php:280`
**Input → Output:** Ask SQLite for a thread’s history with `configurable['checkpoint_id'] = 'abc'`. It returns the broader thread history instead of just the checkpoint requested.
**Should be:** The saver should narrow to that checkpoint ID and return the matching tuple/history only.
**Why it outs the port:** Resume/debug flows can load the wrong state, which is a silent data bug at the boundary consumers care about most.

## 3. Batch failures do not settle every opened run
**Severity:** MAJOR
**Where:** `src/LangChain/LanguageModels/BaseChatModel.php:520`
**Input → Output:** Send a 3-prompt batch where prompt 2 fails. The implementation leaves later runs hanging or half-settled instead of finishing them with the batch error.
**Should be:** Every run opened for the batch should be settled with the batch’s own error, even after the first failure.
**Why it outs the port:** Tracing and lifecycle accounting go out of sync, so a consumer sees orphaned runs rather than a clean failure boundary.