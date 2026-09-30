# The review loop

`ask.py` asks one vision-capable model per iteration for exactly five
improvements, attaches `.loop/arch.png` and `.loop/packet.md`, and records the
answer. `plan.py` regenerates the accumulated ledger from every review; the
triage verdicts live in `triage.json`.

## What the harness has learned

- **A reasoning model can spend the entire output budget thinking and return
  nothing.** `reasoning.effort: low` fixes it where the vendor honours it.
- **The compressed retry must point every knob the same way — down.** The first
  version RAISED `max_tokens`, which is precisely wrong for a model that fills
  its budget thinking: it handed the runaway more room. Fewer findings, a much
  smaller packet, and a LOWER ceiling.
- **Some vendors ignore `max_tokens` outright.** Cohere reported 24,384
  reasoning tokens against a 7,000-token cap. Nothing the client sends can bound
  that, so those models are abandoned after two attempts.
- **A small-context model needs a packet scaled to ITS context, and some lie
  about the arithmetic.** `reka-edge` reports a 16,384 limit while claiming
  30,787 tokens were requested from a packet its own breakdown puts at 5,342
  text + 1,445 image. There is no client-side budget that satisfies an endpoint
  that mis-reports by 4x, so it is abandoned rather than tuned against.
- **`finish_reason: error` is not a review.** Recording it as `ok` lets a broken
  response count as a completed one.
- **The packet's own bug-history tables make reviewers re-report fixed bugs.**
  The prompt now says so explicitly.
- **A reviewer that cannot point at a defect should not be given one to fix.**
  Roughly a third of all findings raised so far have been rejected on evidence.

## The loop reviews itself

`ask.py --packet` sends `.loop/packet_self.md` instead of the normal packet:
every file this loop added or modified, and nothing else. The gap it fills is
plain — for 27 iterations the only reviewer of the loop's own fixes was the loop
that wrote them, and a same-mind author-and-checker is the arrangement most likely
to hide a mistake. The first run rejected all five findings, which is a result
worth having rather than a disappointing one.

Regenerate it after any round of fixes:
`python3 - <<'PY' ...` (see the snippet in the iteration log) or rebuild from
`git ls-files --others --exclude-standard` + `git diff --name-only`, restricted to `src/`.

## Reviewing the tests needs the source attached

`--packet=tests` sends `.loop/packet_tests.md`: every test the loop wrote,
**each paired with the source file its `CoversClass` names**. The first run of
this packet, without the source, returned five findings that were all
already-fixed code — because a tests-only packet forces the reviewer to infer
the implementation from test names, and it inferred wrong every time.

That was a defect in the harness, not in the code, and it is the clearest
demonstration in this project of the harness shaping the measurement. Pairing
29 of 32 tests with their subject changed the findings completely.

The pairing is resolved from `CoversClass(X::class)` through the file's `use`
statements; the short class name is the key, since that is how the attribute
refers to it.

## Probing rules the loop has learned

- **`json_encode` is the wrong instrument for a byte-level question.** It
  silently returns `false` on invalid UTF-8, which read as "the splitter lost
  the text" when `bin2hex` showed the input preserved byte for byte. A probe that
  renders its subject through a lossy formatter proves nothing about bytes.
- **Verify the mutation actually applied before believing a "survived".** Twice
  this session a mutation script silently matched nothing, and the resulting
  green suite looked exactly like a test that cannot fail.
- **Read the source, then probe it.** Several findings described a `try/catch`,
  a missing guard, or an unread field that do not exist — the code was right and
  the review was not.

## The packet rotates, and that was the fix for running dry

For eleven rounds the packet showed the same eight provider-client files. The
last three rounds produced **zero** accepted findings — the loop looked broken
when in fact it was looking at a solved corner of the code.

`build_packet.py` now cycles an 11-way focus (provider clients, messages &
merge algebra, the LangGraph engine, checkpointing, prompts, output parsers,
tools, composition, tracing, text splitting, model base) with the
`RunnableInterface` in every packet as the seam everything hangs off. The
turning point was measurable: the first rotated packet — the output parsers,
never previously shown to a reviewer — produced five findings immediately.

For scale: LangGraph holds 77 source files and the old fixed list showed one.
