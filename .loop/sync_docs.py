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


def measure() -> tuple[str, str]:
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
    if not (tests and tests.isdigit() and assertions and assertions.isdigit()):
        sys.exit(f"junit log gave no usable counts: tests={tests!r} assertions={assertions!r}")

    return tests, assertions


def main() -> None:
    tests, assertions = measure()
    print(f"  measured (full suite, clean): {tests} tests, {assertions} assertions")

    hand = ROOT / "HANDOFF.md"
    s = hand.read_text(encoding="utf-8")
    s, n = re.subn(
        r"\| Tests \| \*\*\d+ passing, \d+ assertions\*\* \|",
        f"| Tests | **{tests} passing, {assertions} assertions** |",
        s, count=1,
    )
    if n != 1:
        sys.exit("HANDOFF.md has no '| Tests | **N passing, M assertions** |' row to update")
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

    # BOTH counts. This previously updated only the test-file figure, so adding
    # a src file left the Size row stale and DocsMatchRealityTest failed on a
    # number the script was supposed to own.
    def count(where: str) -> int:
        return sum(1 for _ in (ROOT / where).rglob("*.php"))

    test_files = count("tests")
    src_files = count("src")

    hand = ROOT / "HANDOFF.md"
    s = hand.read_text(encoding="utf-8")
    s = re.sub(r"· \d+ test files", f"· {test_files} test files", s, count=1)
    s = re.sub(r"\| Size \| \d+ src files", f"| Size | {src_files} src files", s, count=1)
    hand.write_text(s, encoding="utf-8")

    print(f"  HANDOFF.md + PORT_STATUS.md synced to {tests} / {assertions}, {src_files} src + {test_files} test files")


if __name__ == "__main__":
    main()
