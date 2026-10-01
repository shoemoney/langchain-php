#!/usr/bin/env python3
"""Classify how a review or advisory USES the ledger, not whether it names it.

    python3 .loop/ledger_tone.py .loop/advisory/ernie-4-5-vl-424b-a47b.md

Iteration 420's finding: `grep -c PORT_STATUS` was being used as the metric for whether the framing
worked, and it cannot answer the question. Five mentions of PORT_STATUS in one advisory were two
affirmations ("lists deliberate divergences", "align with the codebase"), an observation ("may drift if
not validated"), a suggestion ("validate PORT_STATUS against live code") and a meta-comment about this
loop — and NOT ONE was the failure mode the caution targets, which is citing the ledger as evidence that a
defect exists.

So this counts only ACCUSATIONS. A bare mention scores nothing, on the principle that has now cost this
loop twice (309's case-sensitive probe, 414's existence-only check): a count that cannot distinguish the
cases that matter is worse than no count, because it returns confidence.
"""
import re
import sys
from pathlib import Path

LEDGER = re.compile(r"PORT_STATUS|HANDOFF")

# Ordered, and THE ORDER IS THE DESIGN: the first pattern that matches a line classifies it.
#
# META is checked FIRST, ahead of ACCUSATION, because of a real disagreement this tool surfaced against
# the hand-reading it was written from. The line
#
#     "PORT_STATUS.md and HANDOFF.md describe the port's status, but the brief notes that prior reviews
#      misinterpreted stale or out-of-context entries"
#
# contains "stale" and "misinterpreted", so with ACCUSATION first it scored as an accusation. It is not
# one — it is a remark ABOUT THE REVIEW PROCESS, and a sentence about how earlier reviews misread the
# ledger is not evidence that the code is broken no matter which words appear in it. A line that talks
# about the loop cannot also be a claim about the code, so it is classified before the bug-word patterns
# get a chance to fire.
RULES: list[tuple[str, re.Pattern[str]]] = [
    ("META", re.compile(
        r"\b(?:this (?:brief|review|packet|advisory)|the brief (?:states|notes)|prior reviews?|"
        r"previously (?:flagged|reported)|earlier reviews?)\b", re.I)),
    ("SUGGESTION", re.compile(
        r"\b(?:should|recommend|integrate|add|validate|consider|propose|next step|would be (?:good|better))\b", re.I)),
    ("ACCUSATION", re.compile(
        r"\b(?:missing|absent|not (?:implemented|reflected|covered|ported)|ignored|never (?:read|called|used)|"
        r"no such|fails?|broken|wrong|incorrect|stale|unimplemented|lies?|inaccurate|fabricat)", re.I)),
    ("AFFIRMATION", re.compile(
        r"\b(?:accurately|align(?:s|ed)? with|correctly|accurate|"
        r"lists? (?:deliberate|the)|marked as|carries? (?:a|the) reason|justif\w+)\b", re.I)),
]


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 1

    path = Path(sys.argv[1])
    if not path.is_file():
        print(f"  cannot read {path}")
        return 1

    counts: dict[str, list[str]] = {}
    for raw in path.read_text(encoding="utf-8", errors="replace").split("\n"):
        if not LEDGER.search(raw):
            continue
        label = "MENTION"
        for name, rx in RULES:
            if rx.search(raw):
                label = name
                break
        counts.setdefault(label, []).append(raw.strip())

    for label in ("AFFIRMATION", "ACCUSATION", "SUGGESTION", "META", "MENTION"):
        for line in counts.get(label, []):
            print(f"  [{label:<11}] {line[:110]}")

    acc = len(counts.get("ACCUSATION", []))
    total = sum(len(v) for v in counts.values())
    print(f"\n  {total} ledger mention(s); ACCUSATIONS (the failure mode): {acc}")
    if acc == 0:
        print("  No accusation — the framing's target behaviour is absent from this document.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
