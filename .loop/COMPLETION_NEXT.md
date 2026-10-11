# Completion continuation after Waves 0–5

Started 2026-10-09. This is the active continuation of
[`COMPLETION_PLAN.md`](COMPLETION_PLAN.md); retain the earlier plan as history.
The previous plan finishing did not finish the port.

## Baseline and scope

- Main and origin/main: `8a588b040d943fe8d7d42fef8fad05d7a0624340`.
- Fresh full suite: exit 0, 16,972 tests, 564 skipped, 40,538 assertions,
  45.235 seconds. The assertion count differs from the handoff; use fresh
  measurements when integrating, never hand-edit a test count.
- Read-only LangChain JS snapshot: `17b6bba8343bdd3f3dfc5c59578e0b72337ec740`.
- Read-only LangGraph JS snapshot: `2478e098ae1c2f7f353dfd8d6ff32ae7c20444a8`.
- Never modify `/Users/shoemoney/Projects/agentdesk`, including installing
  packages in its reference checkouts. Copy small oracle inputs into this
  project's build directory if execution needs generated files.

Completion is assessed against these pinned snapshots and the previous plan's
runtime scope. Existing PHP adaptations need evidence and explicit disposition;
a partial flag is not itself an implementable specification. Browser/framework
bindings, UI/toolchains, excluded provider families, and langchain-classic stay
outside this scope. Do not describe a scoped completion as a completely ported
upstream ecosystem. Concrete loaders, memory classes and vector backends from
excluded packages cannot silently become either completed or required.

The old plan excluded SDK thread-stream internals, and HANDOFF records that
public API/projections as deliberately dropped. This continuation reverses only
the runtime portion needed by the already in-scope RemoteGraph: upstream
`pregel/remote.ts` calls `client.threads.stream`, and `remote-run-stream.ts`
imports ThreadStream. N21 restores that HTTP/SSE runtime dependency, excluding
media Blob/objectURL helpers, useStream/controller and ui-internal. WebSocket is
required only if an actual RemoteGraph source call needs it. Preserve PHP's
generator contract; do not invent a browser UI.

## Definition of done

Every in-scope missing API or behavioral gap must have: an owning package,
upstream source/test evidence, converted tests, and a final disposition. A package
is complete only after its patch is reviewed, integrated and the integrated tree
passes. Branch-only success is not landing. Synthetic transformer inputs are
unit coverage, not proof that real agents emit nested events.

Accepted adaptations (for example, UTF-8 cannot hold lone UTF-16 surrogates,
PHP cannot declare conflicting static/instance methods, synchronous task ordering)
remain visible. Do not classify fixable signal propagation, missing schemas,
missing callbacks or absent public methods as language limitations by default.
Pin chosen adaptations with tests; audit whether changing them is required before
altering established contracts.

Live verification is a separate gate. A fake-backed pass never establishes that
a real database, model endpoint, OAuth flow or remote server works. Tests requiring
unavailable credentials/services remain pending, with prerequisites identified.
Do not make billable model requests without an authorized credential/budget lane.

## Execution and integration rules

1. At most three implementers, each in an isolated `.todo-worktrees/<package>`
   checkout. Give each an explicit file allowlist. They are not alone; never
   revert another agent's edits. Serialise packages sharing files.
2. Workers own source/tests only. The integrator owns `HANDOFF.md`,
   `PORT_STATUS.md`, `README.md`, `composer.json`, CI and `.loop/`.
   Workers report proposed ledger changes instead of editing those files.
3. Each worker installs its own vendor directory, lints changed PHP files and
   runs bounded targeted tests, accepting only exit zero with no unexpected
   skips, warnings or risky tests. No shared composer autoloader or cache.
   Do not run many full suites in parallel; that previously caused hangs and
   load-dependent failures. The integrator runs full suites serially.
4. Tests, PHP subprocesses, fixture servers, count measurement and lint all have
   explicit timeouts. On timeout preserve diagnostics, terminate only owned
   process groups and report failure. Never kill unrelated user processes.
5. Match upstream using executable oracles where practical. Reproduce reported
   defects before changing code. If upstream behaves identically, report that
   evidence rather than inventing a new API to make a test pass.
6. Review each wave independently (the earlier plan requests an Opus pass).
   Supply diffs, upstream evidence, converted/skipped describe blocks, commands,
   outcomes, remaining objections and package dependencies. Rejected changes
   return to their owner. Review rounds each get a new delegated task.
7. Integrate only owned patches onto a candidate based on current main; verify
   base SHA and changed-file allowlists. Reject unexpected paths. Never blindly
   merge stale sibling assumptions. New API dependencies must already be integrated and verified. Where commits are prohibited, copy only an explicitly allowlisted verified dependency snapshot into the worker checkout, record its hash, and keep overlay files separate from the new patch.
8. Run bounded `sync_docs.py`, full PHPUnit, composer validation, source syntax
   checks and CS fixer. Each wave also gets a best-effort PHP 8.2-targeted parser
   check; its parser can accept newer syntax, so it is not an 8.2 compatibility pass. Actual 8.2/8.3/8.4 execution remains pending until matching runtimes or
   authorized CI are available; a local pass is PHP 8.5 only.
   Re-run a failed load-shaped check only with evidence;
   a retry is not permission to ignore a consistent failure.
9. Update ledgers from the integrated result. Record completed, accepted adaptation,
   excluded and verification-pending separately; remove superseded stale prose.
   No unrequested commit, push, release, Packagist publication or external send.

## Dependency-ordered work packages

Paths below are relative to this repo. LC/LG/PV refer to the read-only source
prefixes documented in the previous completion plan. Every implementation brief
must narrow the owner paths to exact files before dispatch.

### Wave 6 — independent regressions and verified diagnoses

| ID | Package and owner territory | Acceptance | Dependencies |
|---|---|---|---|
| N01 | Tool-run naming; `Tools/StructuredTool`, both LangGraph ToolNodes, `Agents/ReactAgent`, three stale transformer docblocks | Direct invoke/call, both ToolNodes and real v3 agent stream use tool names; explicit names respected; remove anonymous subclass only after base behavior works | baseline |
| N02 | Partial JSON parser; `OutputParsers/PartialJsonParser`, parser/JSON utility tests | Converted truncated-literal, lone-minus and partial-unicode-prefix cases match upstream; malformed complete JSON still fails; preserve surrogate-pair contract | baseline |
| N02b | Remaining numeric/whitespace parser parity; PartialJsonParser and exact JSON utility owners | Prevent oversized integer saturation in partial inputs; compare Unicode whitespace; record native json_decode negative-zero convention explicitly | N02; same parser owner after review/integration |
| N02c | Parser markdown/UTF-8/performance follow-up; JsonUtils and PartialJsonParser | Match markdown NUL trimming through the public output-parser path; preserve invalid-UTF8 fallback behavior and replace per-character mb_substr bottleneck with bounded large-buffer regression | N02b |
| N02d | Parser memory follow-up; PartialJsonParser and large-buffer regression | Preserve linear scan and all byte/substitution semantics with compact indexing; reviewer measured 134MiB extra peak for a 2.1MB truncated array, which can exceed a 128M PHP memory limit; verify constrained-memory behavior in a separate process | N02c; same parser owner after combined verification |
| N03 | MCP elicitation retry diagnosis; `Mcp/Elicitation`, elicitation regression tests | Reproduce malformed/refused answer followed by corrected second resume on same thread; compare upstream replay semantics; fix only demonstrated port divergence | baseline |
| N04a | OpenAI custom-tool reconstruction; `Messages/AIMessageChunk` and Responses delta/stream tests | Raw custom input folds into `args['input']` in a valid final message, not an invalid JSON call; fake-transport streaming integration | baseline |
| N04b | xAI streaming usage; `Chat/XAI/ChatXAIResponses` and response tests | Streaming aggregation uses upstream `estimatedTokenUsage`, with correct PHP usage shape | baseline |
| N04d | Azure request matrix; `Chat/OpenAI/Azure` and specific Azure tests | Verify deployment/base URL/apiVersion combinations against pinned upstream SDK semantics; repair only demonstrated failures | baseline |
| N05 | Dedicated backend verification harness; CI/test-service configuration | Disposable unique databases/key namespaces, readiness queries, bounded cleanup and log capture; no broad deletion against user's existing services | baseline; integrator-owned |
| N30 | SDK boundary aliases/signals; `Sdk/BaseClient`, `AssistantsClient`, `Utils/Signals` and remote error tests | Public `getGraph` alias, caller/timeout signal merge, remote malformed/error/abort coverage; integrator retires superseded guard/ledger rows | baseline; coordinate N22 test ownership |

N01, N02 and N03 are the first dispatches. N03 is a diagnosis gate: a correct
upstream replay behavior may close as an evidenced adaptation, not a code fix.

### Wave 7 — retrieval foundations and production persistence

| ID | Package and owner territory | Acceptance | Dependencies |
|---|---|---|---|
| N06 | Document IDs and memory vectorstore; `Schema/Document`, `VectorStores/MemoryVectorStore`, indexing/selectors tests | Optional supplied IDs survive memory-vector add/filter/search/MMR; preserve serialization. Index hash IDs remain its separate existing contract; do not invent MemoryVectorStore ID generation/options.ids | baseline |
| N07 | Few-shot prompts; new `Prompts/FewShot*` classes and missing selector cases | Convert upstream few-shot prompt tests and previously missing selector cases; string/chat formatting and callable partials | baseline; independent of N06 |
| N07b | Few-shot exactness follow-up; chat example missing fields and serialization kwargs | Compare missing-variable JS undefined semantics and supplied-only kwargs; resolve fixable gaps or explicitly tested adaptation; selector load belongs to N19 | N07 |
| N08 | MongoDB production adapter and saver construction; `Checkpoint/MongoDB` | Optional `mongodb/mongodb` adapter and pinned borrowed-client constructor/setup through realistic fake seam; pinned saver has no fromConnString/end API and PHP driver has no close, so convenience factory/shutdown extensions are not required parity; real saver specs remain N28-persistence pending | N05; dependency changes integrator-owned |
| N09 | MongoDB store construction/lifecycle; `Store/MongoDB` | `fromConnString`, owned lease release, borrowed-client preservation, date decoding and setup-failure tests; socket/native/live/Atlas evidence remains N28-persistence pending | N08 |
| N10 | Redis cluster adapters and store; `Checkpoint/Redis/PhpRedisClient`, `Store/Redis` | `fromCluster`, routing/auth/error behavior and deterministic fake cluster tests; genuine multi-node cluster validation remains N28-persistence pending | N05 |
| N10b | Redis diagnostic follow-up; PhpRedisClient and narrow adapter tests | Preserve actual standalone FT server error text; delegate non-warning diagnostics to the caller error handler and restore it on all exits | N10 |
| N15 | Shared schema validator; new core `Utils/JsonSchemaValidator`, MCP compatibility delegate and tests | One core validator used by LangChain and LangGraph without layering violation; preserve MCP behavior and schema errors; record standard-schema disposition | baseline; sole validator owner |
| N30b | SDK streaming cancellation and signal types; StreamsWithRetry/StreamRetry and SDK phpdocs | Poll caller cancellation before streaming-transport connection retries; preserve deliberate stream timeout policy; make callable signal contract explicit and test zero/invalid signals | N30 |
| N21 | RemoteGraph-required ThreadStream runtime; `Sdk/ThreadsClient`, new `Sdk/Stream` | HTTP/SSE `/threads/{id}/stream/events`; values/messages/subgraphs/output/interrupt projections, subscribe/unsubscribe, run.start, close, threadId and extensions; exclude media/UI/controller | N30b; existing LangGraph Stream layer |
| N21b | ThreadStream execution limits; SDK stream transport/subscriptions | Review pull-driven progress, synchronous retry/watchdog limits and unbounded buffer policy; preserve tested terminal byte-boundary disposition; headless UI interrupts remain outside RemoteGraph runtime scope | N21; after reviewed RemoteGraph-required slice |

Mongo live provisioning uses an isolated server (local runtime or CI service),
plus the optional PHP extension/library. N05 must remove fixed-name destructive
cleanup before any live run. Driver/server absence blocks verification, not
fake-backed implementation. CI service execution needs an authorized push lane.

### Wave 8 — config, engine events and provider helpers

| ID | Package and owner territory | Acceptance | Dependencies |
|---|---|---|---|
| N11 | Runnable config factories/listeners; `Runnables/RunnableBinding`, base Runnable | `withListeners` start/end/error and per-call config factories, isolation/merge precedence; bound tool name/dispatch preserves underlying identity; absent fields in caller config objects must not erase bound tool defaults | baseline; sole config-merge owner |
| N12 | Engine stream event feed; `Pregel/Pregel`, `PregelLoop`, runner/scheduler, stream helpers | Real `tasks`, `checkpoints`, `custom` payloads (including Func custom mode); derive debug payloads from shared event builders and preserve existing debug fixtures byte-for-byte; preserve namespace provenance through nested graphs; modes/subgraphs/error/interrupt/resume regression matrix | N01; exclusive engine owner |
| N14a | Anthropic pure helpers; new provider utils/prompts/extractGeneratedFiles | Pure prompt conversion and generated-file-ID extraction reuse existing converters; no fetch/download API invented | baseline |
| N14b | Anthropic model/beta validation; narrow ChatAnthropic/tool owner | Match pinned upstream per-model beta/options checks and request headers; retain already-fixed bound cache_control wire behavior | baseline; serialise shared provider files |
| N14c | Anthropic default max-token heuristics; ChatAnthropic and Params | Convert pinned model-dependent defaults and distinguish explicit 1024 from absent constructor default | N14b |
| N31 | Middleware numeric options and deterministic retry timing; retry middleware and Constants | Match explicit-null validation per option (some limits legitimately allow null); inject clock/sleep seams and replace load-sensitive upper-bound assertions | baseline; serialise N13 overlaps |
| N31b | Limit nullable/coercion disposition; ToolCallLimitMiddleware and focused cases | Source compares either present null cap numerically as zero; distinguish omission, preserve ordering/accounting/diagnostics/reset, and disclose supported PHP integer, float or null versus other JS coercions | N31 |
| N18 | Ambient config, Fiber context and cooperative cancellation; AsyncLocalStorage, context/config utilities | Fiber isolation and ambient ensureConfig; cancellation/timeout merging pinned to PHP execution contract; use existing engine event semantics | N11, N12; same config/engine owner; subsequent consumers are serial |
| N18a | Fiber context isolation and task publication; AsyncLocalStorage, Await, PregelScratchpad | Interleaved actual graph runs retain independent ambient config, scratchpad, writer and task input; managed Fibers capture creation-time context and restore after errors | N11, N12 verified; six-file first slice in source-plan-N18.md |
| N18b | Local child graph context/default modes; Pregel, Utils/Config | Imperative and declared children inherit correct task nesting and caller precedence; fresh explicit-thread roots discard stale ambient data; parent/child subscriptions stay distinct | N18a reviewed, integrated and verified |
| N18c | Core ensureConfig and lazy runnable context; core config/runnable owner | Preserve LC overwrite rules, run identity exclusion and per-resume generator scopes; distinguish LG composition | N18a, N18b; coordinate N16 |
| N18d | Cooperative cancellation/deadlines; core signal utility and engine owner | Normalize once, preserve reasons and minimum deadlines, poll before attempts; no preemptive synchronous-I/O claim; distinguish external cancellation/step timeout | N18c; N20b owns error-handler/timeout task wiring |
| N04f | Azure remaining SDK/request gaps; narrow Azure owner | API-version required/fallback/null-query disposition, deployment encoding, token error wrapping and per-call timeout/retry/signal evidence; no live-provider claim | N04d; cancellation depends on N18 |
| N04c | OpenRouter structured validation; `Chat/OpenRouter/ChatOpenRouter` and output tests | Invalid-output/includeRaw cases use shared core validator; no LangChain import of LangGraph | N15 |
| N04g | Structured-output streaming and parser error contracts; provider/output-parser composition owner | Reproduce inherited partial JSON stream failures in OpenRouter/Ollama, compare pinned provider methods, preserve includeRaw single-request/error semantics and malformed JSON exception disposition; add chunked fake-transport matrix | N11, N15, N02b; serialise shared Runnable/provider owners |
| N22 | RemoteGraph projections/nested metadata; `Pregel/RemoteGraph`, `RemoteRunStream` | Adopt reviewed ThreadStream runtime with exact raw/projection delegation, cancel/close, normal stream hook and nested config metadata; common-pull cancellation remains N21b | N21, N30; remote owner is disjoint from local engine |
| N22b | RemoteGraph task/snapshot fields; task description and remote snapshots | Source error/state/result, nested state/checkpoint references, scalar values and interrupt_id mapping; preserve legacy output compatibility | N22; separate approved ownership |

Tokenizer and generic standard-schema interoperability are extensions absent from
the original in-scope utils list. Inventory them in N15, but do not declare them
ported or make an optional tokenizer backend a hidden mandatory dependency.

### Wave 9 — actual nested streaming and core completeness

| ID | Package and owner territory | Acceptance | Dependencies |
|---|---|---|---|
| N16 | Runnable tracing coverage; `Runnables`, `Retrievers/BaseRetriever`, tracer/callback handlers | Nested invoke/overridden stream/parallel/retriever/custom-event traces carry correct ancestry and termination; converted upstream stream-events/log tests | N18; same config-merge owner after N11/N18 |
| N17 | Real agent v3/nested transformer integration; `Agents/ReactAgent`, transformers and Stream | Supervisor, parallel and nested upstream tests run through real `streamEvents`, not `RunStream::fromSource`; cancellation, interrupted output and message leakage checks | N13; follows N12/N18 |
| N31c | Summarization nonnumeric null validation; exact summarizer option owner | Reject explicit null trigger/keep/tokenCounter/summaryPrompt/summaryPrefix as upstream factory does; compare complete issue paths/codes | N31 |
| N19 | Serializable load/import registry; `Load`, explicit import constants/map | Constructor/secret round trips for shipped classes, unknown/excluded imports fail honestly; no user-controlled arbitrary class loading | N07 for new FewShot registry entries only |
| N19b | Serializer literal escaping and registry completeness; Serializable/serializer owners | Emit/honor source escape markers without treating literal lc records as constructors/secrets, including message/tool args; exposure gate before any runtime Load consumer; inventory every shipped serialized id and close unsupported in-scope factories | N19; coordinate serializer consumers |
| N19b-E | Shared literal escaping; Load/Validation, seven serializer paths, Azure aliases, checkpoint decoding and stored/remote boundaries | Apply pinned escape rules before trusted markers and aliasing; prevent literal revival in checkpoint escape wrappers, preserve repeated round trips, typed PHP JSON values and supported depth; stored unescape is an explicit PHP adaptation and remote wire escaping matches source | N19, N07; verified shared escaping boundary; expanded16-file repair |
| N19b-A1 | ToolMessage artifact serialization; ToolMessage, MessageUtils and focused tests | Preserve originally supplied artifact keys and current values; trusted/stored/checkpoint round trips and provider wire exclusion; PHP stored unescaping remains explicit | N19b-E verified |
| N19b-P1 | PII ToolMessage artifact source disposition; one new actual-pipeline regression test | Verify each middleware against its matching pinned source through actual pipeline/checkpoints; preserve intentional modern/deprecated artifact distinctions | N19b-A1 verified; one new test approved |
| N19b-A2 | ToolMessageChunk artifact serialization/conversion; chunk owners | Constructor/toDict and toMessage preserve artifact; stored chunk promotion source-backed; closed checkpoint chunk registry remains N27 | N19b-A1 verified; exact three-file source plan approved |
| N19b-K | Selector serialization disposition; Serializable, BaseExampleSelector, ImportConstants | Match non-serializable upstream selector disposition; no invented load factory; classify existing emitted ids | N19b-E |
| N19b-S1 | Provider secret plumbing; core/OpenAI/Anthropic/OpenRouter owners | Source-derived secret markers and alias precedence with provider-family fixtures; retain runtime-only-field rejection | N19b-E; sole shared serializer owner |
| N19b-S2 | Provider variant secret disposition; xAI/Together/Fireworks owners | Compare deliberate upstream key/configuration deletion and aliasing per family; no blanket secret injection | N19b-S1 |
| N20a | Core cache/content remainder; affected base models and fake-model tests | Cache content-v1 and usage metadata semantics, block-content echo behavior, converted cache cases | N18; exclusive BaseChatModel/BaseLLM owner |
| N20b | Graph lifecycle/handler tasks; StateGraph, Pregel engine, StateSnapshot | Actual graph callback assertions, separately checkpointed error-handler tasks and attribution, nested task state snapshots, timeout policy wiring; rerun N12 full event matrix | N18; same exclusive engine owner after N12/N18 |
| N20c | Cache facade and similarity helper dedupe; graph Cache and StoreUtils | Source-compatible AsyncBatchedCache equivalent; StoreUtils cosine uses existing MathUtils implementation; preserve synchronous contract | N18 |
| N13 | Agent runtime/middleware context; Runtime, middleware nodes and affected middleware | Writer/interrupt injection, callbacks/tags/metadata and structured validation; preserve nostream and model lifecycle on context edits; consume shared config semantics without editing config merge | N18, N15 |
| N23 | Supervisor remote paths/discovery; `Prebuilt/Supervisor` | Remote-agent branches and graph-based handoff discovery; do not invent a remote Swarm API | N22 |
| N04e | Ollama structured validation; ChatOllama and schema output tests | Supported JSON Schema reply validation and includeRaw failure cases use core validator | N15 |

### Wave 10 — MCP runtime

| ID | Package and owner territory | Acceptance | Dependencies |
|---|---|---|---|
| N24 | MCP SSE and fallback; transport layer, connection manager | SSE legacy transport, HTTP-to-SSE fallback, session/protocol/error handling with real local fixture servers | N03 |
| N25 | MCP resources/listen/cache/validation; McpClient/MultiServer client | Resource templates/subscriptions/listen, result-type validation using shared core validator, cache modes and invalidation/reconnect/error behavior | N24, N15; serialise client owners |
| N26 | MCP OAuth/provider auth; connection/auth components | Source-derived auth/provider contract, PKCE/refresh/challenge and injectable credential storage; fixture auth server coverage; real third-party credentials/auth remain N28-auth verification pending | N24, N25 |

### Wave 11 — closure, real boundaries and release candidate

| ID | Package and owner territory | Acceptance | Dependencies |
|---|---|---|---|
| N27 | Exhaustive in-scope source/API/test reconciliation; ledger and package follow-ups | Every partial/not-started/non-exact/known-absent row maps to an outcome; class/method and upstream describe-block inventory; no stale Wave 4/5 claims, no untested orphan APIs | all implementation packages including N30/N31; split by Core, Graph/Pregel, Agents, Providers, SDK/MCP owners |
| N28 | Standard-provider and real-boundary verification; test harness and adapters | Fake HTTP contract matrix for every shipped provider; isolated DB specs; bounded fixture MCP + third-party/hosted checks only with actual prerequisites; mermaid image fetch and optional backend checks | N05, N27; split persistence, each provider family, SDK/Remote, MCP/auth and drawing into independent verification reports |
| N29 | Integrated candidate review and compatibility matrix; docs/CI/package metadata | PHP 8.2/8.3/8.4 tests+lint, additive package compatibility, public examples and documented limitations; independent review closes all objections; publish only when explicitly authorized | N28 |

N27 may discover genuine gaps the old ledgers missed. Add narrowly specified
packages rather than weakening the completion definition. Date estimates are
withheld until the first implementation/review cycle measures package throughput;
waves group dependency lanes, not promised calendar duration or strict barriers. Independent baseline-only packages may start early; packages sharing files or new APIs wait for their gates.

### Closure subpackages

N27 is dispatched as N27-core, N27-engine, N27-agents, N27-providers and
N27-sdk-mcp, each owning only its source/API/test inventory and proposed ledger
deltas. N28 is dispatched as N28-persistence, N28-openai, N28-anthropic,
N28-compatible-providers, N28-sdk-remote, N28-mcp-auth and N28-drawing. Each
records actually executed boundaries and unmet prerequisites separately. N29
requires every report, with live verification pending never presented as a pass.

## Live ledger and artifacts

`.loop/next/run.json` tracks package states, ownership, task IDs, candidate paths
and gates. `.loop/next/reports/` stores read-only audit and implementation evidence.
The first patches are prepared in isolated worktrees; this document does not mark any new API ported.

## Audit evidence and current prerequisites

At the continuation baseline, three read-only audits checked actual PHP and pinned upstream source, not only
the ledgers. Evidence highlights:

- At that baseline, `Pregel.php` stripped subgraph namespaces and lacked tasks/checkpoints/
  custom modes. Three SubagentTransformer tests replay synthetic engine chunks.
- `AIMessageChunk::parseToolCalls` ignores custom-tool semantics; xAI streaming
  output still exposes OpenAI tokenUsage. Upstream xAI plain-text assistant replay
  and structured-output refusal also exist upstream, so they are not new defects.
- Document lacks IDs; memory vector search rebuilds documents without identity.
  Few-shot prompts are absent. These are distinct, independently implementable
  packages; FewShot does not depend on Document IDs.
- Mongo has only test adapters, no installed extension/library/server, and no URI.
  Existing Mongo tests drop a fixed database. Redis module commands and a real
  Postgres query work locally, but current integration tests delete fixed prefixes/
  indexes. Do not enable them against those services until N05 isolates them.
- Docker CLI is installed but its daemon is unavailable. No live provider/LangGraph
  endpoint credentials are present in this process. Presence was checked without
  exposing secret values.
- N03 established MCP same-thread malformed-answer positional replay matches upstream;
  a second resume does not replace the consumed answer. Regression tests retain it.
- `sync_docs.py` itself has no subprocess timeout; historical external landing
  scripts are not present here. Bound it explicitly. Also verify nonzero
  warning/risky exits, not only JUnit failures/errors.

## Execution checkpoint — 2026-10-09

52 bounded groups are independently reviewed, integrated and locally verified in the uncommitted working tree. The canonical checkpoint registry joins the serializer and provider variants. Host PHP8.5.11 passes 20,437 tests / 59,425 assertions / 574 environment skips, plus syntax/style for 1,351 files, target8.2 parsing, strict Composer and diff checks. Full current52 native PHP8.2/8.3/8.4 optional-off/on, syntax/style/platform and strict Composer checks pass; offline live-backend/provider limits remain. No commit, push or release.

N05's integrated live Redis saver/shallow/store/ownership contracts passed on a disposable process: 584 tests / 1840 assertions / zero skips; the owned process stopped afterward. Reviewed bounded native MongoDB acceptance passed for the batch43 input; Atlas and full current-candidate acceptance remain open. Providers have fake HTTP coverage only. N10 fake cluster coverage is not multi-node verification.

N11 round2 and N02c round1 are independently approved and integrated; combined count synchronization and separate final direct run passed. N11 absent-API ledger rows are retired without weakening the guard; the existing dispatchCustomEvent gap is now executable in that table. N11 nested tracing/config-array batches remain N16; callback/public-default presence and TracedNode/untraced-writer batching remain N27. N02c matches pinned-source markdown whitespace and preserves malformed-byte semantics; reviewer measured134MiB extra peak for2.1MB, so compact indexing is N02d. N21 round2 review checks repaired unsolicited protocol error handling, partial message error completion and immutable late-reader message handles; the same owner also repairs raw queue iteration, subgraph field guards and no-sequence terminal resume. N18 source planning has reproduced Fiber leaks and child configuration losses; N18a Fiber isolation is approved and integrated; N18b/c/d retain separate gates. N19b-E expanded16-file repair is independently approved and integrated: literal checkpoint preservation, Azure alias escaping, PHP-native JSON values and supported depth are covered; N19b-A/K/S1/S2 are distinct dependent scopes. N10b diagnostic refinements are independently approved and integrated; combined counts and the final direct run pass.

N19 is approved and locally verified for trusted manifests only. N19b-E shared literal escaping is integrated; artifact, selector and provider-secret evidence plus the final N19b exposure audit still gate message/tool runtime consumers of Load. N19 inventory classifies73 emitted classes/66ids, including44supported factories; remaining literal/serializer/runtime-state and empty-dictionary gaps belong to N19b/N27. The secret-marker variants require per-family evidence because pinned providers deliberately delete some inherited keys. N02c owns markdown NUL trimming, invalidUTF8 fallback and large-buffer parser performance; N04g owns inherited structured-output streaming failures. Complete JSON numeric precision/negativezero and native schema validation adaptations remain explicit. Verified dependency overlays are hashed and excluded from new patches.

N12 round2 APPROVE: interrupt/resume PUSH starts/results and legacy RunStream subscriptions are repaired. PHP scalar stream pairs/namespace triples and synchronous Func interleaving remain explicit adaptations. Actual-source oracle covers13scenarios/77chunks with all payload multisets/errors and12ordered traces; the paused Func timing difference remains documented. N18 owns child graph ambient/default modes, Fiber isolation and meaningful late-writer/child sink closure tests; N17 consumes those semantics. N20b owns remote parent-stream forwarding. Debug fixtures remain byte-compatible.

N10 round2 APPROVE: false FT replies throw, stale Search coordinator resets after failures, driver warnings normalize to exceptions and the next call reselects without automatically replaying side effects. N10b preserves actual standalone error text and the previous handler for other diagnostics. Raw JSON MOVED/ASK, partial/fatal native KEYS and borrowed OPT_PREFIX limitations remain documented. N28-persistence requires real multi-node RedisJSON/Search, auth/TLS, redirects, failover, resharding (including a coordinator losing all slots) and lifecycle.

N10b round1 APPROVE: standalone FT exceptions preserve server text and clear it after reading; non-warning diagnostics delegate with exact return behavior, and handler restoration survives exceptions. Two low residuals belong to N27: a pre-existing stale standalone lastError may be used by the defensive bare-false path, and PHP cannot recover the previous handler's severity mask, so delegated diagnostics may exceed its original selection. Real cluster boundaries remain N28.

N21 is approved and integrated. Its reviewed residuals include pull-driven progress, synchronous reconnect/backoff, inability to interrupt blocking reads and unbounded buffers (N21b). N22 must use extension(name) rather than an early empty property snapshot, and retain caller-owned runs.cancel plus close. Full ChatModelStream/AIMessage usage_metadata remains N20a. The pinned run.start server responds synchronously; 202/204 command responses remain explicitly rejected. WebSocket/media/UI exclusions remain scoped in N27.

N21 round2 APPROVE: protocol error observers, partial message errors, stable late-reader handles (including anonymous starts), single-queue competing readers, malformed subgraph field guards and sequence-less resume are independently tested. N21 combined synchronization and final direct verification pass. N21b also owns the low callback re-entry finding: closing the whole thread in onError currently advances a cleared source; reading the stream from an observer cannot re-enter its running PHP generator. RemoteGraph does not use that observer path. N22 must not claim those callback limitations resolved by its integration.

N18a round1 APPROVE: Fiber-local ambient/config fallback and managed creation-time context pass interleaved shared-graph and unwind probes. Explicit mock-provider graph fallback is same-Fiber only; managed descendants now fail where a leaked global previously succeeded. Parent and managed child share the captured RunnableConfig object while context variables are copied. First non-null withConfig lazily installs the real provider process-wide; main enterWith/context variables can persist in long-lived workers. The engine is synchronous and creates no managed Fibers; raw host Fibers start empty and require explicit entry. N18b graph defaults/composition, N18c lazy runnable scope, N18d cancellation and N17 consumer semantics remain open.

N19b-E round2 APPROVE covers repeated literal checkpoint/provider-wire round trips and deeply nested inputs within the native JSON encoder limit. The checkpoint guard is deliberate PHP hardening: pinned upstream bottom-up JSON revival descends into wrappers. Unregistered records remain stable raw constructor arrays; genuine objects within wrapped literals remain constructor arrays. Historical singleton-marker ambiguity, dropped-field traversal and non-LC Object ids are recorded. Artifact, selector and provider-secret packages remain distinct; runtime Load exposure is still closed.

N08 first five-file borrowed native-driver adapter is independently approved and integrated. Unacknowledged deletes return no available count and still delete both thread collections; fake reads honor native array type maps. Native/BSON/live evidence remains absent. Native driver/BSON tests skip explicitly because optional dependencies are absent, and no live Mongo request was made. N02d compact parser indexing is independently approved and integrated; N31b nullable tool-call thresholds are approved and integrated. N19b-A1 tool artifacts are independently approved, integrated and locally verified. N18b child-graph context, N09 native store/lifecycle is independently approved and integrated pending combined verification; N18b remains isolated and N22 remains under repair after review. N09 round2 source planning and the root lifecycle probe pass; borrowed preservation, original-error cleanup and native date decoding remain implementation/review gates. PHP reachability evidence does not prove socket closure.

N31b round1 APPROVE: independent1452-case supported-type source matrix matches the nullable limit factory/hooks; present null is zero and absence disables the cap. Strings/bools/objects are explicitly rejected PHP adaptations. All-blocked continue without responseFormat matches pinned ReactAgent exit routing and requires no engine repair. responseFormat remains unprobed under N13; absent/null error fields collapse to null and nonfinite/invalid-exit/toolName-null diagnostics remain disclosed.

N02d round1 APPROVE: packed offsets remove the reported128M allocation failure while preserving approved malformed-byte/substitution and diagnostic semantics. Recovery decoding costs more CPU; constructor-time invalid replacement equivalence is verified only on8.5.11 and must be checked on8.2–8.4. The >4GiB index branch is structurally reviewed but unexecuted. The memory contract covers the reported2.1MB array only. Hidden numeric mbstring fallback restoration and opaque captured expected fixtures remain test nits in N27.

N08 round2 APPROVE: production borrowed-client wrappers pass independent realistic driver-shape probes. Object preservation is write-side; reads force associative arrays. Custom collection codec write/read asymmetry and Binary subtype collapse are disclosed. Dynamic metadata is a no-op, default database explicit, WeakReference tests show reachability only. Optional composer suggestions are added; the N05 live fixture switch remains N28-owned before real backend execution, from verified root fixture42790731.

All continuation work remains uncommitted; nothing pushed or released. Live task IDs, owned paths and gates are retained in .loop/next/{run,reviews}.json. Review notifications return here for serial integration.

N19b-A1 round1 APPROVE: supplied-key/current-value artifacts pass repeated trusted, stored and actual checkpoint paths, and provider requests exclude them. The exact three-file patch passes combined synchronization and the separate final direct suite. Reviewer mutation probes killed all five changes. N19b-P1 audits the PII finding: modern and deprecated middleware map to different upstream sources, so no production repair is authorized before concrete source/pipeline proof. Tool producers always supplying null rather than JS undefined remains an explicit N27 byte-shape adaptation; rich PHP object types and A2/chunk registry/empty-object limits remain open.

N22 round1 REQUEST CHANGES: recursive nonplain PHP object conversion changed enum, JsonSerializable, DateTime and ArrayObject wire values. The exact four-file isolated repair must preserve existing json_encode contracts before round2 review; root source remains unchanged for N22. N19b-P1 source disposition is complete: corresponding deprecated and modern upstream middleware intentionally differ, and both PHP paths match. Actual Agent/MemorySaver probes pass; test-only regression coverage is implementing.

N09 round1 APPROVE: exact eight-file store adapter/factory/lease slice is independently approved, integrated and locally verified in the33-group combined candidate. Independent native-shape tests and nineteen killed mutants support cleanup/type-map/date/option semantics. Three surviving mutants and bulk invalid-shape, late metadata-test and destructor/date-value details remain explicit. Native UTCDateTime decoding, real library/server/Atlas and socket closure remain N28; PHP reachability is the only release evidence.

N22 round2 APPROVE repairs enum/JsonSerializable/date/ArrayObject wire conversion. The integrator promotes the newly reproduced circular-input fatal to a required catchable-error repair before integration; r3 is implementing with the same four-file scope.

N19b-P1 root disposition: reviewer R1 compared deprecated PiiRedactionMiddleware with modern pii.ts. Matching piiRedaction.ts preserves artifacts by spread; modern PiiMiddleware already matches pii.ts selected-field removal on changed content. Actual root Agent/middleware/MemorySaver ten-case probe passes. Neither middleware scans artifact PII or erases historical checkpoints. Production repair is unnecessary; one-test regression coverage is assigned.

N19b-P1 round1 APPROVE: the one-test source-disposition package is integrated pending combined verification; actual pipeline/latest/history and seven killed mutation probes support both intentional middleware contracts. The storage-email check documents retained history only, and deprecated output restoration is outside this slice.

Current active gates (2026-10-09 15:28 CDT): N18b round1 identified inherited parent execution options; round2 repairs are being verified. N22 round3 fixed cycles but identified acyclic shared-object memory expansion; round4 must preserve aliasing without bypassing depth checks. N19b-A2 round1 identified post-nullish-concat artifact mutation loss; repair is queued with the explicit-null assignment ambiguity classified for PHP8.2. N28 isolated native runtime bootstrap is authorized and underway in its own profile; two production Mongo fixtures are frozen in review, with no native/server evidence yet. N19b-K source planning is underway.

15:33 CDT execution state: N18b round2 is frozen in independent review. N22 round4 is finishing locked-vendor regression gates; A2 mutation repair follows under the same owner. N19b-K local selector disposition is implementing under exact four-file ownership, without a load factory. Three genuine Linux arm64 PHP8.2.34/8.3.35/8.4.26 images now pass native extension/PDO/BSON readiness checks. The immutable33-group source snapshot is copied; vendors and actual native production Mongo tests/server contracts remain unexecuted. The approved two-fixture migration is withheld from root until native production evidence. No whole N28/N29 completion is claimed.

N18b round2 APPROVE and exactfive-file integration: ambient implicit child options are filtered without caller mutation, explicit/bound child controls are retained, verbose presence and public duplicate tags are preserved. Combined gate pending. Explicit forwarding/declared subgraphs still leak parent invocation fields in pre-existing Algorithm.php:1071; prioritize a separate graph follow-up. Non-task user ambient invocation-option stripping is a PHP adaptation; child verbose inheritance is PHP-only; node tag dedupe remains N16/core. All original N18/N16/N17/N20 limits remain.

15:52 CDT checkpoint: exact34-group root candidate independently reviewed and locally verified (19636tests/50427assertions/572skips; 1333files; separate direct exit0), final docs17/35 pass. N18b-F1 forwarding repair dispatched exact3paths after ten source mismatches. N22r4 independently requests changes for linear decoded-document128M memory fatal; r5 copy-on-write repair assigned same4paths. A2r2 fresh review active with explicit-null-write ambiguity disclosed; K independently approved and held to preserve A2 ImportConstants dependency. Frozen native candidate33 all3actualminor constructor/BSON gates pass4/18/0 each; full82 default128M discovery fatal, then512M run completes19585tests with10 harness failures (missing Python3 and child discovery128M). Validation-only runtime prerequisites repair authorized before root rerun; not a source failure or matrix completion. Live production Mongo saver fails valid root-namespace empty-key metadata_search, store scope unrun, exact server/network cleaned; source-based repair plan underway. All changes remain uncommitted/unpushed/unreleased.

N19b-A2 r2 and N19b-K independently APPROVE; exact3+4 frozen paths serially integrated pending root36 combined verification. A2 captures concat artifact provenance and late values; unobservable explicit-null assignment and getter private-state/loose-equality effects remain PHP adaptations. Falsy concat then unobserved-null mutation gap is recorded for N27; current code independently probed correct. Root union must include LoadTest tool-chunk and root prompt escaping. K local selectors emit not_implemented without kwargs, registry reasons only; no new loader factory. K root-only prompt escape tripwire and generic object/NUL/id limits remain. Native candidate33 remains immutable and excludes these groups.

16:12 CDT stable checkpoint36: root19741tests/51306assertions/572skips; separate finaldirect exit0 in51.167s; syntax/fullCS1334files/strictComposer/diff and docs17/35 pass. Actual Linux arm64 PHP8.2.34/8.3.35/8.4.26 frozenCANDIDATE33 passes SIX full suites (optionalMongo off/on eachminor), native syntax and fullstyleeachminor. Off19585/48467/1099skips; on19585/48472/1097skips; separatefloorcompatible73package lock and validation-onlyPython/inherited512Mprerequisites are explicit, rootignoredlockunchanged. Native33 EXCLUDES N18b/A2/K andlaterrepairs, so current36nativegate remainsOPEN. Exactmatrixcontainersverifiedabsent/profile retainedfor authorizedM1queue/livevalidation. N18b-F1 r1 sourceapproved but B1after-interruptcontrols vacuous; r2testonlystrengthening is frozen in NEWindependentreview. N22r5 ordinaryinput memory repaired but messagebearing30k128Mavoidablememoarray fatal; r6same4scopeassigned, inherentchanged-ancestor/message materializationcostacceptedanddisclosed ratherthanlazyreturn-typeABIredesign. NativeJsonSerializablestdClasssubclasscontractalsorequired. C1normalizeFields primitive finishing exact2scope+5unchangeddeps; no deadline/consumeradoptionclosure. N28-M1exact2saversearchprojection repair finishing nativequeuevalidation, originalBinarypayloadauthoritypreserved andlegacy/mixed-writerlayoutlimits disclosed; live replaystillhelduntilindependentreview. Allworkuncommitted/unpushed/unreleased.

N18b-F1 r2 independently APPROVE: exact3 incremental paths integrated pending root37 union. Parent-after two-node and explicit child GraphInterrupt controls kill both previously surviving mutants. Caller debug remains N27/N16. Native candidate33 excludes F1.

N18c1 independently APPROVE: exact2 fields-only normalization primitive integrated pending next union. No consumer/deadline adoption. Scalar renormalization and runIdParent mutation gaps, normalized presence semantics, and merging defaults after bound runId/verbose hazards are explicit N18c2/N16 requirements.

16:37 CDT stable checkpoint38: forwarded child controls and C1 field normalization independently reviewed, serially integrated and locally verified. Separate final direct exit0: 19794 tests/51729 assertions/572 skips; syntax/fullstyle1336 files/strictComposer/diff and docs17/35 pass. Native foundation remains exact candidate33 only; current38 and later candidates native gate OPEN. N22r6 REQUEST CHANGES for nested nonplain metadata loss and changed-memo test gaps; r7 implementing same4 scope (only2 delta). Shape-dependent eager-copy cost is explicitly measured1.19x shallow rows and>=1.47x deep ancestry, not universal1.2x; slower mapping and native headroom remain. N28-M1r1 production acceptable but B1 test-only native82 near-limit children fail: r2 portability repair/full-owned actual82/83/84 required before new independent review/live acceptance; no source bytes change. C2a exact Lambda+newtest dispatched with7verifiedexcludeddeps after C1 verification; retained PHP ancestry/identity/recursion, existing callback counts and picked publication; broad tracing/lazy/deadline remain. Three T3 implementers active with durable taskIds/briefs; S1 synthetic-source plan read-only. No commits/pushes/releases.

16:42 CDT orchestration state: N22r7 exact4 is frozen and NEW independent review active (source metadata-contract branch+test delta only); both r6 blockers have reported red/green controls, not yet root accepted. M1r2 test-only native portability and C2a exact Lambda adoption remain active isolated T3 implementations; S1 synthetic-source planning remains read-only. Exact taskIds and full briefs retained; review completion auto-returns here. Root38 source remains verified and unchanged. Roadmap desktop/mobile reviewed with18px floor/20px body, native snapshot33 explicitly separate, refreshed inline render saved.

### 2026-10-09T21:55:52.020355+00:00 — normalization-only Lambda and provider marker dispatch

Root remains38 verified slices (19,794 tests, 51,729 assertions, 572 skips). C2a r1 stopped before freeze: picked ambient publication caused25 root graph regressions. R2 is authorized normalization only in the original two files. StateGraph function-node RunnableCallable wrapping is a separate read-only source plan; plain Graph should retain Lambda. S1 source plan is frozen; S1-P exact7 credential-marker/Anthropic alias implementation is dispatched with default-off opt-in and current-root Azure/S2 positive controls. M1 r2 native portability repair and N22 r7 independent review remain active. Three implementers total; root owns serial integration/full suites/native gates. No publication, broad core/provider closure, commits or release.

N22r7 APPROVE and exactfour-file integration pending root39 union. Fresh currentroot38+approvedr7 protocol file passes actual PHP8.2.34/8.3.35/8.4.26:84tests/615assertions/0skips each, production children keep128M. Exact3CIDs absent, source readonly/no network, floor73vendor. T2 nested foreign-metadata recursion assertion gap and N1 global cleanup glob explicitly queued; sourcecode verified correct. Listmetadata L6 and shape-dependent1.19x/>=1.47x eagercopy /~4–7x slowdown remain disclosed. No wholecurrentnative/live/SDK closure.


### Continuation batch 43 — 2026-10-09

Four independently approved bounded slices are now integrated: N18c2a normalization-only (two files), N28-M1 searchable metadata (two), N28-F1 production integration fixtures (two), and N22-T2 recursion/cleanup test guards (one). The combined synchronization passes at 19,948 tests, 52,786 assertions and 574 environment skips; syntax/style and best-effort PHP8.2 parsing pass for 1,339 source/test files. Separate final direct verification passed: build/completion-43-final.xml (exit0, 60.226s wall time). This is local uncommitted work, with no push or release.

Actual scoped native PHP8.2/8.3/8.4 gates pass for normalization (25/104), protocol guards (84/724), and metadata projection (33/212). These are separate approved read-only views; they do not establish a full current-candidate native matrix. The previous complete native matrix covers frozen candidate33 only. Production memory children retain their128M limits.

Reviewed native PHP8.2.34 + MongoDB8.0.32 live acceptance passes for the production saver (455 cases, 2,794 assertions, zero skips), store (29 cases, 100 assertions, ten Atlas conditional skips), and eighteen finite metadata query comparisons. Actual PHPUnit debug skip messages and exact case names establish the Atlas conditions. Binary authority/overwrite and reserved-key collision behavior pass. Legacy and mixed-writer prefix-query mismatches remain explicitly observed; there is no automatic migration or query fallback. Literal dotted-key queries, custom hidden BSON empty-key leaves, Atlas/vector/autoembedding, actual Xdebug headroom, and full current-candidate native/provider acceptance remain open.

The disposable MongoDB server's default1024 file-descriptor limit caused WiredTiger error24 and a fatal assertion in the earlier harness attempts. Only the owned test server limit changed to64000; approved source and fixtures stayed byte-identical. All owned databases were empty before destruction, and the exact three clients, server and private network were removed. Evidence and prior failed attempts remain immutable in .loop/next/reports/live-MongoDB-M1r2-reviewed.json and the owned validation results.

StateGraph wrapping remains unintegrated: its first candidate matches pinned-source duplicate-tag deduplication but exposes a stale PHP-specific node-list assertion. R2 is authorized only to repair that assertion and the wrapper's owned files; mergeConfig behavior and plain Graph/Lambda publication stay separate. Provider credential-marker r1 also remains frozen and unintegrated. Root selected a source-correct model trace constructor-envelope repair and synthetic LoadTest map update, pending a concrete S1-T plan, expanded implementation and fresh review. Neither change closes N16 tracing, N18c publication, N19 provider variants or broad N28 persistence.


17:39 CDT continuation checkpoint43: separate finaldirect exit0,19948tests/52786assertions/574skips; syntax/fullCS1339files/strictComposer/parser82/diff and docs17/35 pass. N18c2bR2 exact3 source wrapper/tagassertion repair frozen in fresh independent review. Three implementers active: S1r2 exact11 provider markers/public trace envelopes/synthetic loader maps, N20cC1 exact4 synchronous cache facade/finally barrier with14 verified excluded overlays, N20cD1 exact3 raw cosine kernel dedupe with no runtime overlays. Scope is source-derived; Promise/suspending-Fiber queue behavior and N16/N18/publication remain separate. No commits/pushes/releases; next serial integration requires independent reviews and scoped native gates.

### Continuation batch 47 — 2026-10-09

47 bounded groups are independently reviewed, integrated and locally verified in the uncommitted working tree. The four latest groups are N18c2b (StateGraph function wrapping), N20c-D1 (cosine kernel deduplication), N20c-C1 (synchronous borrowed cache facade), and N19b-S1-P (six provider credential contracts and four public model trace envelopes). Combined count synchronization passes at 20,203 tests / 54,739 assertions / 574 environment skips on PHP8.5.11. Syntax and full CS pass for 1,345 source/test files; strict Composer validation, best-effort target8.2 parsing and diff checks pass. Separate final direct verification passed with exit0 (61.589s wall time); evidence: build/completion-47-final.xml. The full current47 native PHP8.2–8.4 matrix now passes with optional-library-off/on, syntax/style/platform and strict Composer checks; offline live-backend/provider limits remain. No commit, push or release.

StateGraph raw function nodes now use the pinned RunnableCallable wrapper with trace=false and recurse behavior. The duplicate-node tag assertion was migrated to the actual source deduplication. Plain Graph/Lambda publication, wider tracing/config presence and cancellation remain separate. Scoped native PHP8.2/8.3/8.4 acceptance passes 60 tests / 425 assertions without skips on each version.

StoreUtils and MathUtils share the raw cosine kernel while preserving their existing zero-vector and NaN policies. Tiny-vector infinity behavior is retained; the dimension-mismatch exception now originates in MathUtils with its class and message unchanged. Scoped native acceptance passes 65 tests / 158 assertions without skips on each version. This is deduplication, with no numerical stability policy change.

The AsyncBatchedCache facade synchronously forwards to a borrowed mutable BaseCache backend, keeps public backend identity and makes stop a no-op. Pregel uses a finally cleanup barrier. Scoped native acceptance passes 50 tests / 229 assertions without skips on each version. Promise/deferred errors, suspending-Fiber queues, store/checkpointer stop and cyclic waiting-edge reset/checkpoint behavior remain open; N20c-CYCLE has a frozen source-backed two-method barrier repair plan.

Provider credential markers apply only to the six explicitly opted S1 classes. Four model starts use the full virtual public serialization envelope when handlers exist; zero-handler calls avoid serialization. Scoped native acceptance passes 636 tests / 2,483 assertions without skips on each version. PHP omitted/default/explicit-empty callbacks collapse to the same empty manager, an explicit adaptation; traced shared-DAG cost remains. Ordinary aliases, generic hooks, constructor/current-key provenance, configuration fallback, unknown nested credentials, provider variants and wider N16 tracing remain open. Ignore-handler stream test discrimination is a nonblocking follow-up.

N27-L1 remains unintegrated with independent REQUEST CHANGES F1. Its scoped native 732 tests / 3,788 assertions per version did not catch silent nested chunk and RemoveMessage demotion in ChatPromptValue. Native green does not override that objection. A separate source-backed prompt representation plan is required before any registry integration; old dictionary records can lose irrecoverable class information. Missing/empty prompt warnings and malformed RemoveMessage behavior also need explicit disposition.

These scoped native runs use distinct frozen inputs and overlap; they are not summed or presented as a whole current-candidate matrix. Earlier batch43 live MongoDB acceptance remains valid for its reviewed bounded input, with Atlas and migration limits unchanged. The current47 combined native matrix passes; genuine Redis cluster, remaining provider/runtime parity and live provider gates stay open. Historical batch43 paragraphs describe the state at that checkpoint.

#### Current47 native matrix — 2026-10-09

Actual Linux arm64 PHP8.2.34, 8.3.35 and 8.4.26 each pass the complete current47 suite with the optional MongoDB library absent (20,203 tests / 53,080 assertions / 1,099 skips) and loaded (20,203 / 53,085 / 1,097), plus syntax, full style, dependency-platform and strict Composer validation. The discovered-case multiset matches the host run after only root mount-path normalization. Each native run additionally skips the same529 PostgreSQL-dependent cases; no new owned slice regresses to a skip. This offline matrix does not establish live backend/provider/Atlas/Redis-cluster/MCP acceptance.

The first archive-only PHP8.2 attempt failed exactly two Git-history guards. A new immutable view includes the exact repository history and current source, with no code or test changes; the failed attempt remains recorded. All exact owned containers are gone. Common floor73 dev dependencies are used for PHP8.2; the host ignored lock is unchanged. The512M suite budget is validation-only, while production memory regression children retain128M. Evidence: .loop/next/reports/native-matrix-candidate47-final.json. Later isolated CYCLE/CPV/S2/generic-hook work is excluded until separately reviewed and integrated.


#### Active review gates after current47 native verification

N20c-CYCLE is frozen as an exact three-file candidate in fresh independent code review. Complete consume reset and always-seen checkpoint behavior pass focused isolated and current47-preloaded gates; all twenty setup dependencies are byte-identical and excluded. Candidate native acceptance and integration remain pending.

N27-CPV's combined five-file source plan is independently approved with four binding conditions. Implementation is authorized for genuine message retention, explicit caller-array role capture and local legacy literal/remove disposition. Tests must distinguish HEAD-era zero-wrapper payloads from unreleased escaped dictionaries, and declare role effects on public consumers. The original L1 identity-loss objection remains open until a fresh prerequisite-aware repair and review.

The revised S1-C two-file generic serializer source plan is in fresh independent R2 review; no implementation is authorized. Provider policy S2-M received REQUEST CHANGES on four findings. Read-only R2 adds a proposed seventh narrow test owner, corrects the ordering witness, pins organization presence for any non-null config value and distinguishes the exact Together private-property error. Option A's mutation/provenance adaptations stay explicit; no provider implementation or broader closure is claimed.

Root source/tests still match the immutable47 snapshot. The roadmap was reviewed at728px and360px: all readable DOM text meets18px, body20px, no console errors. All current work remains uncommitted and unreleased.

19:04 CDT: S1-C source-plan R2 independently APPROVED; exact two-file implementation authorized in its own worktree with no production activation. All eight review observations are assigned as controls or explicit adaptations, including post-alias undefined removal and future stdClass-consumer audit. CPV exact nine setup copies are individually verified and approved; actual PII Chat/Chunk rejection is preserved and distinguished from reconstruction proof. CYCLE remains frozen in independent code review; S2-M remains read-only policy repair. Root source/test snapshot47 remains unchanged.

### Continuation batch 48 — cyclic barrier lifecycle

48 bounded groups are independently reviewed, integrated and locally verified in the uncommitted working tree. The newest group is N20c-CYCLE: completed barrier consumption resets participants and partial or empty join checkpoints persist. Combined count synchronization passes at 20,230 tests / 55,211 assertions / 574 environment skips on PHP8.5.11. Syntax/full CS pass for 1,347 source/test files; strict Composer, best-effort target8.2 parsing and diff checks pass. Separate final direct verification passes with exit0 (61.234s wall); evidence: build/completion-48-final.xml. Scoped native PHP8.2/8.3/8.4 CYCLE plus existing cache regression passes38 tests /638 assertions without skips, syntax and exact3 style on each minor. Full current48 native PHP8.2/8.3/8.4 optional-off/on, syntax/style/platform and strict Composer checks pass; offline live-backend/provider limits remain. No commit, push or release.

The exact three-file barrier repair is independently APPROVED with no material findings. Repeated joins, partial/ready/consumed interruptions and cache-on/off source controls pass. Ten worker/reviewer semantic mutations fail; malformed external checkpoint Set normalization and wider async/Fiber semantics remain separate. Intermediate stream payload and slow-backend-call assertion gaps are minor follow-ups, with final output/count/cache controls retained. Parent-owned documentation counts are synchronized.

CPV exact5 and default-disabled S1-C exact2 remain isolated implementations. S2-M policy R2 is independently APPROVED and exact7 isolated implementation is authorized, with fresh code review still required; L1 remains held on its identity objection. Current47 native inputs and all frozen artifacts remain unchanged.

#### Current48 native matrix — 2026-10-09

Actual Linux arm64 PHP8.2.34, 8.3.35 and 8.4.26 each pass the complete current48 suite with the optional MongoDB library absent (20,230 tests / 53,552 assertions / 1,099 skips) and loaded (20,230 / 53,557 / 1,097), plus syntax, full style, dependency-platform and strict Composer validation. Discovered case multisets match the host after root mount-path normalization. Each native run additionally skips the same529 PostgreSQL-dependent cases; no new owned slice regresses to a skip. This offline matrix does not establish live backend/provider/Atlas/Redis-cluster/MCP acceptance.

The immutable view includes exact repository history and source. All18 exact owned containers are gone. Common floor73 dev dependencies support PHP8.2; the host ignored lock is unchanged. The512M suite budget is validation-only, while production memory regression children retain128M. Evidence: .loop/next/reports/native-matrix-candidate48-final.json. Historical47 evidence is retained. Isolated CPV/S1-C/S2-M repairs remain excluded until separately reviewed and integrated.

### Continuation batch 49 — genuine prompt message identity

49 bounded groups are independently reviewed, integrated and locally verified in the uncommitted working tree. The newest group is N27-CPV: genuine nested prompt messages retain identity and explicit array roles through trusted Load rounds. Combined count synchronization passes at 20,270 tests / 55,787 assertions / 574 environment skips on PHP8.5.11. Syntax/full CS pass for 1,348 source/test files; strict Composer, best-effort target8.2 parsing and diff checks pass. Separate final direct verification passes with exit0 (68.533s wall); evidence: build/completion-49-final.xml. Scoped native PHP8.2/8.3/8.4 CPV plus Messages/Load/Schema/Prompts/Caches/Serde/PII/RemoteGraph passes1,101 tests /4,900 assertions without skips on each minor, plus exact5 syntax/style. Full current49 native PHP8.2/8.3/8.4 optional-off/on, syntax/style/platform and strict Composer checks pass; offline live-backend/provider limits remain. No commit, push or release.

The exact five-file patch is independently APPROVED without material findings. Typed lazy capture retains all six chunks, RemoveMessage and custom Chat roles; local legacy dictionaries unescape once and ambiguous stored-remove is refused. HEAD zero-wrapper and unreleased root43 escaped payload controls disclose unversioned singleton ambiguity. Original L1 remains held: empty and nonempty CPV checkpoint reads still throw the same TypeError before/after.

Review NB1: direct shorthand construction lacks a captured role, but concat/convertToChunk/constructFromParams/stored and legacy Chat adapters supply an explicit array role and acquire captured role chat. Loose object equality changes; runtime type remains chat. This PHP adaptation is retained. NB2: actual PII rejection, RemoteGraph fake wire and concat are baseline-green no-regression controls, not discriminators; the changed reconstruction/stored/cache controls and genuine two-round identity assertions supply repair evidence. NB3 colliding-role fixture coverage, NB4 precise unknown-ID exception text, NB5 empty checkpoint precision, NB6 existing role-only field-map adaptation, NB7 deprecation coupling and NB8 composerfiles diagnostics are tracked nonblocking follow-ups.

S1-C exact2 is frozen in fresh independent code review with no production activation. S2-M exact7 remains isolated implementation. L1 R2 and first core-family activation plans are read-only; no borrowed WIP or blanket activation is authorized.

#### Current serializer review disposition — 2026-10-09

S1-C code R1 requested changes for M1: a missing nested secret-path intermediate could serialize the internal undefined placeholder name. Exact2 repair is isolated; actual source array/object rows and a guard-removal mutant are required before fresh independent code R2. All nine nonblocking findings require explicit responses, including partial-depth redaction, Unicode whitespace, numeric whitelist, scalar traversal, observable private state, enum conversion/autoload and nested undefined exceptions. No production activation or root integration of this core has occurred. The read-only first-family activation plan is paused during the repair. Provider variants remain isolated and checkpoint registry R2 remains read-only planning.

#### Current49 native matrix — 2026-10-09

Actual Linux arm64 PHP8.2.34, 8.3.35 and 8.4.26 each pass the complete current49 suite with the optional MongoDB library absent (20,270 tests / 54,128 assertions / 1,099 skips) and loaded (20,270 / 54,133 / 1,097), plus syntax, full style, dependency-platform and strict Composer validation. Discovered case multisets match the host after root mount-path normalization. Each native run additionally skips the same529 PostgreSQL-dependent cases; no new owned slice regresses to a skip. This offline matrix does not establish live backend/provider/Atlas/Redis-cluster/MCP acceptance.

The immutable view includes exact repository history and source. All18 exact owned containers are gone. Common floor73 dev dependencies support PHP8.2; the host ignored lock is unchanged. The512M suite budget is validation-only, while production memory regression children retain128M. Evidence: .loop/next/reports/native-matrix-candidate49-final.json. Historical48 evidence is retained. Isolated S1-C/S2-M and L1 repairs remain excluded until separately reviewed and integrated.

### Continuation batch 50 — opt-in serializer core

50 bounded groups are independently reviewed, integrated and locally verified in the uncommitted working tree. N19b-S1-C adds an opt-in source-contract serializer core with explicit capture, alias/secret ordering and Undefined read/write guards. It remains disabled by default with no production-family adoption. Host PHP8.5.11 passes 20,327 tests / 56,092 assertions / 574 environment skips, plus syntax/style for 1,349 files, target8.2 parsing, strict Composer and diff checks. Full current50 native PHP8.2–8.4 matrix pending; historical49 full native passes. No commit, push or release.

The exact2 R3 patch and subsequent method-name-only integration amendment are independently APPROVED. Combined verification identified a substring collision: the reference guard counted Observable within the new test method name. Renaming it to Appears preserves all assertions and keeps the orphan guard intact. Frozen R3 artifacts remain immutable; amendment provenance is separate.

N1–N10 remain disclosed: partial-depth secret path behavior differs and may leave a PHP raw field where source redacts it; NBSP splitting/int keys/scalar paths are bounded adaptations; the private capture bag changes native object serialization state; backed enums/public fields and nested undefined differ; eligibility still calls its hook; source creates empty intermediates for missing secret terminals while PHP omits them. No blanket credential-safety or full source-path parity claim. HumanMessage activation is held for fresh source-plan review after core acceptance; Document is not adopted.

S2-M frozen exact8 R2 is in fresh independent code review after a dispatch-only retry, with full49 consumer and mutation evidence; no root integration yet. L1 source-plan R2 is approved and exact4 implementation active with approved11 setup copies excluded. N16 first retriever trace and S2-D deletion plans remain read-only.

### Continuation batch 51 — provider variant contracts

51 bounded groups are independently reviewed, integrated and locally verified in the uncommitted working tree. The serializer core and exact8 provider variant contracts pass together. Host PHP8.5.11 passes 20,379 tests / 56,959 assertions / 574 environment skips, plus syntax/style for 1,350 files, target8.2 parsing, strict Composer and diff checks. Full combined51 native PHP8.2–8.4 pending; historical49 native remains verified. No commit, push or release.

Independent code R2 APPROVE exact8 repairs the existing Load consumer with three reload IDs and four family marker expectations, preserving XAI ordinary raw organization. Native scoped three-minor checks pass against current serializer core, including the full Load suite, variant policy test, original19-path union and previously omitted FireworksEmbeddings. Load roundtrip cannot replace focused organization/exclusion controls.

Approved Option A retains source-versus-PHP presence/null/type/current-state adaptations, exact-class guards, Together private reader/delegation and Fireworks construction provenance; no generic credential safety or complete source serialization parity is claimed. Unowned stale comments, sourceMarked documentary assertions, source archive replay seam, noenv organization/header reload provenance and indirect-consumer inventory bounds remain NB1–NB7. S2-D public deletion and organization residual remain separate; no generic Load/registry/core activation.

Root50 full direct verifies current documentation counts after its count-sync wrapper timeout; no successful sync exit is claimed for that timed-out process. Full50 native was superseded by the combined51 matrix after both candidates passed independent review and scoped native checks; immutable49 history and root50 host snapshot remain preserved.

#### Current51 native matrix — 2026-10-09

Actual Linux arm64 PHP8.2.34, 8.3.35 and 8.4.26 each pass the complete current51 suite with the optional MongoDB library absent (20,379 tests / 55,300 assertions / 1,099 skips) and loaded (20,379 / 55,305 / 1,097), plus syntax, full style, dependency-platform and strict Composer validation. Discovered case multisets match the host after root mount-path normalization. Each native run additionally skips the same529 PostgreSQL-dependent cases; no new owned slice regresses to a skip. This offline matrix does not establish live backend/provider/Atlas/Redis-cluster/MCP acceptance.

The immutable view includes exact repository history and source. All18 exact owned containers are gone. Common floor73 dev dependencies support PHP8.2; the host ignored lock is unchanged. The512M suite budget is validation-only, while production memory regression children retain128M. Evidence: .loop/next/reports/native-matrix-candidate51-final.json. Historical49 evidence is retained. S1-C core plus reviewed test-name amendment are included; production-family activation and isolated S2-D/L1/N16 remain excluded.

### Continuation batch 52 — canonical checkpoint core registry

52 bounded groups are independently reviewed, integrated and locally verified in the uncommitted working tree. The canonical checkpoint registry joins the serializer and provider variants. Host PHP8.5.11 passes 20,437 tests / 59,425 assertions / 574 environment skips, plus syntax/style for 1,351 files, target8.2 parsing, strict Composer and diff checks. Full current52 native PHP8.2/8.3/8.4 optional-off/on, syntax/style/platform and strict Composer checks pass; offline live-backend/provider limits remain. No commit, push or release.

Replacement L1 R2 exact4 independent code R1 APPROVE and current51 scoped native acceptance precede serial integration. Checkpoint allowlist includes13 concrete messages and2 prompt values with canonical string-list IDs, exact fixed-class lcId equality, abstract/unknown inertness, single known-kwargs unescape and typed prompt adapters. Genuine children retain identity; legacy dictionaries require string type, array data and no lc key; ambiguous legacy remove is refused. Two old artifact methods are migrated, same14 unknown controls retained and all33 untouched methods preserved. Original heldF1 evidence stays historical and is not reversed.

PHP numeric-key JSON object IDs can decode as lists and revive (NB1); source leaves them plain. array_is_list is redundant with exact canonical equality (NB2), so its equivalent mutant is not independently killed. Final31 immutable49 mutants against the40-test file are authoritative; earlier29 moving-root/draft runs remain diagnostic (NB5). Source namespace fallback, malformed/typed/legacy coercions, irreversible old class/role collisions, empty object/list and nested-native second-round loss remain disclosed. Other model and registry families are not generically activated; N27 and the whole port remain incomplete. Native validation uses current51 plus exact4 candidate, without importing historical49 setup overlays.

#### Current52 native matrix — 2026-10-09

Actual Linux arm64 PHP8.2.34, 8.3.35 and 8.4.26 each pass the complete current52 suite with the optional MongoDB library absent (20,437 tests / 57,766 assertions / 1,099 skips) and loaded (20,437 / 57,771 / 1,097), plus syntax, full style, dependency-platform and strict Composer validation. Discovered case multisets match the host after root mount-path normalization. Each native run additionally skips the same529 PostgreSQL-dependent cases; no new owned slice regresses to a skip. This offline matrix does not establish live backend/provider/Atlas/Redis-cluster/MCP acceptance.

The immutable view includes exact repository history and source. All18 exact owned containers are gone. Common floor73 dev dependencies support PHP8.2; the host ignored lock is unchanged. The512M suite budget is validation-only, while production memory regression children retain128M. Evidence: .loop/next/reports/native-matrix-candidate52-final.json. Historical49 and51 evidence is retained. S1-C core plus reviewed test-name amendment are included; production-family activation and isolated S2-D/N16 remain excluded; canonical L1 included.
