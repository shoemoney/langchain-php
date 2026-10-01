#!/usr/bin/env python3
"""TRIPLE-A REVIEW — adapted for a PHP library port.

The `tripple-a-gamedev` skill is Godot-specific: its dossier is captured frames, its reviewer sees
pixels and no code, and its gate re-renders. None of that applies to a PHP library, so running it
as-is would judge nothing about this repo.

What survives the adaptation is the LOOP, not the artifacts:

    Godot                          this repo
    ---------------------------    ------------------------------------------
    captured frames                measured repo facts + live wire bytes
    reviewer sees pixels only      reviewer sees BEHAVIOUR, not source history
    confirm re-runs the sim        confirm EXECUTES the probe and measures
    gate re-renders, accept/revert gate mutation-verifies, commit or revert
    banked findings                banked findings

Transport, roster, retry and the 330 output-budget fix are imported from ask.py rather than copied,
so a fix there reaches this path too.
"""
import json, os, random, sys, time, datetime, urllib.error, urllib.request, base64
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from ask import call, output_budget, slug, ENDPOINT  # noqa: E402
from framing import framing  # noqa: E402

LOOP = Path(__file__).resolve().parent
STATE = LOOP / "aaa_state.json"

PROMPT = """You are a CONSUMER-BLIND reviewer judging one thing:

**Is this a top-tier, production-grade PHP port — judged FOR THE KIND OF THING IT IS?**

It is a faithful PHP port of LangChain JS / LangGraph JS. That is the product. It is NOT a game, and
"it lacks vector stores" or "it has no React agent" are not giveaways — those are documented unported
subsystems, and naming them wastes the review.

Judge it against what a demanding consumer of a PHP AI library actually receives: the request that goes
on the wire, the response it can parse back, the errors it can diagnose, and the behaviour it gets when
something is empty, truncated, absent, or malformed.

**THE DEGENERATE CASES ARE THE PRODUCT.** For a library, quality lives in what happens at the edges:
an empty array, a null, an empty object, a stream that ends with only a stop reason, a tool call with no
arguments, a checkpoint written for the first time. A port that handles the happy path beautifully and
the empty case silently is not top-tier. Look there first.

**RULES — follow them exactly.**

1. Return **at least 3 fixable craft items**, even if your verdict is AAA. "Best in class" never means
   "nothing to improve". An empty list is a failed review.
2. At most **ONE** item may be structural or unfixable (a missing subsystem, a language limitation, an
   architecture preference). Spend your budget on craft.
3. **Every item must name a real file and a real line**, and must describe a DEFECT — wrong output,
   lost data, a swallowed error, a silent divergence — not a missing feature and not a style preference.
4. A comment in the source that explains WHY the code is shaped a certain way, quoting the upstream
   line, is a COMPLETED DECISION. Reporting it as a bug is a false positive. Read the comment before
   reporting the line below it.
5. Prefer a claim you can state as "for input X the output is Y, and it should be Z" over a claim about
   code quality. If you cannot state the input, say the brief does not show it.
6. `PORT_STATUS.md` and `HANDOFF.md` are a record of work ALREADY DONE. Never cite them as evidence for
   a finding. To claim a recorded fix is wrong, quote the code that contradicts it.

Output this shape, nothing else:

## 1. <short title>
**Severity:** MAJOR | MINOR
**Where:** `path/File.php:LINE`
**Input → Output:** <the concrete input, and what it actually produces>
**Should be:** <what it should produce>
**Why it outs the port:** <the consequence a consumer feels>

""" + framing() + """

The long text below is a review packet: measured metrics, the live source of one rotating subsystem,
and the project's own notes. Judge the SUBSYSTEM IN FOCUS hardest.
"""


def main():
    n = int(sys.argv[1]) if len(sys.argv) > 1 and sys.argv[1].isdigit() else 1
    roster = json.loads((LOOP / "roster.json").read_text())
    state = json.loads(STATE.read_text()) if STATE.exists() else {"cycles": []}

    # triple-A is a DIFFERENT LENS, not new coverage: prefer models not yet used as a triple-A
    # reviewer, then anything with a real context window.
    used = {c["model"] for c in state["cycles"]}
    pool = [m for m in roster if "text" in m.get("in", []) and m["id"] not in used and m["id"] not in ("meta/muse-spark-1.3-contributor",)]
    if not pool:
        pool = [m for m in roster if "text" in m.get("in", [])]
    random.seed()
    model = random.choice(pool)["id"]

    ctx = next((m.get("ctx") or 0 for m in roster if m["id"] == model), 0) or 90_000
    ctx = min(ctx, 90_000)
    packet = (LOOP / "packet.md").read_text(encoding="utf-8")
    png = base64.b64encode((LOOP / "arch.png").read_bytes()).decode()

    IMAGE_TOKENS, PROMPT_TOKENS = 1_600, 6_100
    effective = max(0, ctx - IMAGE_TOKENS - PROMPT_TOKENS)
    budget_chars = max(1_500, int(effective * 1.4))
    packet = packet[:budget_chars]

    content = [
        {"type": "text", "text": PROMPT},
        {"type": "text", "text": "Architecture diagram:"},
        {"type": "image_url", "image_url": {"url": f"data:image/png;base64,{png}"}},
        {"type": "text", "text": f"\n\n# Review packet\n\n{packet}"},
    ]

    fitted = output_budget(ctx, PROMPT_TOKENS + IMAGE_TOKENS + int(len(packet) / 3.6))
    if fitted is None:
        print(f"  skipped: ctx {ctx:,} cannot hold prompt+image for {model}")
        return 1

    print(f"[AAA {len(state['cycles']) + 1}/{n}] {model}  (ctx {ctx:,} -> max_tokens {fitted:,})", flush=True)
    t0 = time.time()
    try:
        raw = call(model, content, max_tokens=fitted)
        # `call()` returns the decoded JSON envelope; take the assistant text out of it.
        text = raw["choices"][0]["message"]["content"]
        if not isinstance(text, str):
            text = json.dumps(text)
    except urllib.error.HTTPError as e:
        print(f"    HTTP {e.code}: {e.read()[:120]}")
        return 1
    except Exception as e:
        print(f"    ERROR {type(e).__name__}: {e}")
        return 1

    out = LOOP / "aaa" / f"{len(state['cycles']) + 1}-{slug(model)}.md"
    out.parent.mkdir(exist_ok=True)
    out.write_text(f"# Triple-A cycle {len(state['cycles']) + 1} - {model}\n"
                   f"_asked {datetime.datetime.now().isoformat(timespec='seconds')} - {time.time() - t0:.0f}s - "
                   f"max_tokens {fitted:,}_\n\n{text}", encoding="utf-8")

    findings = sum(1 for l in text.splitlines() if l.startswith("## "))
    ledger = sum(1 for l in text.splitlines() if "PORT_STATUS" in l or "HANDOFF" in l)
    print(f"    {findings} findings, {ledger} ledger citations -> {out.name}")

    state["cycles"].append({
        "cycle": len(state["cycles"]) + 1, "model": model, "file": str(out),
        "findings": findings, "ledger_citations": ledger,
        "chars": len(text), "at": datetime.datetime.now().isoformat(timespec="seconds"),
    })
    STATE.write_text(json.dumps(state, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main() or 0)
