# Review 96 - perplexity/sonar-reasoning-pro
_asked 2026-09-30T04:46:27 - served by perplexity/sonar-reasoning-pro - 117s_

## 1. Structured-output pipeline run name never reaches traces
**Severity:** MAJOR  
**Evidence:** PORT_STATUS.md entry “**A structured-output pipeline name is bound through the CONFIG slot** … **Residual, unfixed:** … even bound that way the name does not reach `Run::name()` — the break is between the merged binding config and the config the model receives.”  
**Why it matters:** Structured-output runs appear under the model’s component id instead of the pipeline name, so traces for different structured-output pipelines are indistinguishable and mis-labelled in any UI that reads `Run::name()`.  
**Suggested fix:** In the `StructuredOutput` helper, keep the existing `bind(['runName' => $runName], [])` but fix the config path so the merged binding config’s `runName` is the one passed into `BaseChatModel` (through whatever `withConfig`/`mergeConfig` chain exists). Concretely: ensure the final `RunnableConfig` seen by `BaseChatModel::generate()` preserves the caller’s `runName` rather than overwriting it with the component id.

## 2. RunnableSequence step tagging clobbers caller-supplied runName
**Severity:** MAJOR  
**Evidence:** PORT_STATUS.md entry “**A sequence step does not clobber the caller's `runName`** … `RunnableSequence::invoke` passes `stepConfig($config, $i)`, and `stepConfig` does `$config->forChild('seq:step:' . ($index + 1))`, which **overwrites** `runName` … **Not fixed yet**.”  
**Why it matters:** A sequence whose run is named (e.g. an agent pipeline) loses that name, so all runs fall back to the component id; step-specific tags are kept but the top-level name is destroyed, making trace analysis and log filtering by pipeline name unreliable.  
**Suggested fix:** Change `stepConfig` to mimic upstream `patchConfig(config, { callbacks: runManager?.getChild('seq:step:' + (i + 1)) })`: preserve `runName` on the child config and only replace the callbacks/tags. In PHP terms, make `forChild()` (or the helper around it) clone the config, set child callbacks and tags, and leave `runName` unchanged.

## 3. Completions docblock ends with a misleading, malformed sentence
**Severity:** MINOR  
**Evidence:** `src/LangChain/LanguageModels/Chat/OpenAI/Utils/Completions.php` class docblock: final line ends `“…must filter it themselves.""` (two trailing double quotes).  
**Why it matters:** This looks like an unfinished or duplicated quotation and slightly obscures the important behavioural note: that upstream filters certain block types but this port does not. It’s easy for a future editor to “fix” it by rewriting the text incorrectly, or to doubt whether the divergence is intentional.  
**Suggested fix:** Clean the docblock to a single-quoted, explicit sentence, e.g. “If a caller hand-builds such a content list, they must filter it themselves.” and, if useful, add a short cross-reference to the relevant PORT_STATUS.md row so the divergence is clearly documented in one place.