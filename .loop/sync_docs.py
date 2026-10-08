#!/usr/bin/env python3
"""Write the measured suite size into PORT_STATUS.md and HANDOFF.md.

Every attempt to do this by parsing PHPUnit's console output in an ad-hoc shell
one-liner has gone wrong at least once, in three different ways:

  * the regex did not match PHPUnit's summary line, so the write silently
    produced an EMPTY row — and DocsMatchRealityTest failed on `main`, which
    is precisely the failure it exists to catch;
  * a second attempt measured `--testsuite unit` (2106) while the ledger
    claims the suite `composer test` runs (2116), because bare `phpunit` runs
    the integration testsuite too;
  * a third measured a run that was FAILING and recorded its partial count as
    the truth.

So: read the counts from a JUnit log (unambiguous), measure the same thing CI
measures, and refuse to write anything unless that run was clean.

    python3 .loop/sync_docs.py
"""

import re
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
JUNIT = ROOT / "build" / "sync-docs-junit.xml"


def failing_tests(junit: Path) -> set[str]:
    """The names of every test that failed or errored in a junit log."""
    root = ET.parse(junit).getroot()
    suite = root.find("testsuite") if root.tag == "testsuites" else root
    names: set[str] = set()
    for case in suite.iter("testcase"):
        if case.find("failure") is not None or case.find("error") is not None:
            names.add((case.get("class") or "") + "::" + (case.get("name") or ""))
    return names


def measure() -> tuple[str, str, str]:
    """The FULL suite — bare `phpunit`, the same command `composer test` and
    the CI unit+integration steps run. Not `--testsuite unit`: that omits the
    integration testsuite and undercounts by ten."""
    JUNIT.parent.mkdir(exist_ok=True)
    proc = subprocess.run(
        ["./vendor/bin/phpunit", "--log-junit", str(JUNIT)],
        cwd=ROOT, capture_output=True, text=True,
    )
    # Order matters. A suite that cannot even LOAD must be rejected BEFORE
    # anything parses the log: PHPUnit aborts while collecting and leaves an
    # EMPTY junit file, so `ET.parse` raises a bare ParseError, and a suite that
    # loads but crashes mid-run can write a junit whose counts describe however
    # far it got. Both would otherwise be recorded as truth.
    #
    # Verified: a parse error in one test file previously produced a traceback
    # from ET.parse, and an earlier version recorded the truncated counts.
    fatal_markers = (
        "Fatal error", "Parse error", "Cannot declare class",
        'No tests executed', 'Cannot open file',
    )
    output = (proc.stdout or "") + (proc.stderr or "")
    hit = [m for m in fatal_markers if m in output]
    if hit:
        sys.exit(
            f"refusing: phpunit did not complete cleanly ({', '.join(hit)}); its counts describe "
            f"however far it got, not the suite.\n" + output[-600:]
        )

    if not JUNIT.exists() or JUNIT.stat().st_size == 0:
        sys.exit("phpunit wrote no usable junit log; refusing to guess\n" + output[-600:])

    root = ET.parse(JUNIT).getroot()
    suite = root.find("testsuite") if root.tag == "testsuites" else root
    tests, assertions = suite.get("tests"), suite.get("assertions")
    skipped = suite.get("skipped") or "0"
    failures, errors = suite.get("failures"), suite.get("errors")

    # Bootstrap: the guard that enforces these numbers is itself a test, so a
    # stale count makes the suite red, and refusing to sync on a red suite makes
    # the script unable to ever fix it. Deadlock. So the ONE tolerated failure is
    # DocsMatchRealityTest — the check that exists to say "these numbers are
    # wrong", which is precisely what this script is about to correct. Any OTHER
    # failure means the counts would be recording a broken run, and that is
    # refused.
    stale_docs_only = failing_tests(JUNIT)
    tolerated = {"DocsMatchRealityTest"}
    offending = [t for t in stale_docs_only if not any(k in t for k in tolerated)]

    if failures != "0" or errors != "0":
        if not offending:
            print(f"  (bootstrapping: the only failures are {sorted(stale_docs_only)}, which this script fixes)")
        else:
            sys.exit(
                f"refusing to record a failing run as the truth. Unrelated failures: {sorted(offending)}\n"
                + proc.stdout[-600:]
            )
    if not (tests and tests.isdigit() and assertions and assertions.isdigit() and skipped.isdigit()):
        sys.exit(f"junit log gave no usable counts: tests={tests!r} assertions={assertions!r} skipped={skipped!r}")

    return tests, assertions, skipped


def write_counts(tests: str, assertions: str, skipped: str) -> None:
    """Write the measured counts into both documents.

    Skipped tests (env-gated integration suites with no server configured) are
    stated separately: counting them as "passing" made the row claim 455 tests
    passed that never ran (the MongoDB integration spec, Wave 2)."""
    passing = int(tests) - int(skipped)
    hand = ROOT / "HANDOFF.md"
    s = hand.read_text(encoding="utf-8")
    s, n = re.subn(
        r"\| Tests \| \*\*[^|]*assertions\*\* \|",
        f"| Tests | **{tests} tests ({passing} passing, {skipped} skipped), {assertions} assertions** |",
        s, count=1,
    )
    if n != 1:
        sys.exit("HANDOFF.md has no '| Tests | **... assertions** |' row to update")
    hand.write_text(s, encoding="utf-8")

    status = ROOT / "PORT_STATUS.md"
    s = status.read_text(encoding="utf-8")
    s, n = re.subn(
        r"\| \*\*Total so far\*\* \| \| \*\*\d+\*\* \|",
        f"| **Total so far** | | **{tests}** |",
        s,
    )
    if n != 1:
        sys.exit("PORT_STATUS.md has no '**Total so far**' row to update")
    status.write_text(s, encoding="utf-8")

    # Files AND lines, for both trees.
    #
    # This previously updated neither line count: `sync_docs.py` had ZERO
    # mentions of "lines", so the Size row's totals were frozen at whatever they
    # were when last typed by hand. Measured drift: HANDOFF.md claimed 33,493
    # src and 21,361 test lines against a real 33,984 and 23,776 — 491 and 2,415
    # lines out of date, on a row that is supposed to describe the code as it is.
    #
    # It survived because DocsMatchRealityTest checks test counts and FILE
    # counts, and a line count is none of those. A number nothing measures is a
    # number nothing keeps true.
    #
    # CORRECTED (487): this comment previously said DocsMatchRealityTest checks
    # test counts, ASSERTION counts and file counts. It does not check assertion
    # counts — there is no such assertion in that class. The identical false
    # claim stood in its own docblock, so the two agreed with each other and
    # neither was true, which is the strongest way for a comment to be believed.
    # Consequence, measured rather than argued: this script is the ONLY thing
    # that writes the assertion count, and nothing verifies it, so it was the
    # last number in HANDOFF.md to drift — it sat at 9,328 against a measured
    # 9,329. It cannot be guarded from inside the suite (a test cannot observe an
    # assertion total without recursively running the suite); the guard belongs
    # in CI as `sync_docs.py && git diff --exit-code`. Not added in 487.
    def measure_tree(where: str) -> tuple[int, int]:
        files = sorted(p for p in (ROOT / where).rglob("*.php") if p.is_file())
        lines = 0
        for p in files:
            with p.open("rb") as f:
                lines += sum(1 for _ in f)
        return len(files), lines

    test_files, test_lines = measure_tree("tests")
    src_files, src_lines = measure_tree("src")

    s = hand.read_text(encoding="utf-8")
    s, n = re.subn(
        r"\| Size \| [^|]*\|",
        f"| Size | {src_files} src files / {src_lines:,} lines "
        f"· {test_files} test files / {test_lines:,} lines |",
        s,
        count=1,
    )
    if n != 1:
        sys.exit("HANDOFF.md has no '| Size | ... |' row to update")
    hand.write_text(s, encoding="utf-8")

    print(
        f"  HANDOFF.md + PORT_STATUS.md written: {tests} / {assertions}, "
        f"{src_files} src + {test_files} test files"
    )


def main() -> None:
    # CONVERGE, DO NOT MEASURE ONCE (487).
    #
    # `measure()` deliberately TOLERATES a DocsMatchRealityTest failure — the
    # bootstrap deadlock documented above. But that tolerance is precisely the
    # hole: this script's own output is what makes that test pass, so the run it
    # measures is red exactly when its numbers are stale, and a red
    # DocsMatchRealityTest UNDERCOUNTS. PHPUnit stops a test method at its first
    # failed assertion, so `testHandoffFileCountsAreCurrent` contributes one
    # assertion instead of two.
    #
    # Measured, both directions, on demand:
    #
    #     docs stale   ->  Tests: 4306, Assertions: 9337, Failures: 2
    #     docs correct ->  OK (4306 tests, 9338 assertions)
    #
    # So a single measurement can record 9,337 into the ledger when the truth is
    # 9,338 — and that off-by-one is a permanent, self-inflicted ledger lie,
    # written by the very script meant to keep the ledger true. It is almost
    # certainly where this session's opening 9,328-vs-9,329 drift came from.
    #
    # Fix: write, then MEASURE AGAIN, and only accept a reading that a second,
    # now-green run reproduces. One pass of the loop costs ~10s; correctness of a
    # number every other guard reads is worth it.
    for attempt in range(1, 4):
        tests, assertions, skipped = measure()
        print(f"  measured (full suite): {tests} tests ({skipped} skipped), {assertions} assertions")
        write_counts(tests, assertions, skipped)

        after_tests, after_assertions, after_skipped = measure()
        if (after_tests, after_assertions, after_skipped) == (tests, assertions, skipped):
            print(f"  stable at {tests} tests / {assertions} assertions after {attempt} pass(es)")
            return

        print(
            f"  UNSTABLE: writing the docs moved the count "
            f"{tests}/{assertions} -> {after_tests}/{after_assertions}; re-syncing"
        )

    sys.exit(
        "counts did not converge in 3 passes; refusing to leave a number I could not "
        "confirm. Check for a test whose assertion count depends on the documents."
    )


if __name__ == "__main__":
    main()
