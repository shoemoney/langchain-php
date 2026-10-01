---
active: true
iteration: 383
maxIterations: 1000
sessionId: ses_f13553186ffepdQcWupZglIwmo
---

INFINITE REVIEW-AND-IMPROVE LOOP over the PHP repo at /Users/shoemoney/Projects/langchain-php (a faithful PHP port of LangChain JS / LangGraph JS). NEVER output the DONE promise — this loop only ends when Jeremy tells it to.

Working dir: /Users/shoemoney/Projects/langchain-php
Upstream TypeScript (READ-ONLY, never modify): /Users/shoemoney/Projects/agentdesk/.runtime/js-sdks/langchainjs and .../langgraphjs
The OpenRouter key is at /Users/shoemoney/.config/openrouter/key — `export OPENROUTER_API_KEY=$(cat /Users/shoemoney/.config/openrouter/key)` before any .loop/*.py call.

=== STEP 1 — REFRESH THE PACKET (the reviewer must see the LIVE repo) ===
  python3 .loop/build_packet.py
  python3 .loop/plan.py

=== STEP 2 — ASK A REVIEWER (one per iteration, random un-asked vision model) ===
  python3 .loop/ask.py
  Roster was widened to 99 models; ~49 have never been asked. Keep burning that list.
  Only status "ok" is a real review. If a model fails twice (404 guardrail, truncated, empty), note it and move on.

=== STEP 2b — EVERY 3rd ITERATION, RUN A HOLISTIC ADVISORY PASS ===
  python3 .loop/advisory.py
  This hands ONE model the arch PNG, the namespace inventory, cross-package reference counts, the public surface of BaseChatModel/Pregel/Schema, the guard digest, and every prior finding, then asks for a verdict on all 8 elements plus cross-cutting composition/layering/dead-code questions. Triaged 3 of 4 as fidelity misreads last time (upstream Pregel uses public class fields; upstream langchain-core DOES ship src/utils/testing; upstream jsonplus.ts:75-89 DOES decode JS Set/Map) — so quote upstream file:line before rejecting a layering or dead-code claim.

=== STEP 3 — TRIAGE EVERY FINDING AGAINST UPSTREAM BEFORE TOUCHING CODE ===
  ACCEPT only if VERIFIED BY EXECUTING something (php -r probe, test run). Quote the command and its real output.
  REJECT when upstream does the opposite — quote the upstream file and line. This is a PORT: fidelity beats preference.
  REJECT feature requests (vector stores, embeddings, createReactAgent) — documented unported subsystems, not findings.
  REJECT vague advice with no specific defect.
  DEFER only with a stated reason, recorded in .loop/triage.json.
  Record: python3 .loop/plan.py --add "<model>" "<one-line verdict + evidence>"

=== STEP 4 — FIX ACCEPTED ONES, MUTATION-VERIFY EVERY FIX ===
  1. Fix faithfully. If you diverge from upstream, say why in a comment AND add a row to PORT_STATUS.md "Known non-exact behaviours".
  2. The test MUST fail on pre-fix code. Prove it: revert the fix, expect RED, restore, expect GREEN. Report which fixes you mutation-verified and which you could NOT.
  3. composer test must be green. Run it TWICE — a failure that moves is a nondeterminism bug.
  4. php -l every new file.
  5. Then: composer dump-autoload -o, PSR-4/duplicate-FQCN check, composer test twice, sync HANDOFF.md + PORT_STATUS.md to the MEASURED counts (DocsMatchRealityTest enforces this — it will fail you if you guess), git commit with a conventional message + emoji, git push, and check CI with `gh run watch $(gh run list --branch main --limit 1 --json databaseId -q '.[0].databaseId') --exit-status`.

=== HARD RULES (learned the hard way — do not regress them) ===
* A green suite proves LESS than it appears. Hunt what a test cannot see: written-but-never-read, parsed-but-dropped, swallowed errors, falsy-vs-absent (0, "", [] are legitimate), and DOCS THAT CONTRADICT THE CODE.
* ASSERT ON THE REQUEST BODY and the RAW STORED BYTES, not just the response. Two real releases were broken by defects only visible when reading what actually goes on the wire — a Schema instance nested as a property (failed validation OPEN), and an empty object encoding as [] instead of {}. Both are now guarded by tests/Integration/GraphAndCheckpointIntegrationTest.php.
* A TEST FIXTURE THAT MIRRORS A BUG HIDES IT. This bit twice: a fixture built with plain arrays hid that Schema::string() nested as a property validated nothing; a populated checkpoint hid that an EMPTY object encoded as []. Always test the empty/degenerate case — that is where the cast or the coercion lives.
* A MUTATION THAT SURVIVES IS A FINDING, not an annoyance. Read the diff, find why the test is blind, strengthen the test or fix the real defect underneath. Do not weaken the assertion.
* NEVER add a function newer than the declared PHP floor (composer.json >=8.2; CI runs 8.2/8.3/8.4; dev box is 8.5 so new functions are invisible locally). tests/Unit/PhpVersionCompatibilityTest.php guards it.
* Files land in the PSR-4 path or not at all. Run the PSR-4/duplicate-FQCN check after adding a file.
* A dev box with no coverage driver CANNOT see a defect that only exists when coverage is on — that is how the coverage job went red while 8.2/8.3/8.4 were all green. If you touch anything coverage-related, reason about what the coverage job will do.
* Never write a number you did not measure. DocsMatchRealityTest will catch you.
* Do NOT commit AI/Claude attribution anywhere.
* Always measure before reporting. Never guess an API — READ it. Guessing cost three failed probes in one session.

=== REPORT EACH ITERATION (under 25 lines) ===
  - Reviewer asked + status (or the advisory model's verdict summary)
  - Findings: accepted / rejected / deferred, one line each, with the evidence
  - Fixes landed, each marked mutation-verified YES/NO
  - Suite count before -> after
  - Anything you are deliberately NOT doing, and why
Then immediately begin the next iteration. Do not stop to ask permission.