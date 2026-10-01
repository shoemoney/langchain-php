#!/usr/bin/env python3
"""Check every path a review cites, before anyone triages it by reading.

    python3 .loop/citations.py .loop/aaa/7-openai__gpt-4o-mini-2024-07-18.md

Iteration 413: three findings, three paths that did not exist. Two were rejected with one command
instead of with a paragraph of reasoning, and the third turned out to name the right file in the wrong
directory AND land on unrelated code at the cited line. `.loop/framing.py` already tells the model
"a file path that does not exist is not a finding — check it", and it did not apply the rule to its own
citations. So the pipeline applies it instead.

A plausible-looking citation is indistinguishable from a real one by reading — the namespace reads
correctly, the class name reads correctly, the line is plausible. Only `test -f` separates them. This is
the fourth time in this run a one-command check has done work that careful reading could not.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# `src/LangChain/Utils/Completions.php:45`, optionally in backticks, with or without a `L`-prefix.
CITE = re.compile(r"`?((?:src|tests)/[A-Za-z0-9_./-]+\.php)(?::(\d+))?`?")


def resolve(path_arg: str) -> Path | None:
    p = ROOT / path_arg
    if p.is_file():
        return p
    hits = [h for h in ROOT.rglob(Path(path_arg).name) if h.is_file()]
    if len(hits) == 1:
        return hits[0]
    return None


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 1

    review = Path(sys.argv[1])
    if not review.is_file():
        print(f"  cannot read {review}")
        return 1

    text = review.read_text(encoding="utf-8", errors="replace")

    # Does the LEDGER already record a fix for the thing this citation points at?
    #
    # Iterated 415 recorded the pattern: a fix recorded in PORT_STATUS and pinned by a test is STILL
    # re-reported, because a reviewer reading the code sees the cast and reads it as a DESCRIPTION
    # rather than a fix. Asking "has this already been fixed?" should be a lookup, not a reading.
    #
    # BUT THE LEDGER IS CONCEPT-KEYED, NOT FILE-KEYED, and that is worth stating rather than papering
    # over: a row reads "**An empty argument object satisfies an object schema** <!-- fix:7563354 -->"
    # and never names a `.php` file. A first attempt at this check matched on `` `Some/File.php` `` in the
    # row's first cell and could therefore never fire — a cross-check that cannot succeed is a check that
    # cannot be trusted, which is the 414 lesson arriving in a new place. So the search below is over the
    # row TEXT, keyed on the cited file's basename and the words around it, and it is deliberately
    # advisory: it surfaces candidate rows to read, it does not decide.
    ledger = ROOT / "PORT_STATUS.md"
    fix_rows: list[str] = []
    if ledger.is_file():
        fix_rows = [
            line.strip()
            for line in ledger.read_text(encoding="utf-8", errors="replace").split("\n")
            if "<!-- fix:" in line
        ]

    # One entry per cited path, with every line number cited for it.
    cited: dict[str, list[str]] = {}
    for m in CITE.finditer(text):
        cited.setdefault(m.group(1), []).append(m.group(2) or "?")

    if not cited:
        print("  no path citations found")
        return 0

    bad = 0
    for path, lines in sorted(cited.items()):
        mark = "OK     "
        note = ""
        if not (ROOT / path).is_file():
            guess = resolve(path)
            if guess is None:
                mark = "MISSING"
                bad += 1
            else:
                rel = guess.relative_to(ROOT)
                mark = "MOVED  "
                note = f"  (actually {rel})"
        print(f"  [{mark}] {path}:{','.join(sorted(set(lines)))}{note}")

        if fix_rows:
            stem = Path(path).stem.lower()
            hits = [r for r in fix_rows if stem and stem in r.lower()]
            if hits:
                print(f"           ^ {len(hits)} LEDGER fix row(s) mention this file — READ BEFORE CALLING IT A DEFECT:")
                for r in hits[:2]:
                    print(f"             {r[:100]}")

    total = len(cited)
    print(f"\n  {total} distinct path(s) cited, {bad} do not exist.")
    if bad:
        print("  Every MISSING path is a fabricated citation. Reject before reading the prose.")
    print("  A LEDGER line means: read the fix row BEFORE deciding this is a defect.")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
