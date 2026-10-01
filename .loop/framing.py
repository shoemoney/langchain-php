"""Shared reviewer framing — emitted by BOTH document paths.

357 measured that the ledger-caution added to `build_packet.py` had no effect on the advisory: the
advisory builds its own brief and never reads the packet. Two files, two documents, one missing
instruction. That is the same shape as 339 (an Anthropic wire fix landing without the OpenAI side
being checked) — a consumer assumed to share a producer's context.

So the text lives HERE, once, and both `build_packet.py` and `advisory.py` import it. Duplicating the
paragraphs into both files would be the smaller mistake; leaving them in one is the one that produced
the gap. Adding a third consumer now means importing, not remembering.
"""

LEDGER_CAUTION = """\
**THE LEDGER FILES BELOW ARE A RECORD OF WORK ALREADY DONE — NOT A LIST OF OPEN DEFECTS.**
Reviews have got this backwards. A `<!-- fix:HASH -->` row in PORT_STATUS.md means a defect was found,
fixed and pinned by a test. Reporting one of those rows back as a new finding is a FALSE POSITIVE:

  - review 66 re-reported the empty-object-encodes-as-`[]` checkpoint bug, fixed at `fce8bc4`, and
    cited `src/LangGraph/Pregel/Checkpoint/JsonPlusEncoder.php` — a path that does not exist. The real
    one is `src/LangGraph/Checkpoint/Serde/JsonPlusEncoder.php`.
  - the 340 advisory padded one `RunnableBinding` claim across all seven of its sections, citing
    PORT_STATUS as its only evidence.

So, precisely:
  - Do NOT cite a PORT_STATUS or HANDOFF line as evidence. They are the ledger of CLOSED work.
  - To claim a recorded fix is wrong or regressed, quote the CODE LOCATION that contradicts it.
  - A file path that does not exist is not a finding. Check it.
  - Prefer evidence from the live source in this document.\
"""

COMMENT_CAUTION = """\

**THE SAME IS TRUE OF COMMENTS IN THE SOURCE.** This port documents its deliberate decisions inline,
quoting the upstream line and naming the symptom the bug produced. A comment like "upstream writes
`!input` (chat.ts:144) where an EMPTY ARRAY IS TRUTHY" is a FIXED divergence, not a live bug — reading
it as a defect is the most common false positive this loop sees:

  - `MessagesPlaceholder.php:59-64` — explains why `[]` is deliberately NOT absent; the guard below is
    the fix.
  - `BasePromptTemplate.php:151-164` — explains that an earlier `(string)` cast corrupted a callable
    partial; the line below it is the corrected version.
  - `ChatAnthropic.php:405` — explains that `is_string()` silently returned `''` for every multi-block
    answer; the `stringifyText()` call below it is the fix.

So: **if a comment explains WHY the code is shaped as it is and quotes the upstream line, the code
below it is a COMPLETED DECISION.** A finding about it needs a specific reason why that reasoning is
wrong.\
"""


def framing() -> str:
    """Both cautions, for either document path."""
    return LEDGER_CAUTION + COMMENT_CAUTION
