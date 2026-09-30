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


def measure() -> tuple[str, str]:
    """The FULL suite — bare `phpunit`, the same command `composer test` and
    the CI unit+integration steps run. Not `--testsuite unit`: that omits the
    integration testsuite and undercounts by ten."""
    JUNIT.parent.mkdir(exist_ok=True)
    proc = subprocess.run(
        ["./vendor/bin/phpunit", "--log-junit", str(JUNIT)],
        cwd=ROOT, capture_output=True, text=True,
    )
    if not JUNIT.exists():
        sys.exit("phpunit wrote no junit log; refusing to guess\n" + proc.stdout[-600:])

    root = ET.parse(JUNIT).getroot()
    suite = root.find("testsuite") if root.tag == "testsuites" else root
    tests, assertions = suite.get("tests"), suite.get("assertions")
    failures, errors = suite.get("failures"), suite.get("errors")

    if failures != "0" or errors != "0":
        sys.exit(
            f"refusing to record a failing run as the truth: failures={failures} errors={errors}\n"
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

    test_files = sum(1 for _ in (ROOT / "tests").rglob("*.php"))
    hand = ROOT / "HANDOFF.md"
    s = hand.read_text(encoding="utf-8")
    s = re.sub(r"· \d+ test files", f"· {test_files} test files", s, count=1)
    hand.write_text(s, encoding="utf-8")

    print(f"  HANDOFF.md + PORT_STATUS.md synced to {tests} / {assertions}, {test_files} test files")


if __name__ == "__main__":
    main()
