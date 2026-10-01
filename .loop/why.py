#!/usr/bin/env python3
"""Explain a line, in one command.

    python3 .loop/why.py src/LangChain/Runnables/RunnableSequence.php 158
    python3 .loop/why.py RunnableSequence.php 158

Iteration 380 measured the shape of this loop's dominant review failure: class-(2) findings — a
correct file:line, a correct mechanism, and the wrong idea of whether that line is FIXED — because this
port documents its decisions at the point of repair. Refuting one by hand took two greps and a read
(iteration 363). This makes it one.

The reason it is a tool rather than another framing paragraph: class-(2) is not caused by what the model
was told, it is caused by what the repository does, and 380 concluded the correct response is cheap triage
rather than a better prompt. This is the cheap triage.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def resolve(path_arg: str) -> Path:
    p = Path(path_arg)
    if p.is_file():
        return p.resolve()
    for base in (ROOT / "src", ROOT / "tests", ROOT):
        cand = base / path_arg
        if cand.is_file():
            return cand
    hits = list(ROOT.rglob(path_arg))
    if len(hits) == 1:
        return hits[0]
    if len(hits) > 1:
        print(f"  {path_arg} is ambiguous:", file=sys.stderr)
        for h in hits[:8]:
            print(f"    {h.relative_to(ROOT)}", file=sys.stderr)
        sys.exit(1)
    print(f"  cannot find {path_arg}", file=sys.stderr)
    sys.exit(1)


COMMENT_START = re.compile(r"^\s*(/\*\*|\*|//|#)")


def main() -> int:
    args = [a for a in sys.argv[1:] if not a.startswith("-")]
    if len(args) < 2:
        print(__doc__)
        return 1
    path = resolve(args[0])
    try:
        line_no = int(args[1])
    except ValueError:
        print(f"  {args[1]!r} is not a line number", file=sys.stderr)
        return 1

    lines = path.read_text(encoding="utf-8", errors="replace").split("\n")
    if not (1 <= line_no <= len(lines)):
        print(f"  {path.name} has {len(lines)} lines; {line_no} is out of range")
        return 1

    print(f"\n  {path.relative_to(ROOT)}:{line_no}\n  {'-' * 68}")
    # walk UP while the previous line is still comment/docblock text
    start = line_no - 1
    while start > 0 and COMMENT_START.match(lines[start - 1]):
        start -= 1
    # and a couple of lines of code context after
    end = min(len(lines), line_no + 3)
    for i in range(start, end):
        mark = ">>" if i == line_no - 1 else "  "
        print(f"{mark} {i + 1:>5} | {lines[i]}")

    body = "\n".join(lines[start:end])
    print("  HINTS")
    hits = 0
    for word, why in (
        ("fix:", "this comment records a fix — check PORT_STATUS for its <!-- fix:HASH --> row before calling it a bug"),
        ("upstream", "this comment quotes upstream; the port is a port, so matching upstream is correct, not a defect"),
        ("deliberately", "a deliberate divergence — check PORT_STATUS 'Known non-exact behaviours'"),
        ("not ported", "a documented unported subsystem — out of scope for a faithful port"),
        ("tracked, not fixed", "a KNOWN GAP, still open — this is a real assignment, not a closed item"),
    ):
        if word in body:
            hits += 1
            print(f"    - {why}")
    if hits == 0:
        print("    - NO fix/decision comment encloses this line. Either the finding's line number is")
        print("      wrong, or the code here is genuinely undocumented - both worth knowing before")
        print("      calling it a defect.")
    print()
    return 0


if __name__ == "__main__":
    sys.exit(main())
