#!/usr/bin/env python3
"""
Append a triage note SAFELY. The one sanctioned way to write triage.json.

    python3 .loop/triage_add.py "audit/some-key" "#OPEN - what is true" "more detail..."
    printf '%s\n' "note text" | python3 .loop/triage_add.py --stdin audit/some-key
    printf '%s\n' "what landed" | python3 .loop/triage_add.py --stdin audit/some-key --status=RESOLVED

Why this exists, in a number rather than a resolution: hand-written triage writes in
this loop produced a status-marker failure EIGHT times — `.append(a, b)` instead of
`.extend([a, b])` (77, 79, 92, 146) and `t[key] = [...]` replacing the list and
wiping a marker another tool had stamped (130, 143, 152). Every one was caught by
`TriageStatusMarkerTest`, so the guard was doing its job and the WRITER was the bug.
A rule enforced by attention is a habit; this is the mechanism.

Two guarantees, both enforced here rather than remembered:
  1. For an `audit/` key, the FIRST note always opens with a status marker. A new
     key is stamped `#OPEN`; an existing key keeps whatever it has.
  2. Nothing is written unless the marker check passes, so a malformed note fails
     loudly at the point of the mistake instead of at the next commit.
"""
import json
import os
import sys
import tempfile

TRIAGE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "triage.json")
MARKERS = ("#RESOLVED", "#OPEN", "#CLOSED", "#RETRACTED", "#PARTLY")
# A `#PARTLY` note also satisfies a bare `#RESOLVED`-family check upstream of it.
KNOWN_KEYS: dict[str, list[str]] = {}


def _has_marker(note: str) -> bool:
    first = note.lstrip().split(" ", 1)[0]
    return any(first.startswith(m) for m in MARKERS)


def main(argv: list[str]) -> int:
    if len(argv) >= 2 and argv[1] == "--stdin":
        # Read the notes from STDIN, one per line. Passing text as shell ARGV is
        # not safe: backticks inside a double-quoted shell string are executed as
        # command substitution, which destroyed the content THREE times in two
        # iterations even with the marker mechanism in place. The mechanism
        # protected the JSON but the SHELL had already mangled the text before
        # the script saw it, so the guarantee has to cover the whole path.
        key = argv[2]
        status = "OPEN"
        if len(argv) >= 4 and argv[3].startswith("--status="):
            status = argv[3].split("=", 1)[1].strip().lstrip("#").upper()
        notes = [ln.rstrip("\n") for ln in sys.stdin.read().split("\n") if ln.strip()]
        if status != "OPEN" and notes and not notes[0].startswith("#"):
            notes = [f"#{status} - {notes[0]}"] + notes[1:]
    else:
        if len(argv) < 3:
            print(__doc__)
            return 2
        key = argv[1]
        notes = list(argv[2:])

    with open(TRIAGE, encoding="utf-8") as fh:
        triage = json.load(fh)

    if key.startswith("audit/") and triage.get(key):
        if not _has_marker(triage[key][0]):
            print(f"REFUSING TO WRITE: {key} has no status marker on its first note", file=sys.stderr)
            return 1
    for n in notes:
        if not n.strip():
            print("REFUSING TO WRITE: empty note", file=sys.stderr)
            return 1

    existing = triage.setdefault(key, [])
    if key.startswith("audit/") and not existing:
        existing.append("#OPEN - status not stated; set it when the work closes.")
    existing.extend(notes)

    if key.startswith("audit/") and not _has_marker(existing[0]):
        print(f"REFUSING TO WRITE: post-write check failed for {key}", file=sys.stderr)
        return 1

    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(TRIAGE))
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        json.dump(triage, fh, indent=1)
    os.replace(tmp, TRIAGE)
    print(f"  noted: {key} ({len(existing)} notes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
