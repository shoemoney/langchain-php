# Advisory review — z-ai/glm-5v-turbo

_Generated 2026-09-30T02:51:24.366717+00:00_

# WHOLE-SYSTEM ADVISORY REVIEW — langchain-php

---

### 1. layering
**Verdict:** sound
**Evidence:** Cross-package reference count: "LangGraph -> LangChain: 15 files" (Section 3). Namespace inventory (Section 2) shows zero files in `LangChain.*` that import from `LangGraph.*`. Previous advisory (google-gemini-3.5-flash) independently confirmed unidirectionality.
**Finding:** None. The dependency arrow points one way: the graph engine depends on core abstractions; core abstractions never reference the graph engine. Provider clients (`ChatOpenAI`, `ChatAnthropic`) live in `LangChain\LanguageModels\Chat\*` and have no knowledge of `LangGraph\Pregel` or `LangGraph\State`.
**Suggested change:** none

---

### 2. public-api
**Verdict:** needs work
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php` — 9 public methods listed (Section 4). `src/LangGraph/Pregel/Pregel.php` — 6 public methods plus promoted public properties (`nodes`, `channels`, `inputChannels`, `outputChannels`, `streamMode`, `debug`). `src/LangChain/Tools/Schema.php` — 11 public methods.
**Finding:** The surface is mostly coherent but carries one upstream-faithful hazard: `Pregel`'s constructor promotes `nodes`, `channels`, `inputChannels`, `outputChannels`, `streamMode`, and `debug` as public readonly properties. This was reviewed and rejected as a finding (upstream does the same), but it remains a surface where a caller can mutate compilation state after construction in ways the invoke/stream paths may not handle. The `RunnableInterface::batch()` docblock admits `$options` is "accepted and ignored" — honest, but means the interface lies about its contract (caller passes something that does nothing).
**Suggested change:** Add an `@deprecated` or `@internal` tag on `Pregel::$debug` and `$streamMode` — these are compilation parameters, not runtime configuration, and mutating them between `invoke()` calls would hit untested code. For `batch()`, either implement `returnExceptions` or remove the parameter from the interface; "accepted and ignored" is a trap for downstream consumers who read interfaces as contracts.

---

### 3. composition
**Verdict:** sound
**Evidence:** PORT_STATUS.md (Section 6) documents nine composition-related fixes: `RunnableBinding` config merging, `RunnableLambda` config passthrough, `RunnableParallel` scalar acceptance, `RunnableAssign` non-record behavior, `bindTools` kwargs precedence, canonicalised option spelling tables, `withStructuredOutput` base implementation, tool conversion on all client paths, and `BaseChatModel::bindTools()` default throw.
**Finding:** Subsystems compose. The two seams that historically bit — tool-call argument encoding (`{}` vs `[]`) and checkpoint `channel_versions` serialisation (`{}` vs `[]`) — are both fixed and mutation-verified. The remaining risk is at the `RunnableLambda` boundary: config is now passed, but only when reflection confirms arity ≥ 2 or variadic. A lambda whose second parameter is *not* `RunnableConfig` will silently receive config as whatever that parameter means.
**Suggested change:** Add a guard in `RunnableLambda::invoke()` that type-checks the reflected second parameter against `RunnableConfig` (or at minimum logs a warning) when arity ≥ 2. The current "pass it if they can take it" logic is clever but will silently misroute config if a user writes `fn($input, $tools) => ...`.

---

### 4. error-handling
**Verdict:** sound
**Evidence:** `TransportExceptionContractTest` (Section 1) pins wrapping behavior for OpenAI, Anthropic, and the eager path. PORT_STATUS documents: stream-stall throws `HttpException` naming the URL; batch failure settles all opened runs; corrupt checkpoint records are refused; SSE error events in-stream raise rather than silently ending; `finally` double-reporting on catch-exit fixed.
**Finding:** Exception types are stratified correctly: transport errors wrap provider errors, and callers can catch `HttpException` and trust it originated from the HTTP layer. The one gap: `BaseChatModel::bindTools()` throws by default (documented divergence), but the exception type and message are not pinned in the public contract — a caller who catches `\\Exception` will work, but there is no dedicated `UnsupportedOperationException` or similar to make the "this model does not support tools" case distinct from "this model's tool binding failed."
**Suggested change:** Define and throw a dedicated `LangChain\\Exception\\NotSupportedException` (or similar) from `BaseChatModel::bindTools()` so callers can distinguish "you asked this model to do something it cannot" from "something went wrong while trying."

---

### 5. fidelity
**Verdict:** sound (with documented divergences)
**Evidence:** PORT_STATUS.md (Section 6) lists ~30 deliberate divergences, each with a "Why" column and a "Where" column pinning source location. Previous audits rejected several claimed divergences that were actually faithful ports (mergeDicts throwing, parseToolCalls rejecting non-string args, MessageUtils type error, MessageMerge empty model_name, array_values on content).
**Finding:** The port is honestly divergent where it must be (PHP's single array type, no optional methods, named argument binding semantics) and faithfully identical where it can be. The three substantive unported areas — OpenAI Responses API, Zod schema branching, jsonMode/strict mode — are documented scope decisions, not accidental gaps. The `RunnableInterface::transform()` docblock discrepancy (claimed per-item streaming, actually buffers) is now documented in PORT_STATUS.
**Suggested change:** none — the fidelity record is the strongest part of this codebase.

---

### 6. test-strategy
**Verdict:** needs work
**Evidence:** 2148 tests / 6069 assertions (Section 1). Load-bearing guards: `PhpVersionCompatibilityTest`, `DocsMatchRealityTest`, `TransportExceptionContractTest`. History of defects every suite passed: Schema nested-instance bug (found by external install), tool-conversion on constructor/per-call paths (found by glm audit), eager retry all-statuses (found by glm audit), stream stall silence (found by opus audit), batch run leak (found by opus audit), checkpoint empty-map serialisation (found by serde audit), comparePathSegments ordering (found by high-risk audit).
**Finding:** The suite proves correctness of the **exercised paths** but has systematic blind spots: (1) it prefers the "happy" variant of any multi-variant feature (bindTools works, constructor tools didn't; streaming retry worked, eager didn't); (2) it asserts on PHP objects rather than wire formats (checkpoint test asserted array type, not JSON shape); (3) it rarely composes across layers in a single test (unit tests mock neighbours, so seam defects are invisible). The `DocsMatchRealityTest` is ingenious — it proves the documentation isn't lying about coverage — but it cannot prove coverage of the *right* things.
**Suggested change:** Add a **path-coverage matrix** for every public method with multiple implementation paths. For `ChatOpenAI`: constructor-options, bindTools-kwargs, and per-call-options must all hit the same assertion set. For `BaseChatModel::stream()`: complete stream, mid-stream error, and stall-timeout must all be exercised. For `Checkpoint::fromArray`: valid, corrupt, absent, and old-format must all be tested. This is the class of defect the last 40 iterations keep finding.

---

### 7. documentation
**Verdict:** sound
**Evidence:** `PORT_STATUS.md` (Section 6) — table format with Behaviour / Why / Where columns. `HANDOFF.md` — file counts and suite size. `DocsMatchRealityTest` (Section 1) — three assertions: `testPortStatusTotalIsNotUnderstatingCoverage`, `testHandoffFileCountsAreCurrent`, `testHandoffStatesACurrentSuiteSize`.
**Finding:** The documentation is honest and machine-verified. PORT_STATUS does not overclaim: it lists what diverges and why, and each entry names the file/function. HANDOFF appears to be kept current (the test passes). The only gap: PORT_STATUS does not document the `isMetadataOnly` dead branch in `BaseChatModel` or the `Completions::encode()` unreachable path — both were reviewed and judged harmless, but a reader wondering "are there other unreachable paths?" cannot tell from the docs alone whether those are the only two or merely the only two found.
**Suggested change:** Add a "Known Dead Code" section to PORT_STATUS listing the reviewed-and-accepted dead paths (isMetadataOnly AIMessage branch, Completions::encode unreachable, JsonPlusDecoder Set/Map decode branches with justification). This prevents future reviewers from re-finding them and signals that the surface has been swept.

---

### 8. dead-code
**Verdict:** sound (with accepted orphans)
**Evidence:** Previous reviews examined and rejected four dead-code claims: (1) `LangChain\Utils\Testing` in src/ — upstream ships it too; (2) `JsonPlusDecoder` Set/Map branches — needed for JS-written checkpoints; (3) `isMetadataOnly` dead AIMessage branch — unreachable but fall-through is correct; (4) `Completions::encode()` unreachable — fixed anyway.
**Finding:** Three categories of dead-ish code exist, all justified: (a) **testing utilities in src/** — matches upstream packaging, not an accident; (b) **decode-only branches** (Set/Map in JsonPlusDecoder) — load-bearing for cross-language checkpoint compatibility; (c) **truly unreachable branches** (isMetadataOnly AIMessage, Completions::encode) — one fixed proactively, one left because deletion has no behavioural test to pin it. No orphaned files or parsed-then-dropped subsystems were found.
**Suggested change:** Delete the `isMetadataOnly` dead AIMessage branch with a comment explaining why (`AIMessageChunk` is the only concrete chunk type and it declares `toolCallChunks`). Even though it cannot be mutation-tested (unreachable code has no failing test), its presence forces every reader to reason about it — and two reviewers already wasted time doing so.

---

## Cross-Cutting Analysis

### A. Composition — Two User Journeys

**Journey 1: Bind tools to ChatAnthropic, stream inside a StateGraph with SQLite checkpointing**

Trace:
1. `new ChatAnthropic(['api_key' => '...'])` → `Provider Clients` layer ✓
2. `$model->bindTools([$tool])` → `Core Abstractions` (`BaseChatModel::bindTools`) ✓ (fixed: now converts tools correctly)
3. `StateGraph::create($schema)` → `LangGraph Engine` ✓
4. `$graph->node('agent', $model)` → registers model as node ✓
5. `$graph->compile(['checkpointer' => $sqliteSaver])` → returns `Pregel` ✓
6. `$graph->invoke($input, ['configurable' => ['thread_id' => 't1']])` → `Pregel::invoke()` ✓

**Seam status:** This journey works end to end. The three seams that historically broke it are all closed:
- **Tool conversion**: Fixed in iteration 26 (glm audit #2) — constructor and per-call paths now convert, not just bindTools.
- **Checkpoint serialisation**: Fixed in iteration 28 (serde audit #1) — `channel_versions` serialises as `{}` not `[]`.
- **Stream error in graph context**: Fixed in iteration 29 (opus audit #1) — abandoned stream closes trace run via `finally`.

**Journey 2: Structured output pipeline — ChatOpenAI → withStructuredOutput → JsonOutputParser → invoke**

Trace:
1. `new ChatOpenAI([...])` → `Provider Clients` ✓
2. `$model->withStructuredOutput($schema)` → returns `Runnable` (composition layer) ✓
   - Documented limitation: function-calling only, no jsonMode/strict
3. `new JsonOutputParser()` → `Tools + Parsers` ✓
4. `RunnableSequence::create([$structured, $parser])` → `Composition` ✓
5. `$chain->invoke($query)` → walks sequence ✓

**Seam status:** Works, with one documented gap: `Schema::object()` accepting nested `Schema` instances was broken (deploy-audit #2) and fixed, but only after external installation testing found it. The unit suite never built `Schema::object(['a' => Schema::string()])` — every test used plain arrays. This journey would have failed at step 2 if the user wrote natural code, despite 2148 green tests.

**Verdict:** Both journeys work now. The first survived three audit cycles of seam-fixes. The second survived one external-installation catch. Neither dead-ends at a layer boundary today.

---

### B. Layering Violations

**Finding: None detected.**

The evidence is consistent:
- Section 3: "LangGraph -> LangChain: 15 files" —单向依赖。
- 命名空间清单（第 2 节）：没有 `LangChain.*` 文件导入了 `LangGraph.*`。
- 之前的咨询（google-gemini-3.5-flash）独立确认了这一点。
- 架构图显示了 6 个清晰的层，箭头向下指向。

具体检查：
- `StateGraph` 是否知道 `ChatOpenAI`？不知道。它通过 `Runnable` 接口操作节点。
- `BaseMessage` 是否知道 `Pregel`？不知道。消息层在图表引擎之下。
- `SseParser` 是否知道 `BaseChatModel`？不知道。它生成原始事件；模型类消费它们。
- `HttpClient` 接口是否提到了 `LangGraph`？没有。它是一个通用的 PSR 风格接口。

**结论：** 分层是干净的。图表引擎位于抽象层的顶部，并且正确地向下看，而不是向上看。

---

### C. 记录的诚实性

**发现：记录是真实的，但存在一个文档与代码之间的微小差异。**

`PORT_STATUS.md` 得到 `DocsMatchRealityTest` 的支持，该测试验证了：
- 记录的偏差总数没有被低估
- `HANDOFF.md` 文件数量是最新的
- 套件大小是最新的

**未解决的差异：**

1.  **`HANDOFF.md` 声称当前的套件大小** —— 测试通过了，所以这是真实的 *现在*。但如果有人在添加测试文件后忘记更新 `HANDOFF.md`，测试将会失败。这是一个好的守卫。

2.  **`PORT_STATUS` 没有记录 `isMetadataOnly` 的死分支或 `Completions::encode()` 的不可达路径** —— 这些都在审查中被讨论并判断为无害，但没有被列入表格。未来的工程师可能会重新发现它们（事实上，opus 审计确实发现了 `isMetadataOnly` 这个问题）。

3.  **`PORT_STATUS` 记录“`RunnableInterface::transform()` 文档块声称是逐项流式传输，但实际上是缓冲的”** —— 这是一个 *已修复* 的文档问题，但它是否也记录了 *行为上的差异*？简要说明中提到：“逐项流式传输与上游缓冲之间的差异现在已在 PORT_STATUS 中记录。”所以是的，它已经记录了。

**结论：** 记录值得信任。`DocsMatchRealityTest` 是这里的关键创新 —— 它将文档变成了一个可失败的断言，而不是一个逐渐腐烂的散文。

---

### D. 绿色测试套件隐藏的东西

基于缺陷历史，这里有三个具体的例子，在这些地方，通过的测试套件会给你一种虚假的安全感：

**1. `Schema::object()` 接受嵌套的 `Schema` 实例（deploy-audit #2）**

*为什么测试没有捕获到：* 每个现有的测试都使用纯数组构建属性：`Schema::object(['name' => ['type' => 'string']])`。没有人写过 `Schema::object(['name' => Schema::string()])`。当用户编写自然的、面向对象的代码时，它会发出 `{"a":{"schema":{"type":"string"}}}` —— 一个模式内的模式包装器。`errors()` 方法在内部结构中没有找到 `'type'`，所以它接受了整数、null、数组作为字符串字段的值。

*绿色套件所暗示的：* “Schema 验证对于所有有效输入都能正常工作。”
*实际情况：* “Schema 验证对于 *数组形式* 的输入能正常工作。”

**2. 工具通过构造函数和每次调用路径原样到达网络（glm审计 #2）**

*为什么测试没有捕获到：* 所有测试都使用 `bindTools()` 来附加工具。该路径有正确的转换逻辑（`formatTools()` 或等价物）。但是通过构造函数（`new ChatOpenAI(['tools' => [$tool]])`）传递工具或通过每次调用选项（`$model->invoke($messages, ['tools' => [$tool]])`）则跳过了转换，并发送了一个序列化的 PHP 对象，其中包含 `lc`、`type`、`id`、`kwargs` —— 提供者无法理解的内容。

*绿色套件所暗示的：* “工具在所有配置方法中都能正常工作。”
*实际情况：* “工具在 `bindTools()` 中能正常工作。”

**3. 热切（eager）的 `post()` 重试每个 `HttpException`，无论状态码如何（glm审计 #3）**

*为什么测试没有捕获到：* 流式传输路径具有正确的状态过滤（仅重试 0、429、>=500）。热切路径缺少此过滤器并重试所有内容（400、401、403、404）。测试可能使用了流式传输（已过滤）或非重试场景（未命中重试逻辑），或者使用了可重试的状态码（如 429）。“热切 + 400 + maxRetries=3”的组合产生了 4 个请求而不是 1 个 —— 这是通过实际计算请求数才发现的。

*绿色套件所暗示的：* “重试逻辑是正确的。”
*实际情况：* “流式传输的重试逻辑是正确的；热切路径的是复制粘贴错误。”

**共同主题：** 在每种情况下，测试都执行了多变体功能的 *工作变体*，而损坏的变体保持未被探测状态。这不是覆盖率缺失的问题（这些行 *确实* 被覆盖了）；而是 *路径多样性* 缺失的问题。

---

### E. 单一最高杠杆的改变

**改变：为每个具有多个实现路径的公共方法添加一个“路径多样性”测试矩阵。**

**原因：** 过去 40 次迭代中发现的最后 10 个缺陷中的 7 个都属于同一类别：一个公共入口点有 N 种方式到达核心逻辑，其中 N-1 种方式是正确的，而有 1 种方式是损坏的。测试套件练习了正确的方式并通过了。损坏的方式只在以下情况下被发现：
- 外部安装测试（Schema 嵌套实例）
- 专注于特定子系统的审计（工具转换）
- 审计时明确地 *计数请求*（热切重试）

这不是随机遗漏的问题。这是一种系统性的盲点：单元测试自然地倾向于 *一种* 正确的方式来调用每个方法，而损坏总是潜伏在 *另一种* 方式中。

**具体来说：**

对于 `ChatOpenAI` 和 `ChatAnthropic`，创建一个单一的测试类 `OptionPathMatrixTest`，它数据驱动地提供：
- 配置来源：[`constructor`，`bindTools`，`per-call`]
- 执行模式：[`eager` (invoke)，`streaming` (stream)]
- 错误注入：[`none`，`429`，`400`，`stall`]

这是一个 3×2×4 = 24 种情况的矩阵，并且它本可以一次性捕获工具转换错误、热切重试错误和流停滞错误。

成本：约 200 行测试代码，运行时间不到 1 秒（使用模拟的 HTTP 客户端）。
收益：消除整个缺陷类别。

---

## Findings Ranked by (Impact × Confidence)

| Rank | Finding | Impact | Confidence | Element |
|------|---------|--------|------------|---------|
| 1 | **Path-diversity test matrix for multi-variant public methods** — would have caught 7 of the last 10 defects | **HIGH** (prevents defect category) | **HIGH** (pattern is unambiguous in history) | test-strategy / composition |
| 2 | **`RunnableLambda` silently misroutes config when arity≥2 but 2nd param isn't RunnableConfig** | **MEDIUM** (silent misbehaviour in composition primitive) | **MEDIUM** (inference from code, not yet triggered) | composition |
| 3 | **No dedicated exception type for "this model doesn't support this operation"** | **LOW-MEDIUM** (callers must catch \Exception) | **HIGH** (code shows generic throw) | error-handling |
| 4 | **`Pregel` exposes compilation state as mutable public properties** | **LOW** (faithful to upstream, but hazardous in PHP) | **MEDIUM** (reviewed and rejected, but risk is real) | public-api |
| 5 | **Dead `isMetadataOnly` branch forces repeated reviewer effort** | **LOW** (cosmetic, but wastes auditor time) | **HIGH** (already happened twice) | dead-code |
| 6 | **`batch()` accepts-and-ignores `$options` on interface** | **LOW** (honest but traps consumers) | **HIGH** (explicitly documented) | public-api |
| 7 | **PORT_STATUS missing "known dead code" section** | **LOW** (documentation completeness) | **MEDIUM** (would prevent re-finds) | documentation |

**Overall system verdict: SOUND** — with the test-strategy gap (Rank 1) being the principal unresolved risk. The code is honest, layered, and faithful. Its weakness is that its test suite exercises one path per method where the implementation has three, and history shows the unexercised paths are where the landmines are.
