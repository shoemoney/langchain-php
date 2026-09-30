# Review 4 - stepfun/step-3.7-flash
_asked 2026-09-30T07:48:07 - served by stepfun/step-3.7-flash - compressed retry_

The user wants me to review a PHP library (langchain-php) and find exactly THREE things that would make it better. I need to provide:
1. One-line title
2. Severity (BLOCKER/MAJOR/MINOR)
3. Evidence (file:line or test name, but no line numbers invented - quote code instead)
4. Why it matters
5. Fix

Constraints:
- Answer FIRST, then stop
- Keep each finding SHORT (at most 6 lines)
- Only verify three, return three
- No invented line numbers
- Quote code only if verbatim in packet
- Address comments if present
- This is a PORT, not a product - suggesting features upstream doesn't have is a defect
- Judge fidelity, correctness, safety and tests

The packet contains:
- Overview of the project
- Measured state
- Source layout
- Test layout
- Project status and known divergences (PORT_STATUS.md)
- HANDOFF.md with architecture decisions and bugs already found

I need to find actual issues in the code based on the packet. But the packet is mostly metadata and documentation. The actual source code is not provided in detail. However, I can infer issues from the HANDOFF.md and the architecture decisions.

Let me look for things that are explicitly mentioned as problems or potential issues:

1. From HANDOFF.md: "The third was self-inflicted this session: adding `array $kwargs = []` to `BaseChatModel::bindTools()` broke `FakeStreamingChatModel::bindTools(array $tools)` at class-load time, which took down every test in the suite at once."
   - This is about PHP parameter types being contravariant. The issue is that `BaseChatModel::bindTools()` added a required parameter (or changed signature) that broke child classes. This is a known bug class. But is it still present? The HANDOFF says it was found and fixed? Actually it says "The third was self-inflicted this session" implying it was introduced and presumably fixed. But the pattern is a risk.

2. From HANDOFF.md: "All three bugs below came from a signature that PHP forbids." - This is a known issue pattern.

3. From the architecture: "The Pregel loop is a `\Generator`, and that is load-bearing" - this is a design decision, not a bug.

4. From the message merge algebra: "Getting it wrong silently corrupts reconstructed streams. It is heavily tested — leave it alone." - This is a warning.

5. From the provider client section: "Which means assert on the request body, not just the response. Two of the bugs in the table below were invisible until a test read back what the client actually sent." - This suggests that some tests might not be asserting on request bodies.

6. From the bugs table: `CheckpointFunctions::uuid6()` used `random_int` for sub-ms bits - this was a bug that was found and fixed presumably.

7. `StateGraph::__construct` reassigned the promoted parameter, not `$this->`schema - this was a bug.

8. `ChatPromptValue` declared in two files - fatal redeclare.

9. `FunctionMessage::$name` redeclared `string` over inherited `?string` - fatal on class load.

The packet says "Bugs already found (do not reintroduce)" - so these are fixed. I should not report them again unless the fix didn't hold.

I need to find NEW issues or potential issues that are evident from the packet.

Let me re-read carefully:

The packet is a review packet. It contains:
- Project overview
- Measured state
- Source layout
- Test layout
- Project status and known divergences (PORT_STATUS.md)
- HANDOFF.md

The HANDOFF.md mentions:
- "The user's instruction was 'a completely ported SDK.' That is not achieved yet. See 'What is NOT ported'"
- But the "What is NOT ported" section is not included in the packet! It's referenced but not shown.

So I cannot report on what is not ported because I don't have that information.

The HANDOFF.md also says:
- "Do not touch `/Users/shoemoney/Projects/agentdesk`." - not relevant.
- "The Pregel loop is a `\Generator`, and that is load-bearing" - design decision.
- "Promises exist so ported logic maps 1:1" - design decision.
- "Streaming is `[channel, value]` tuples" - design decision.
- "A provider client is a translation layer, and needs a transport seam" - design decision.
- "The message merge algebra is the streaming core" - heavily tested.
- "PHP parameter types are contravariant — this bit us three times" - known issue class.

The bugs table lists bugs that were found. The note says "do not reintroduce".

So what can I actually find that's wrong?

Let me look at the test layout. There are 15 tests/Unit/LanguageModels/Chat, 12 tests/Unit/Checkpoint, etc. But I don't have the actual test code.

Maybe I can infer something from the architecture decisions that is not correctly implemented?

Wait, the HANDOFF.md says: "The third was self-inflicted this session: adding `array $kwargs = []` to `BaseChatModel::bindTools()` broke `FakeStreamingChatModel::bindTools(array $tools)` at class-load time, which took down every test in the suite at once."

This implies that currently, the code might have this issue? Or it was fixed? The tense is past "was self-inflicted", "broke", "took down". It sounds like it was a bug that occurred during the session and was presumably fixed. But the packet is the review packet, so it should reflect the current state. If it's listed under "Bugs already found", it's fixed.

But the instruction says: "Quote the row and explain why the fix did not hold, or leave it alone." So if I think the fix didn't hold, I need evidence. I don't have evidence that it didn't hold.

Let me think about what else could be wrong.

The HANDOFF.md mentions: "PORT_STATUS.md and HANDOFF.md are the PROJECT'S OWN record of what was fixed and why. A row there is evidence that a bug was found and dealt with — not that it is still open."

So I should not report bugs from those tables as open.

What about the architecture decisions? Are they correctly implemented? I don't have the code to verify.

Maybe there's something in the test layout that's suspicious? For example, there are 2 tests/Unit/Channels but 14 src/LangGraph/Channels. That's a low test count. But is that a defect? Not necessarily - maybe Channels are simple. But it could be a risk.

Similarly, 9 tests/Unit vs many src files. But again, not necessarily a defect.

The user wants "three specific findings". I need to find actual issues.

Let me re-read the packet very carefully for any hints of problems.

The HANDOFF.md says: "The user's instruction was 'a completely ported SDK.' That is not achieved yet. See 'What is NOT ported' — this is the single most important thing to know before you start."

But the "What is NOT ported" section is missing from the packet. So I cannot use that.

The measured state says: "2312 tests, 6398 assertions (GREEN)". So tests pass.

The source layout shows 230 files. The test layout shows 106 files.

Maybe there's a mismatch? For example, src/LangChain/Load has 1 file, but no tests/Unit/Load. That could be untested code. But is that a defect? The instruction says "suggesting a feature upstream does not have is a defect in your review". But missing tests for a component might be a fidelity issue if the upstream has tests. However, I don't know if upstream has tests for Load.

The HANDOFF.md says: "It marks a subsystem ported ONLY when converted tests exist and pass". So if there's a src directory with no corresponding tests, it's not fully ported. But the packet doesn't show PORT_STATUS.md content, only references it.

Wait, the packet says: "The authoritative accounting is PORT_STATUS.md. Read it — it marks a subsystem ported ONLY when converted tests exist and pass, and it carries a 'Known non-exact behaviours' table where every deliberate PHP-vs-JS divergence is recorded with the reason."

But PORT_STATUS.md content is not provided. So I cannot read it.

So I'm limited to what's in the packet.

Let me look for any code snippets. There are none except in HANDOFF.md.

The HANDOFF.md has a code block showing the verify command. That's not a defect.

It also has a table of bugs. Those are fixed.

It has architecture decisions. Those are design choices.

Maybe I can find something in the test layout that indicates a missing test? For example, src/LangChain/Load has 1 file, but no tests/Unit/Load directory. That could be a finding: "Missing tests for Load component". But is that a defect? The HANDOFF says "converted tests exist and pass" is the mark of a ported subsystem. If there's no test directory, it's not ported. But the user asked for "three things that would make it better". Missing tests would make it better.

But I need to be careful: the test layout shows:
```
  1 tests/Unit/LanguageModels/Chat/OpenAI
  1 tests/Unit/LanguageModels/Chat/Anthropic
```
But src has:
```
  2 src/LangChain/LanguageModels/Chat/OpenAI
  2 src/LangChain/LanguageModels/Chat/Anthropic
```
So there are tests for those.

For Load: src has 1 file, tests have 0 directories for Load. So that's a missing test.

Similarly, src/LangChain/Utils/Testing has 9 files, but tests/Unit/Utils/Testing? Not listed. tests/Unit/Utils has 1 file. So maybe missing tests for Utils/Testing.

But are these "things that would make it better"? Yes, test coverage.

But the instruction says: "Judge fidelity, correctness, safety and tests." So missing tests is a valid finding.

However, I need to provide evidence. I can cite the source layout and test layout tables. That's in the packet.

So finding 1: Missing test coverage for `src/LangChain/Load` (1 file, 0 test directories).
Severity: MAJOR (since it's a whole component untested)
Evidence: Source layout shows `1 src/LangChain/Load`; test layout shows no `tests/Unit/Load` directory.
Why: A port is only considered complete when converted tests exist and pass; untested code may diverge silently from upstream.
Fix: Add converted tests from upstream for the Load component.

But is that too vague? The user wants specific findings. I can be specific about the path.

Finding 2: Missing test coverage for `src/LangChain/Utils/Testing` (9 files).
Evidence: Source layout shows `9 src/LangChain/Utils/Testing`; test layout shows only `1 tests/Unit/Utils`.
Why: Testing utilities are critical for verifying provider clients; untested utilities may hide bugs in the transport seam.
Fix: Add unit tests for the testing utilities, especially `FakeHttpClient`.

But again, is this a defect? The HANDOFF says "converted tests exist and pass" is the mark. So missing tests means not fully ported. That's a fidelity issue.

Finding 3: Something else.

Maybe I can look at the test counts per component. For example, src/LangGraph/Pregel has 25 files, tests/Unit/Pregel has 7 files. That's a ratio of about 3.5:1. src/LangChain/Runnables has 13 files, tests/Unit/Runnables has 6 files. Ratio ~2:1. src/LangChain/Messages has 16 files, tests/Unit/Messages has 5 files. Ratio ~3:1.

But I don't know if that's abnormal.

Maybe there's a specific bug mentioned in HANDOFF that is not fixed? The HANDOFF says "Bugs already found (do not reintroduce)". So they are fixed.

Wait, the HANDOFF says: "The third was self-inflicted this session: adding `array $kwargs = []` to `BaseChatModel::bindTools()` broke `FakeStreamingChatModel::bindTools(array $tools)` at class-load time, which took down every test in the suite at once."

This is described in the present perfect? "was self-inflicted", "broke", "took down". It sounds like it happened and was fixed. But maybe it's describing a current issue? The HANDOFF is a snapshot. If it says "this session", it might be current. But it's under "Bugs already found", implying they are known and fixed.

The instruction says: "Quote the row and explain why the fix did not hold, or leave it alone." So if I think the fix didn't hold, I need to explain. But I have no evidence that it didn't hold.

Maybe I can look at the architecture decision #6: "PHP parameter types are contravariant — this bit us three times". This is a known issue class. Perhaps there are other places where this happens? But I don't have the code.

Let me think about the "Known non-exact behaviours" table in PORT_STATUS.md. I don't have that.

Maybe I can infer something from the test suite size. 2312 tests, 6398 assertions. That's about 2.76 assertions per test. That seems reasonable.

What about the fact that the suite is green? That's good.

Maybe there's a issue with the Pregel loop being a Generator? The HANDOFF says "Do not 'improve' this into an event loop or a state machine." So that's a design constraint, not a bug.

Maybe there's an issue with streaming? The HANDOFF says streaming returns `[channel, value]` tuples. If the implementation doesn't match that, it's a bug. But I don't have the code.

I need to find something concrete in the packet.

Let me look at the source layout again:
```
25 src/LangGraph/Pregel
  22 src/LangChain/OutputParsers
  19 src/LangChain/Prompts
  16 src/LangChain/Tracers
  16 src/LangChain/Messages
  14 src/LangGraph/Channels
  13 src/LangGraph/Errors
  13 src/LangChain/Runnables
  11 src/LangGraph/Checkpoint
  11 src/LangChain/Tools
   9 src/LangChain/Utils/Testing
   9 src/LangChain/TextSplitters
   7 src/LangChain/LanguageModels
   6 src/LangChain/LanguageModels/Outputs
   5 src/LangGraph/Pregel/Checkpoint
   5 src/LangGraph/Checkpoint/Serde
   5 src/LangChain/Utils/Http
   5 src/LangChain/Utils
   4 src/LangChain/Schema
   3 src/LangGraph/State
   2 src/LangChain/OutputParsers/OpenAITools
   2 src/LangChain/LanguageModels/Chat/OpenAI/Utils
   2 src/LangChain/LanguageModels/Chat/OpenAI
   2 src/LangChain/LanguageModels/Chat/Anthropic/Utils
   2 src/LangChain/LanguageModels/Chat/Anthropic
   1 src/LangGraph/Pregel/Retry
   1 src/LangChain/Load
```

Test layout:
```
15 tests/Unit/LanguageModels/Chat
  12 tests/Unit/Checkpoint
  10 tests/Unit/OutputParsers
   9 tests/Unit
   7 tests/Unit/Tools
   7 tests/Unit/Prompts
   7 tests/Unit/Pregel
   6 tests/Unit/Runnables
   5 tests/Unit/TextSplitters
   5 tests/Unit/Messages
   4 tests/Unit/Tracers
   4 tests/Unit/LanguageModels
   3 tests/Unit/Utils/Http
   2 tests/Unit/Channels
   2 tests/Integration
   1 tests/Unit/Utils
   1 tests/Unit/State
   1 tests/Unit/OutputParsers/OpenAITools
   1 tests/Unit/LanguageModels/Chat/OpenAI
   1 tests/Unit/LanguageModels/Chat/Anthropic
   1 tests/Unit/Errors
   1 tests/Unit/Checkpoint/Serde
```

Notice:
- src/LangChain/Load (1 file) has no corresponding test directory.
- src/LangChain/Utils/Testing (9 files) has no corresponding test directory. tests/Unit/Utils has 1 file, but that's probably for Utils, not Utils/Testing.
- src/LangGraph/Pregel/Retry (1 file) has no corresponding test directory.
- src/LangChain/OutputParsers/OpenAITools (2 files) has tests/Unit/OutputParsers/OpenAITools (1 file) - that's covered.
- src/LangChain/LanguageModels/Chat/OpenAI/Utils (2 files) has tests/Unit/LanguageModels/Chat/OpenAI (1 file) - maybe not covering Utils specifically.
- src/LangChain/LanguageModels/Chat/Anthropic/Utils (2 files) has tests/Unit/LanguageModels/Chat/Anthropic (1 file) - same.

But the most glaring is src/LangChain/Load with 1 file and zero test directories. That's a clear missing test.

Also, src/LangChain/Utils/Testing has 9 files. These are testing utilities. They should be tested themselves? Maybe not, but they are part of the port. The HANDOFF says "converted tests exist and pass" marks a subsystem as ported. So if there are no tests for Utils/Testing, it's not ported.

But is Utils/Testing a subsystem? It's utilities for testing. Maybe it's not part of the SDK proper. But it's in src/, so it's part of the codebase.

Similarly, src/LangGraph/Pregel/Retry has 1 file. No tests.

So I can report missing tests for these.

But the user wants "three things that would make it better". Missing tests are valid.

However, I need to be careful: the HANDOFF says "converted tests exist and pass" is the mark. So if there's no test directory, it's not ported. That's a fidelity issue.

Let me formulate three findings:

1. Missing test coverage for `src/LangChain/Load` component.
   - Severity: MAJOR
   - Evidence: Source layout lists `1 src/LangChain/Load`; test layout has no `tests/Unit/Load` directory.
   - Why: The port is only considered complete when converted tests exist and pass; untested code may diverge from upstream.
   - Fix: Add converted tests for the Load component from upstream.

2. Missing test coverage for `src/LangChain/Utils/Testing` utilities.
   - Severity: MAJOR
   - Evidence: Source layout lists `9 src/LangChain/Utils/Testing`; test layout has no `tests/Unit/Utils/Testing` directory.
   - Why: Testing utilities like `FakeHttpClient` are critical for verifying provider clients without sockets; untested utilities may hide bugs in the transport seam.
   - Fix: Add unit tests for the testing utilities, ensuring they correctly replay responses and record requests.

3. Missing test coverage for `src/LangGraph/Pregel/Retry` component.
   - Severity: MAJOR
   - Evidence: Source layout lists `1 src/LangGraph/Pregel/Retry`; test layout has no `tests/Unit/Pregel/Retry` directory.
   - Why: Retry logic is part of the Pregel durable-execution engine; untested retry behavior may cause silent failures in graph execution.
   - Fix: Add converted tests for the Retry component from upstream.

But are these "specific findings"? They are specific paths. But they are also somewhat similar. The user said "three specific findings beat five vague ones". These are specific.

But maybe I can find something more concrete.

Let me look at the test layout again. There is `2 tests/Unit/Channels` but `14 src/LangGraph/Channels`. That's a low ratio. But maybe Channels are simple. Still, 2 test files for 14 source files is low. But I don't know if that's a problem.

Similarly, `5 tests/Unit/Messages` for `16 src/LangChain/Messages`. That's 3:1 ratio. Might be okay.

What about `1 tests/Unit/Utils` for `5 src/LangChain/Utils`? That's low.

But the most obvious missing tests are for directories that have source files but no test directories at all.

From the source layout, the directories with no corresponding test directory (based on the test layout list) are:
- src/LangChain/Load (1 file)
- src/LangChain/Utils/Testing (9 files)
- src/LangGraph/Pregel/Retry (1 file)

Also, src/LangChain/Utils has 5 files, but tests/Unit/Utils has only 1 file. So maybe missing tests for some of those.

But I'll stick with the three that have zero test directories.

Now, I need to format each finding as:

## <n>. <Title>
**Severity:** ... **Evidence:** ...
**Why:** ...
**Fix:** ...

And keep each finding at most six lines.

Let me draft:

## 1. Missing tests for Load component
**Severity:** MAJOR **Evidence:** Source layout shows `1 src/LangChain/Load`; test layout shows no `tests/Unit/Load` directory.
**Why:** The port is only marked complete when converted tests exist and pass; untested code may silently diverge from upstream.
**Fix:** Add converted tests for the Load component from the upstream TypeScript SDK.

That's 4 lines. Good.

## 2. Missing tests for Utils/Testing utilities
**Severity:** MAJOR **Evidence:** Source layout shows `9 src/LangChain/Utils/Testing`; test layout shows no `tests/Unit/Utils/Testing` directory.
**Why:** Testing utilities like `FakeHttpClient` are the seam for provider client tests; untested utilities may hide request/response translation bugs.
**Fix:** Add unit tests for the testing utilities, verifying they replay responses and record requests correctly.

That's 4 lines.

## 3. Missing tests for Pregel/Retry component
**Severity:** MAJOR **Evidence:** Source layout shows `1 src/LangGraph/Pregel/Retry`; test layout shows no `tests/Unit/Pregel/Retry` directory.
**Why:** Retry logic is part of the Pregel durable-execution engine; untested retry behavior may cause silent failures in graph execution.
**Fix:** Add converted tests for the Retry component from the upstream TypeScript SDK.

That's 4 lines.

But are these the best findings? They are all about missing tests. Maybe I should find one about a code issue.

Let me think about the architecture decision #6: "PHP parameter types are contravariant — this bit us three times". The HANDOFF says: "The third was self-inflicted this session: adding `array $kwargs = []` to `BaseChatModel::bindTools()` broke `FakeStreamingChatModel::bindTools(array $tools)` at class-load time, which took down every test in the suite at once."

This is a bug that was introduced. Is it still present? The HANDOFF says "Bugs already found (do not reintroduce)". So it's fixed. But maybe the fix is not correct? I don't have evidence.

What about the message merge algebra? The HANDOFF says "Getting it wrong silently corrupts reconstructed streams. It is heavily tested — leave it alone." So it's tested.

What about the provider client transport seam? The HANDOFF says "assert on the request body, not just the response. Two of the bugs in the table below were invisible until a test read back what the client actually sent." But those bugs are in the table and are fixed.

Maybe there's a issue with the test suite itself? The HANDOFF says "The suite must be green. If it is not, something changed since the handoff — find out what before writing new code." But the measured state says it's green.

Maybe I can report that the test suite does not cover the Load component, which is a fidelity issue.

But the user might expect code-level findings, not just missing tests. However, the packet doesn't provide source code. So I can only report on what's visible.

Another angle: The HANDOFF.md mentions "Known non-exact behaviours" table in PORT_STATUS.md. I don't have that. But maybe I can infer that there are known divergences. However, without seeing them, I cannot report them.

The instruction says: "Evidence you cannot see in the packet is a guess. Leave the finding out."

So I must stick to what's in the packet.

The packet has:
- Project status and known divergences (but no details)
- HANDOFF.md with architecture decisions and bugs table
- Source layout
- Test layout

From the source and test layout, I can see mismatches.

Also, note that src/LangChain/Utils/Http has 5 files, and tests/Unit/Utils/Http has 3 files. That's covered.

src/LangChain/Utils has 5 files, tests/Unit/Utils has 1 file. So maybe missing tests for some Utils.

But the most clear are the three with zero test directories.

Let me check if there are any other directories with source files but no test directories:
- src/LangChain/Load: 1 file, no test dir.
- src/LangChain/Utils/Testing: 9 files, no test dir.
- src/LangGraph/Pregel/Retry: 1 file, no test dir.

Also, src/LangChain/OutputParsers/OpenAITools has 2 files, tests/Unit/OutputParsers/OpenAITools has 1 file. So partially covered.

src/LangChain/LanguageModels/Chat/OpenAI/Utils has 2 files, tests/Unit/LanguageModels/Chat/OpenAI has 1 file. Might not cover Utils.

But I'll go with the three zero-coverage ones.

Now, I need to ensure each finding is at most six lines. My drafts are 4 lines each. Good.

I need to start with the answer, then stop. So I'll write the three findings and stop.

But wait, the user said "Find exactly THREE things that would make it better." So I must provide exactly three.

Let me double-check if there's any other issue.

The HANDOFF says: "Do not touch `/Users/shoemoney/Projects/agentdesk`." That's not a code issue.

The measured state says PHP 8.5.11 local; CI matrix 8.2 / 8.3 / 8.4. That's fine.

Deps: guzzlehttp/guzzle, psr/log, ramsey/uuid; phpunit 11. Fine.

Maybe there's an issue with the test count? 2312 tests, 6398 assertions. That's fine.

Maybe the fact that it's not on Packagist? The HANDOFF says "Not on Packagist — consume via the VCS repository." That's a distribution issue, but not a code defect. And suggesting a feature (being on Packagist) is not a defect in the review? Actually, the instruction says: "suggesting a feature upstream does not have is a defect in your review, not an insight." Being on Packagist is a distribution thing, not a feature. But it's not about code fidelity. I'll skip.

Maybe the fact that the Pregel loop is a Generator is load-bearing, but what if the implementation doesn't match? I don't have code.

So I'll go with the missing test coverage findings.

But are they "things that would make it better"? Yes, they would improve completeness and fidelity.

Now, I need to format exactly as requested.

Let me write:

## 1. Missing test coverage for Load component
**Severity:** MAJOR **Evidence:** Source layout lists `1 src/LangChain/Load`; test layout lists no `tests/Unit/Load` directory.
**Why:** The port is only marked complete when converted tests exist and pass; untested code may silently diverge from upstream.
**Fix:** Add converted tests for the Load component from the upstream TypeScript SDK.

## 2. Missing test coverage for Utils/Testing utilities
**Severity:** MAJOR **Evidence:** Source layout lists `9 src/LangChain/Utils/Testing`; test layout lists no `tests/Unit/Utils/Testing` directory.
**Why:** Testing utilities like `FakeHttpClient` are the seam for provider client tests; untested utilities may hide request/response translation bugs.
**Fix:** Add unit tests for the testing utilities, verifying they replay responses and record requests correctly.

## 3. Missing test coverage for Pregel/Retry component
**Severity:** MAJOR **Evidence:** Source layout lists `1 src/LangGraph/Pregel/Retry`; test layout lists no `tests/Unit/Pregel/Retry` directory.
**Why:** Retry logic is part of the Pregel durable-execution engine; untested retry behavior may cause silent failures in graph execution.
**Fix:** Add converted tests for the Retry component from the upstream TypeScript SDK.

That's three findings.

But wait, the user said "Be terse — three specific findings beat five vague ones". These are specific.

Also, "anything you cannot point at should be left out." I'm pointing at the source and test layout tables, which are in the packet.

I think this is acceptable.

However, I should consider if there's a more critical issue. The HANDOFF mentions that "The user's instruction was 'a completely ported SDK.' That is not achieved yet." So missing components are a big deal. The three I found are missing tests, which means those components are not fully ported according to the project's own standard.

But maybe there are other components with missing tests? Let's check all source directories against test directories:

Source directories (from layout):
- src/LangGraph/Pregel (25) -> tests/Unit/Pregel (7) - covered
- src/LangChain/OutputParsers (22) -> tests/Unit/OutputParsers (10) - covered
- src/LangChain/Prompts (19) -> tests/Unit/Prompts (7) - covered
- src/LangChain/Tracers (16) -> tests/Unit/Tracers (4) - covered
- src/LangChain/Messages (16) -> tests/Unit/Messages (5) - covered
- src/LangGraph/Channels (14) -> tests/Unit/Channels (2) - covered
- src/LangGraph/Errors (13) -> tests/Unit/Errors (1) - covered
- src/LangChain/Runnables (13) -> tests/Unit/Runnables (6) - covered
- src/LangGraph/Checkpoint (11) -> tests/Unit/Checkpoint (12) - covered
- src/LangChain/Tools (11) -> tests/Unit/Tools (7) - covered
- src/LangChain/Utils/Testing (9) -> no test dir - MISSING
- src/LangChain/TextSplitters (9) -> tests/Unit/TextSplitters (5) - covered
- src/LangChain/LanguageModels (7) -> tests/Unit/LanguageModels (4) - covered
- src/LangChain/LanguageModels/Outputs (6) -> ? not directly, but maybe covered by LanguageModels tests.
- src/LangGraph/Pregel/Checkpoint (5) -> tests/Unit/Checkpoint (12) - probably covered
- src/LangGraph/Checkpoint/Serde (5) -> tests/Unit/Checkpoint/Serde (1) -