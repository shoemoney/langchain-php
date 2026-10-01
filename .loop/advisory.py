"""Advisory reviewer: one holistic pass over EVERY key element of the port.

The per-iteration reviewer in ask.py is deliberately narrow -- it gets one
rotating subsystem plus a code packet, so a single model cannot see the whole
system. That narrowness is the point (it forces real depth), but it means no
model ever forms an opinion about the port AS A WHOLE: whether the subsystems
compose, whether the layering holds, whether the public API is coherent.

This script closes that gap. It hands one model:
  * the architecture PNG (vision -- how the boxes connect),
  * a full-repo inventory (every namespace, its size, its tests),
  * the real public surface of the core abstractions,
  * a JSON summary of the module dependency graph,
  * a digest of the test suite and what the guards actually assert,
  * the accumulated findings from every prior reviewer.

and asks for a structured verdict per key element, plus a cross-cutting
section that only a holistic reader can produce.

Model:  python3 .loop/advisory.py                     # random eligible model
         python3 .loop/advisory.py --model <id>       # specific
         python3 .loop/advisory.py --list             # who has served
         python3 .loop/advisory.py --digest           # print the brief, no API call
"""

import argparse

import base64
import collections
import json
import os
import random
import re
import subprocess
import sys
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from framing import framing  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
LOOP = ROOT / ".loop"
ENDPOINT = "https://openrouter.ai/api/v1/chat/completions"

# Models that cannot serve a useful advisory pass: routers with no identity,
# safety classifiers, and the decision model (not a chat model).
EXCLUDE = re.compile(
    r"(typesafe/jev|openrouter/(auto|free)|safety|guard|lyria|"
    r"grok-.*multi-agent|^~|perceptron/perceptron-mk1.5)",
    # perceptron/perceptron-mk1.5: every request returns HTTP 200 with
    # "Upstream error from Perceptron: Generation failed." Verified across three
    # shapes (text-only, text+image, system+user) and three max_tokens values,
    # including a 400-token two-message request. The model accepts the payload
    # and cannot generate, so no budget or trim makes it usable. Measured in
    # iteration 158; an exclusion is the honest answer here, not a fix.
    re.I,
)

# The elements a review of a port must always cover. Hard-coded so that a
# lazy or truncated model cannot quietly skip half the system.
ELEMENTS = [
    ("layering", "Namespace boundaries, PSR-4 layout, and whether any class "
                 "reaches across a layer it should not"),
    ("public-api", "The public surface of the abstractions callers actually use "
                   "(BaseChatModel, Runnable, Pregel) — coherent, or leaky?"),
    ("composition", "Do the subsystems actually compose? Traced end to end, or "
                    "merely coexisting?"),
    ("error-handling", "Exception types, causes, and whether a caller can catch "
                       "one type and trust it"),
    ("fidelity", "Fidelity to the TypeScript upstream — places the port "
                 "diverges, and whether each divergence is justified"),
    ("test-strategy", "What the suite proves, what it cannot see, and which "
                      "guards are load-bearing"),
    ("documentation", "Do PORT_STATUS.md and HANDOFF.md describe the code that "
                      "exists?"),
    ("dead-code", "Written-but-unreachable code, orphans, and anything parsed "
                  "then dropped"),
]


def run(cmd):
    return subprocess.run(
        cmd, shell=True, cwd=ROOT, capture_output=True, text=True
    ).stdout.strip()


def inventory():
    """Every namespace, how big it is, and how tested it is."""
    inv = collections.defaultdict(lambda: {"src": 0, "lines": 0, "test_files": 0})
    for p in (ROOT / "src").rglob("*.php"):
        rel = p.relative_to(ROOT / "src")
        ns = str(rel.parent).replace("/", ".")
        inv[ns]["src"] += 1
        inv[ns]["lines"] += len(p.read_text(encoding="utf-8", errors="replace").splitlines())
    # Attribute each test file to the src namespace it covers. Keying tests by
    # their own namespace reported "0 test files" for every src namespace and
    # told the reviewer the suite was blind — it is not, the two sides simply
    # have different namespace names.
    for p in (ROOT / "tests").rglob("*Test.php"):
        rel = p.relative_to(ROOT / "tests").parent.as_posix()
        for prefix in ("Unit/", "Integration/"):
            if rel.startswith(prefix):
                rel = rel[len(prefix):]
                break
        target = rel.replace("/", ".")
        # A test lives at tests/Unit/<ns-path>/XTest.php while its source lives
        # at src/<Vendor>/<ns-path>/X.php, so the test path is a SUFFIX of the
        # src namespace, never equal to it.
        #
        # EVERY match is credited, not just the longest. Longest-suffix alone
        # was demonstrably wrong: tests/Unit/Checkpoint/ matched
        # `LangGraph.Pregel.Checkpoint` (21 chars) over `LangGraph.Checkpoint`
        # (20), so the brief reported 11 and 0 respectively — claiming a
        # namespace with eleven test files had none.
        #
        # That direction of error is the expensive one. Under-reporting coverage
        # does not merely misstate a number, it invites reviewers to report
        # already-fixed gaps: one advisory pass this iteration claimed
        # `LangGraph\Errors` had zero tests, when the brief it was reading said
        # one. Over-attributing is the safe direction, because it cannot
        # manufacture a finding.
        matched = False
        for k in list(inv):
            if k == target or k.endswith("." + target) or target.startswith(k + "."):
                inv[k]["test_files"] += 1
                matched = True
        if not matched:
            inv.setdefault(target, {"src": 0, "lines": 0, "test_files": 0})["test_files"] += 1
    return {k: v for k, v in sorted(inv.items()) if k not in (".", "")}


def strip_php_comments(src: str) -> str:
    """Remove comments, docblocks and attributes so a reference scan sees CODE.

    A `use` statement is the only thing that creates a dependency. Scanning raw
    text counts a PROSE mention as one, and this instrument did: it reported
    `LangChain -> LangGraph: 1 files` for 30+ iterations when the single hit was
    a docblock in `Runnable.php` EXPLAINING why two LangGraph classes implement
    `batch()` themselves. An advisory then built a ranked finding on that edge —
    "one `LangChain\\*` file reaching into `LangGraph\\*` is an inverted
    dependency" — about a comment.

    Order matters: docblocks first, then `//`, then attributes. A naive `//`
    pass would eat the `//` inside a URL or a regex literal that appears inside a
    string, and attributes are stripped because `#[CoversClass(...)]` legitimately
    names classes from the other package in tests, not in src.
    """
    src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
    src = re.sub(r"//[^\n]*", "", src)
    src = re.sub(r"#\[[^\]]*\]", "", src, flags=re.S)
    return src


def dep_edges():
    """Which top-level package references which. Scans CODE, not prose.

    Comments are stripped first: a docblock naming the other package is a
    sentence ABOUT the architecture, not a dependency created by it. Measured,
    that one distinction takes `LangChain -> LangGraph` from 1 to 0 — the port
    has no upward dependency at all, which is what upstream's
    `@langchain/core` having no dependency on `@langchain/langgraph` requires.
    """
    edges = collections.Counter()
    for p in (ROOT / "src").rglob("*.php"):
        body = strip_php_comments(p.read_text(encoding="utf-8", errors="replace"))
        own = p.relative_to(ROOT / "src").parts[0]
        for target in ("LangChain", "LangGraph"):
            if target == own:
                continue
            if re.search(rf"\b{target}\\", body):
                edges[f"{own} -> {target}"] += 1
    return dict(edges)


def public_surface(class_name, limit=40):
    """The public methods of a core abstraction, with signatures."""
    hits = list((ROOT / "src").rglob(f"{class_name}.php"))
    if not hits:
        return f"{class_name}: NOT FOUND"
    src = hits[0].read_text(encoding="utf-8", errors="replace")
    body = re.sub(r"/\*\*.*?\*/", "", src, flags=re.S)  # drop docblocks: too long
    out = []
    for m in re.finditer(r"^\s*public\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)\s*:?\s*([^\n{]*)", body, re.M):
        sig = f"  {m.group(1)}({', '.join(a.strip() for a in m.group(2).split(',') if a.strip())}): {m.group(3).strip()}"
        if len(sig.strip()) > 160:
            sig = sig[:157] + "..."
        out.append(sig.strip())
    return f"--- {hits[0].relative_to(ROOT)} ({len(out)} public methods) ---\n" + "\n".join(out[:limit])


def test_digest():
    out = run("./vendor/bin/phpunit --list-tests 2>/dev/null")
    n = len(re.findall(r"^ - ", out, re.M))
    res = run("composer test 2>&1 | tail -3")
    guards = []
    for name in ("PhpVersionCompatibilityTest", "DocsMatchRealityTest", "TransportExceptionContractTest"):
        p = list((ROOT / "tests").rglob(f"{name}.php"))
        if p:
            guards.append(f"  {name}: " + ", ".join(re.findall(r"public function (test\w+)", p[0].read_text(encoding="utf-8", errors="replace"))))
    return f"tests enumerated: {n}\nresult: {res}\nload-bearing guards:\n" + "\n".join(guards)


def prior_findings(limit=45):
    """What every previous reviewer already said, so the advisor does not repeat it."""
    t = json.loads((LOOP / "triage.json").read_text())

    # Status first, then detail. Taking the last N lines across every key worked
    # until one key accumulated more notes than the budget: `audit/stepconfig-clobber`
    # reached 33 notes, its resolution was pushed out of the tail, and an advisory
    # recommended a defect that had been fixed and mutation-verified two iterations
    # earlier as outstanding. That is the iteration-60 stale-verdict failure again,
    # with a different cause — not a stale entry but a truncated one.
    #
    # So each key contributes its FIRST note (which now carries a RESOLVED banner
    # when the work is closed) and its LAST note (the most recent state), deduped,
    # before any middle notes fill the remaining budget.
    status, recent, filler = [], [], []
    for model, items in t.items():
        if not items:
            continue
        status.append(f"[{model}] {items[0]}")
        if len(items) > 1:
            recent.append(f"[{model}] {items[-1]}")
        for it in items[1:-1]:
            filler.append(f"[{model}] {it}")

    # `audit/` keys are the investigations and carry the RESOLVED banners; with
    # 125 keys against a 45-line budget they have to go first or the cap silently
    # decides which defects an advisory is allowed to know about, which is not a
    # decision a truncation should be making.
    def rank(entry: str) -> tuple[int, str]:
        return (0 if "] #RESOLVED" in entry or "[audit/" in entry else 1, entry)

    status.sort(key=rank)
    recent.sort(key=rank)

    lines = status + recent
    if len(lines) < limit:
        lines += filler[-((limit - len(lines))):]

    return "\n".join(lines[:limit])


def declared_class(path: Path) -> str | None:
    """The type a src file declares, from its own `class`/`interface`/`enum` line.

    Parsed rather than taken from the filename: this tree has function-only files
    and the PSR-4 path has been wrong before (five files once landed in
    `LanguageModels/Chat/` instead of `Chat/OpenAI/`, so filename-based counting
    would have credited the wrong namespace).
    """
    src = path.read_text(encoding="utf-8", errors="replace")
    m = re.search(
        r"^\s*(?:final\s+|abstract\s+|readonly\s+)*"
        r"(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)",
        src,
        flags=re.M,
    )
    return m.group(1) if m else None


def reference_counts() -> dict[str, int]:
    """How many files reference each src class, counting CODE only.

    This exists because a prose warning did not work. The brief already told the
    reviewer, in three paragraphs, that the "test files" column counts test paths
    and NOT usage — and `claude-fable-5-1` still reported `LangChain\\Schema` as
    dead code with "0 test files" and "0 reference data", then suggested
    "delete or merge any with zero non-test referrers". Measured, those four
    classes have 3, 9, 13 and 6 referrers, and all four are covered by tests.

    The column is a DIRECTORY count, so a shared abstraction used from four
    different namespaces reads as untested no matter how many tests touch it.
    Nothing about that is fixable by telling the reader to be careful; the
    advisory already did that and the warning was ignored. So the number is
    supplied instead of the caution — and `LangChain\\Schema` is the case that
    proves it, since no amount of care with a directory count yields the truth.

    Comments and docblocks are stripped, because a sentence ABOUT a class is not
    a reference to it: `Runnable.php` names two LangGraph classes in a docblock
    and that is prose about the architecture, not a dependency (see
    `strip_php_comments`).
    """
    src_files = sorted((ROOT / "src").rglob("*.php"))
    # One pass over every file, remembering the stripped text for reuse.
    bodies: dict[Path, str] = {}
    for p in src_files + sorted((ROOT / "tests").rglob("*.php")):
        bodies[p] = strip_php_comments(p.read_text(encoding="utf-8", errors="replace"))

    counts: dict[str, int] = {}
    for p in src_files:
        name = declared_class(p)
        if name is None:
            continue
        pat = re.compile(rf"\b{re.escape(name)}\b")
        n = 0
        for other, body in bodies.items():
            if other == p:
                continue
            if pat.search(body):
                n += 1
        counts[name] = n
    return counts


def least_referenced(refs: dict[str, int], inv: dict[str, dict], limit: int = 12) -> str:
    """The classes with the FEWEST referrers — the only ones a reader should doubt.

    Sorted ascending so a genuine orphan sits at the top of the list rather than
    being buried under 236 healthy names. Anything here is a QUESTION for the
    reviewer, not a verdict: a class referenced once is not dead, and a class
    referenced only by tests is reachable but unintegrated.
    """
    rows = []
    for path in sorted((ROOT / "src").rglob("*.php")):
        name = declared_class(path)
        if name is None:
            continue
        rows.append((refs.get(name, 0), name, str(path.relative_to(ROOT))))
    rows.sort(key=lambda r: (r[0], r[1]))
    out = ["  referrers  class                                          path"]
    for n, name, path in rows[:limit]:
        flag = "   <-- ORPHAN?" if n == 0 else ""
        out.append(f"  {n:>9}  {name:<45} {path}{flag}")
    return "\n".join(out)


def brief():
    inv = inventory()
    top = sorted(inv.items(), key=lambda kv: -kv[1]["src"])[:22]
    edges = dep_edges()
    refs = reference_counts()
    orphans = sum(1 for v in refs.values() if v == 0)
    return f"""# ADVISORY BRIEF — langchain-php

{framing()}

A faithful PHP port of LangChain JS / LangGraph JS. Composer PSR-4, PHP >= 8.2 floor
(CI: 8.2 / 8.3 / 8.4). Upstream TypeScript is READ-ONLY reference.

## 1. Suite
{test_digest()}

## 2. Namespace inventory

**How to read the third column.** It counts test files whose path sits in
that namespace — it does NOT count how much the namespace is *used*. Those are
different numbers, and section 2b below now gives you the usage number directly
so you never have to infer it.

## 2b. Least-referenced classes (the dead-code question, answered)

**Use THIS, not the third column above, to decide whether something is dead.**
Each class with the fewest referrers in `src/` and `tests/`, counting real code
references and ignoring comments and docblocks:

{least_referenced(refs, inv)}

{orphans} of {len(refs)} src classes have zero referrers.

**How to use it.** A `0` is a QUESTION, not a verdict — check whether the class is
reached by name string, a factory, or serialization before calling it dead. A low
count means "look here", not "delete". The third column in section 2 is a
directory count and systematically under-reports shared abstractions: it shows
`LangChain\\Schema` with 0 test files while `PromptValue` alone is referenced by
13 files and covered by 5 test files. A previous advisory used that column to
report the namespace as dead code and suggested deleting the classes.

(src files / lines / test files)
""" + "\n".join(
        f"  {ns:<44} {v['src']:>3} src {v['lines']:>6} lines {v['test_files']:>2} test files"
        for ns, v in top
    ) + f"""

## 3. Cross-package reference counts
{chr(10).join(f'  {k}: {v} files' for k, v in sorted(edges.items()))}

## 4. Public surface of the core abstractions
{public_surface("BaseChatModel")}
{public_surface("Pregel", 30)}
{public_surface("Schema", 25)}

**THE TEST OUTPUT IN THIS BRIEF IS A SNAPSHOT, NOT A VERDICT.** The
test-output line was captured when the packet was built and may not describe the
tree in front of you. It has already been wrong: an advisory pass reported "2
failures in 2311 tests" and "DocsMatchRealityTest failures (4/4)" and called Test
Strategy, Documentation and Error Handling BROKEN — all four of which re-run green,
because the failures belonged to a red commit that existed for three minutes.

So: **a quoted failing assertion is a claim to re-run, not evidence.** Before you
treat any failure as a property of this codebase, say that you re-ran it. If you
did not run it, you do not know whether it still fails, and a verdict of "broken"
built on an un-reproduced failure is worse than no verdict — it reads as a finding.

**THE LEDGER CAN BE STALE — section 5 is a record, not the source of
truth.** A prior finding or audit verdict listed here may have been FIXED since
it was written, and a resolved item still reads exactly like an open one. It has
already happened: an advisory's single top recommendation was to fix
`RunnableSequence::stream()`, a defect that had been fixed and mutation-verified
five iterations earlier, because the ledger entry still read "fix deferred".

So before you offer ANY recommendation drawn from section 5, re-check it against
the live source in front of you, and say what you checked. If a resolved defect
appears as your highest-leverage change, that is a defect in this BRIEF, not a
finding about the code — say so instead of recommending it.

## 5. What previous reviewers already found

{prior_findings()}

**These are ALREADY FIXED or ALREADY REJECTED.** Section 5 is there so you do
not waste a review rediscovering them — NOT as material to re-analyse. A review
that paraphrases section 5 back as its own "what the green suite hides" has
found nothing and wasted an iteration.

Concretely: do NOT list anything from section 5 under your own heading. If a
section 5 item is genuinely still unfixed, say so in one line under that item's
name and move on. If you have nothing NEW, say "no new findings in this element"
and say so plainly — an honest empty review is worth more than a restatement,
because a restatement reads like corroboration and is not. Corroboration only
counts if you independently reached it from the source, and then you must say
what the source shows.

**THE PAST-TENSE TRAP — read this, it has already caught one review.** Sections
5 and 6 are full of prose describing defects in the PAST tense, and that prose
reads fluently as a description of present behaviour. An advisory reviewed the
`BaseChatModel` empty-stream defect that had been fixed the day before and
reported it in the present tense, with a file:line, as "stream() silently
returns an empty result in some cases while throwing exceptions in others" —
false on both counts: both paths threw, and the eager one always had.

So: **a claim about what the code does NOW may only be backed by the code as it
is NOW.** Before asserting any present-tense defect, quote the line you read and
check it says what you claim. If your evidence is a sentence from section 5 or 6,
you have found nothing — you have restated a fix note. Write "no new findings in
this element" instead. That answer costs nothing and beats a confident
restatement, because a restatement looks like corroboration and is not.

## 6. Known non-exact behaviours (from PORT_STATUS.md)
{run("sed -n '/Known non-exact/,/^## /p' PORT_STATUS.md | head -40") or '(section not found)'}
"""


SYSTEM = """You are a principal engineer performing a WHOLE-SYSTEM advisory review of a
PHP port of a TypeScript library. You are not reviewing one file; you are judging
whether the system is coherent, correctly layered, honestly documented, and
genuinely tested.

Ground every claim in the brief. If you assert a defect, name the file and the
line or the exact identifier. If you cannot point at evidence, say you are unsure
— an honest "the brief does not show this" is worth more than a confident guess.

You are reviewing a PORT. Suggesting unported subsystems (vector stores,
embeddings, agent frameworks) is not a finding; they are documented scope. Your
job is whether what IS ported is right, and whether the record tells the truth."""


def prompt():
    els = "\n".join(f"  {i+1}. {k} — {d}" for i, (k, d) in enumerate(ELEMENTS))
    return f"""Review this port as a whole system. The attached diagram is the
generated architecture view; the brief below is real data pulled from the repo.

Review EVERY one of these key elements:
{els}

Then answer the cross-cutting questions that only a holistic reader can answer:

A. **Composition** — trace two realistic user journeys through the port
   (e.g. "bind tools to a model and stream a response inside a StateGraph
   checkpointed run"). Do they actually work end to end, or do they dead-end
   at a seam between subsystems? Name the seams.

B. **Layering violations** — does any low-level utility know about a high-level
   abstraction? Does the graph layer know about specific providers?

C. **The honesty of the record** — PORT_STATUS.md and HANDOFF.md are the
   contract with the next engineer. Does anything in them contradict the brief?
   Are there ported-but-untested or unported-but-implied areas?

D. **What the green suite hides** — the project's history is dominated by
   defects every unit test passed over. Name three specific places in this brief
   where a passing suite would tell you nothing.

E. **The single highest-leverage change** — if you could only make ONE change
   to this codebase tomorrow, what is it and why?

## Output format

For each of the 8 elements:

### <element>
**Verdict:** sound | needs work | broken
**Evidence:** <file:line or brief datum>
**Finding:** <one or two sentences — or "none">
**Suggested change:** <concrete, or "none">

Then the cross-cutting sections A–E.

Be specific and be blunt. Rank your findings at the end by (impact x confidence).
"""


RATE_LIMIT_RETRIES = 3      # a 429 is transient; the model is fine
RATE_LIMIT_BACKOFF = 20     # seconds, multiplied by the attempt number
IMAGE_TOKENS = 1_600        # the arch PNG costs this whatever else happens
PROMPT_TOKENS = 6_100       # measured: reka reported 6,006 text tokens
SMALL_CTX = 90_000           # below this a roster ctx is treated as a real window


def call(model, system, user_text, png_b64=None, max_tokens=32000):
    key = os.environ["OPENROUTER_API_KEY"]
    body = {
        "model": model,
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": (
                [{"type": "text", "text": user_text}]
                + ([{"type": "image_url", "image_url": {"url": f"data:image/png;base64,{png_b64}"}}]
                   if png_b64 else [])
            )},
        ],
        "max_tokens": max_tokens,
        "temperature": 0.2,
    }
    req = urllib.request.Request(
        ENDPOINT,
        data=json.dumps(body).encode(),
        headers={
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
            "HTTP-Referer": "https://github.com/langchain-ai/langchain-php",
            "X-Title": "langchain-php advisory review",
        },
    )
    # 900 was measured to be too low: iteration 51's call ran past ~1500s before
    # the shell killed it, so the socket timeout was below the real cost of this
    # request. Raised to 2400s, which is above the observed elapsed. NOTE the
    # shell cap must exceed this too — the 1500000ms default killed the previous
    # attempt from the outside, so a generous socket timeout alone buys nothing.
    with urllib.request.urlopen(req, timeout=2400) as r:
        return json.load(r)


def state_path():
    return LOOP / "advisory.json"


def load_state():
    p = state_path()
    return json.loads(p.read_text()) if p.exists() else {}


def save_state(s):
    state_path().write_text(json.dumps(s, indent=1))


HEALTH = LOOP / "model_health.json"


def healthy(model: str) -> bool:
    """
    Is this model able to GENERATE at all? Ask with the smallest possible request.

    Three iterations of this loop were spent on a model that accepts every payload
    and returns "Upstream error from Perceptron: Generation failed" — a budget was
    added for a cause that was never established. The check that settles it costs ONE
    short call: if a two-message, few-hundred-token request cannot produce content,
    no brief length, image or message shape will make the model usable, and the
    honest response is to stop selecting it.

    Results are cached in `.loop/model_health.json`, because a model does not change
    between iterations and a probe per selection would cost a call every time.
    """
    cache = {}
    if HEALTH.exists():
        try:
            cache = json.loads(HEALTH.read_text())
        except ValueError:
            cache = {}
    # Only a real boolean is a cached verdict. A non-boolean entry — `null`, or a
    # file truncated mid-write — is UNKNOWN and must be probed, not treated as a
    # failure: iteration 160 marked reka-edge `null` to force a re-probe and it was
    # excluded for the wrong reason, never probed at all. Reading "not True" as
    # "unusable" is the same conflation as a falsy value standing for an absent
    # one, which is the bug class this loop has now found in mb_chr, json_encode
    # and a schema validator.
    if isinstance(cache.get(model), bool):
        return cache[model]

    # Two questions, because iteration 159's single tiny probe could only answer
    # one of them. rekaai/reka-edge answered a sixteen-token request happily and
    # then returned http_400 on a real brief inside its own 16,384-token window, so
    # "can it generate" is not the question selection actually needs to ask.
    ok = True
    png_probe = None
    arch = LOOP / "arch.png"
    if arch.exists():
        png_probe = base64.b64encode(arch.read_bytes()).decode()
    try:
        r = call(model, "You answer with one word.", "Reply with exactly: OK", None, max_tokens=16)
        text = (r.get("choices") or [{}])[0].get("message", {}).get("content") or ""
        ok = bool(text.strip())
    except Exception:
        ok = False

    if ok:
        # Second probe: the SAME order of payload the advisory actually sends.
        # The answer is one word, so max_tokens stays tiny and the cost is input
        # tokens only - which is the axis that fails.
        filler = ("The quick brown fox jumps over the lazy dog. " * 260)[:12000]
        try:
            # All THREE components of a real advisory, not two: the brief-sized
            # text, the arch PNG, and a system prompt of the real SYSTEM's size.
            # reka-edge passed the previous two-component probe and still returned
            # http_400 on a real call, so the missing piece had to be either the
            # image or the system prompt, and there is no way to know which
            # without sending both.
            r = call(
                model,
                SYSTEM[:PROMPT_TOKENS * 4],
                filler + "\n\nReply with exactly: OK",
                png_probe,
                max_tokens=16,
            )
            text = (r.get("choices") or [{}])[0].get("message", {}).get("content") or ""
            ok = bool(text.strip())
        except Exception:
            ok = False
            print(f"    (holds-a-brief probe {model}: UNUSABLE)", flush=True)

    cache[model] = ok
    HEALTH.write_text(json.dumps(cache, indent=1))
    print(f"    health probe {model}: {'ok' if ok else 'UNUSABLE'}", flush=True)
    return ok


def pick():
    roster = json.loads((LOOP / "roster.json").read_text())
    st = load_state()
    pool = [
        m for m in roster
        if not EXCLUDE.search(m["id"]) and st.get(m["id"], {}).get("status") != "ok"
    ]
    if not pool:
        pool = [m for m in roster if not EXCLUDE.search(m["id"])]
    if not pool:
        sys.exit("no eligible models")
    # Probe before selecting, not after failing. `healthy()` is cached, so this
    # costs one short call per model ever tried rather than per iteration.
    usable = [m for m in pool if healthy(m["id"])]
    if not usable:
        sys.exit("no healthy models in the roster")
    return random.choice(usable)["id"]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--model")
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--digest", action="store_true")
    a = ap.parse_args()

    if a.list:
        st = load_state()
        roster = json.loads((LOOP / "roster.json").read_text())
        for m in roster:
            s = st.get(m["id"])
            if EXCLUDE.search(m["id"]):
                continue
            print(f"  [{s.get('status','-'):<10}] {m['id']}" if s else f"  [{'not served':<10}] {m['id']}")
        return

    b = brief()
    if a.digest:
        print(b)
        return

    model = a.model or pick()
    st = load_state()
    if model in st and st[model].get("status") == "ok":
        print(f"{model} already served an advisory pass — pass --model for someone else")
        return

    # Charge the whole request against the model's window, the way ask.py has
    # since iteration 130. This script used to send the full brief and ALWAYS
    # attach the PNG regardless of the roster's ctx, so every small-context model
    # it picked was a candidate for a 400 — which is what happened to
    # perceptron/perceptron-mk1.5 (ctx 36,864) in iteration 156.
    #
    # The rule is about the REQUEST, not the script that makes it: iteration 130
    # fixed ask.py because ask.py 400'd, and left this caller unguarded. Three
    # roster models sit under SMALL_CTX, so this is the common case rather than
    # an edge.
    roster = json.loads((LOOP / "roster.json").read_text())
    ctx = next((m.get("ctx") or 0 for m in roster if m["id"] == model), 0)
    png = base64.b64encode((LOOP / "arch.png").read_bytes()).decode()
    attach_png = True

    # An UNKNOWN window is not an unlimited one, and it is not `SMALL_CTX`
    # either: a fallback of exactly SMALL_CTX fails the strict `<` below
    # (90000 < 90000), which is a silent no-op. Handle "not known to be large"
    # as its own branch — trim to the smallest budgeted window and drop the
    # image outright, because the whole point is not to guess. Iteration 207.
    if ctx <= 0:
        attach_png = False
        b = b[:1_500] + "\n\n_[model not in roster: brief truncated to the smallest budgeted window, image dropped]_"
        print("    (context UNKNOWN - brief trimmed to ~1,500 chars, image dropped)", flush=True)
    elif ctx < SMALL_CTX:
        effective = ctx - IMAGE_TOKENS - PROMPT_TOKENS
        budget = max(1_500, int(effective * 1.4))
        if len(b) > budget:
            b = b[:budget] + "\n\n_[brief truncated to fit this model's context window]_"
        # Below ~2k effective tokens the image cannot fit alongside the prompt;
        # dropping it is the difference between a call that runs and one that 400s.
        attach_png = effective >= 2_000
        print(f"    (context {ctx:,} tokens — brief trimmed to ~{budget:,} chars"
              f"{', image dropped' if not attach_png else ''})", flush=True)

    print(f"advisory: {model}  (brief {len(b):,} chars)")

    # The brief is regenerated per call, not cached: ask.py's own lesson is that
    # a reviewer must see the LIVE repo, never a stale snapshot.
    # A 429 is a RATE LIMIT, not a verdict on the model. Measured across six
    # recorded failures, FIVE of them PAID models with large context windows, so
    # the "free tier" and "small context" explanations are both wrong: the models
    # were busy. Retrying costs one call and has recovered four lost advisory slots
    # in this loop's history. Only a 400, 404 or an empty body is a real failure
    # worth recording, and those go straight through. Marking a model "asked" after
    # a transient refusal is a bookkeeping field lying about an event — the second
    # time that has happened here.
    resp = None
    last_status = None
    for attempt in range(1, RATE_LIMIT_RETRIES + 1):
        try:
            resp = call(model, SYSTEM, prompt() + "\n\n" + b, png if attach_png else None)
            break
        except urllib.error.HTTPError as e:
            last_status = f"http_{e.code}"
            if e.code != 429 or attempt == RATE_LIMIT_RETRIES:
                print(f"  {last_status}")
                break
            wait = RATE_LIMIT_BACKOFF * attempt
            print(f"  429 rate limited — retry {attempt}/{RATE_LIMIT_RETRIES} in {wait}s", flush=True)
            time.sleep(wait)
        except Exception as e:  # noqa: BLE001
            print(f"  error: {type(e).__name__}: {e}")
            last_status = f"error_{type(e).__name__}"
            break

    if resp is None:
        st[model] = {"status": last_status or "http_error", "at": datetime.now(timezone.utc).isoformat()}
        save_state(st)
        return

    choices = resp.get("choices") or [{}]
    text = (choices[0].get("message") or {}).get("content") or ""
    fr = choices[0].get("finish_reason")
    if not text.strip():
        st[model] = {"status": "empty", "at": datetime.now(timezone.utc).isoformat()}
        print("  empty")
        save_state(st)
        return
    if fr == "length":
        st[model] = {"status": "truncated", "at": datetime.now(timezone.utc).isoformat()}
        print("  truncated")
        save_state(st)
        return

    name = re.sub(r"[^a-z0-9]+", "-", model.split("/")[-1].lower()).strip("-")
    out = LOOP / "advisory" / f"{name}.md"
    out.parent.mkdir(exist_ok=True)
    out.write_text(
        f"# Advisory review — {model}\n\n"
        f"_Generated {datetime.now(timezone.utc).isoformat()}_\n\n{text}\n"
    )
    usage = resp.get("usage") or {}
    st[model] = {
        "status": "ok",
        "at": datetime.now(timezone.utc).isoformat(),
        "file": str(out.relative_to(ROOT)),
        "chars": len(text),
        "cost": round(usage.get("cost", 0), 4),
    }
    save_state(st)
    print(f"  ok -> {out.relative_to(ROOT)}  ({len(text):,} chars, ${st[model]['cost']})")


if __name__ == "__main__":
    main()
