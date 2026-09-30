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

ROOT = Path(__file__).resolve().parent.parent
LOOP = ROOT / ".loop"
ENDPOINT = "https://openrouter.ai/api/v1/chat/completions"

# Models that cannot serve a useful advisory pass: routers with no identity,
# safety classifiers, and the decision model (not a chat model).
EXCLUDE = re.compile(
    r"(typesafe/jev|openrouter/(auto|free)|safety|guard|lyria|"
    r"grok-.*multi-agent|^~)",
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
    for p in (ROOT / "tests").rglob("*Test.php"):
        rel = p.relative_to(ROOT / "tests")
        inv[str(rel.parent).replace("/", ".")]["test_files"] += 1
    return {k: v for k, v in sorted(inv.items()) if k not in (".", "")}


def dep_edges():
    """Which top-level package references which. Cheap namespace scan."""
    edges = collections.Counter()
    for p in (ROOT / "src").rglob("*.php"):
        body = p.read_text(encoding="utf-8", errors="replace")
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
    lines = []
    for model, items in t.items():
        for it in items:
            lines.append(f"[{model}] {it}")
    return "\n".join(lines[-limit:])


def brief():
    inv = inventory()
    top = sorted(inv.items(), key=lambda kv: -kv[1]["src"])[:22]
    edges = dep_edges()
    return f"""# ADVISORY BRIEF — langchain-php

A faithful PHP port of LangChain JS / LangGraph JS. Composer PSR-4, PHP >= 8.2 floor
(CI: 8.2 / 8.3 / 8.4). Upstream TypeScript is READ-ONLY reference.

## 1. Suite
{test_digest()}

## 2. Namespace inventory (src files / lines / test files)
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

## 5. What previous reviewers already found (do NOT repeat these)
{prior_findings()}

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


def call(model, system, user_text, png_b64, max_tokens=32000):
    key = os.environ["OPENROUTER_API_KEY"]
    body = {
        "model": model,
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": [
                {"type": "text", "text": user_text},
                {"type": "image_url", "image_url": {"url": f"data:image/png;base64,{png_b64}"}},
            ]},
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
    with urllib.request.urlopen(req, timeout=900) as r:
        return json.load(r)


def state_path():
    return LOOP / "advisory.json"


def load_state():
    p = state_path()
    return json.loads(p.read_text()) if p.exists() else {}


def save_state(s):
    state_path().write_text(json.dumps(s, indent=1))


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
    return random.choice(pool)["id"]


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

    png = base64.b64encode((LOOP / "arch.png").read_bytes()).decode()
    print(f"advisory: {model}  (brief {len(b):,} chars)")

    # The brief is regenerated per call, not cached: ask.py's own lesson is that
    # a reviewer must see the LIVE repo, never a stale snapshot.
    try:
        resp = call(model, SYSTEM, prompt() + "\n\n" + b, png)
    except urllib.error.HTTPError as e:
        status = f"http_{e.code}"
        print(f"  {status}")
        st[model] = {"status": status, "at": datetime.now(timezone.utc).isoformat()}
        save_state(st)
        return
    except Exception as e:  # noqa: BLE001
        print(f"  error: {type(e).__name__}: {e}")
        st[model] = {"status": f"error_{type(e).__name__}", "at": datetime.now(timezone.utc).isoformat()}
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
