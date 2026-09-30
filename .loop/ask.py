#!/usr/bin/env python3
"""
Ask ONE reviewer model for five improvements, save the answer, advance the roster.

  python3 .loop/ask.py                 # next unasked model
  python3 .loop/ask.py --model <id>    # a specific one
  python3 .loop/ask.py --list          # show state

State lives in .loop/state.json so a crashed run resumes rather than re-asking.
"""
import base64, json, os, random, re, sys, time, datetime, urllib.request, urllib.error

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)
KEY = os.environ["OPENROUTER_API_KEY"]
ENDPOINT = "https://openrouter.ai/api/v1/chat/completions"
STATE = ".loop/state.json"
REVIEWS = ".loop/reviews"
os.makedirs(REVIEWS, exist_ok=True)

SHORT_PROMPT = """You are a senior staff engineer reviewing a PHP library (a port of the LangChain JS SDK).

Find exactly THREE things that would make it better. For each: a one-line title, a
Severity (BLOCKER/MAJOR/MINOR), a file:line or test name as Evidence, one line on
why it matters, and one line on the fix. Be terse — three specific findings beat
five vague ones, and anything you cannot point at should be left out.

EVIDENCE RULES — this is where reviews most often go wrong:

* The packet contains NO line numbers. Do NOT invent one. A citation like
  `MessageMerge.php:143` that you have not counted is fabricated precision: it
  makes a guess look checkable, and whoever verifies it spends real effort
  confirming a finding that was never grounded. Quote the CODE instead — the
  exact expression, the exact `throw`, the exact key name. An unline-numbered
  quote is verifiable; an invented line number is not.
* If you do cite a line number, count it in the snippet you were given.
* Quote code only if it appears VERBATIM in the packet. Quoting something you
  reconstructed from memory is worse than citing nothing: a code block reads as
  checked, and a reviewer will confirm the finding against it without noticing
  the quote was never in the source. One review did exactly this, claiming a
  duplicated docblock that does not exist in the file.
* Evidence you cannot see in the packet is a guess. Leave the finding out.

This is a PORT, not a product: suggesting a feature upstream does not have is a
defect in your review, not an insight. Judge fidelity, correctness, safety and tests.

Format:

## <n>. <Title>
**Severity:** ... **Evidence:** ...
**Why:** ...
**Fix:** ...
"""

PROMPT = """You are a senior staff engineer doing an ADVISORY review of a PHP library.

EVIDENCE RULES — this is where reviews most often go wrong:

* The packet contains NO line numbers. Do NOT invent one. A citation like
  `MessageMerge.php:143` that you have not counted is fabricated precision: it
  makes a guess look checkable, and whoever verifies it spends real effort
  confirming a finding that was never grounded. Quote the CODE instead — the
  exact expression, the exact `throw`, the exact key name. An unline-numbered
  quote is verifiable; an invented line number is not.
* If you do cite a line number, count it in the snippet you were given.
* Evidence you cannot see in the packet is a guess. Leave the finding out.

The attached image is the architecture. The long text is a review packet: measured
metrics, the project's own status ledger, and the source of the most important files.

# What this project is

A faithful PHP port of the LangChain JS and LangGraph JS SDKs. It is a PORT, not a
product: the goal is behavioural equivalence with the TypeScript originals — same
class hierarchies, same algorithms, same composition semantics, same durable-execution
engine — expressed idiomatically in PHP, verified by tests converted from upstream.

THIRD TRAP — comments in this codebase often explain what the code USED TO do
wrong, because that is the reasoning worth keeping. A comment reading "the try
must enclose the drain, not just the call" is an instruction to a future
editor; a model can easily read it as a description of the present state. Trust
the CODE, never a comment's implication about current behaviour. Several reviews
have reported bugs that were fixed in an earlier round precisely this way.

SECOND TRAP — the packet contains this project's own history of defects it has
ALREADY FIXED. HANDOFF.md lists past bugs in a table, and PORT_STATUS.md has a
"Known non-exact behaviours" table. Those are written down for a future reader.
Reporting an entry from either table as a live defect is a false positive and
wastes a round. Before reporting anything, check whether the code in the packet
still exhibits it — most of those were fixed and the source below shows the fix.

This constraint is the most important thing in this review:

  **Suggesting a feature that does not exist upstream is a defect in your review,
  not an insight.** "Add vector stores" or "support embeddings" are wrong answers
  unless you can name the upstream TypeScript file they come from. The port tracks
  what upstream has, and deliberately excludes some of it (documented in the packet).
  Judge the port on fidelity, correctness, safety, and test quality — not on whether
  it could do more.

# What I want from you

Exactly FIVE things that would make this codebase better. For each:

  1. **Title** — short and specific.
  2. **Severity** — BLOCKER (ships a wrong result / loses data / false claim) |
     MAJOR (real defect or serious gap) | MINOR (clarity, robustness, coverage).
  3. **Evidence** — a `file:line`, a named test, or a specific construct you read in
     the packet. "I think this could be cleaner" is not evidence. If you are inferring
     rather than reading, say so.
  4. **Why it matters** — the concrete failure it causes, not a principle.
  5. **Suggested fix** — the specific change, in enough detail to implement.

# Calibration

- The suite is large and green. Assume a green suite proves less than it appears to;
  this project's history is dominated by defects every unit test passed over. Look for
  the things a test cannot see: wrong values written but never read, paths that
  silently drop data, error branches that swallow, and docs that contradict the code.
- A finding you cannot point at is a guess. Label guesses as guesses. I will verify
  everything before acting on it, and an unverified guess costs me a round.
- Prefer five real findings over fifteen speculative ones. If you genuinely only see
  three, give three and say so — padding is worse than a short list.
- Do not propose refactors for style. Do not propose "add more tests" in the abstract.

Keep each finding SHORT — at most 6 lines total per finding. Five concise,
specific findings are worth more than five long ones, and a verbose answer runs
out of budget before the fifth one lands.

Format each finding EXACTLY like this, with the title on the same line as the
heading number and nothing between them:

## <n>. <Title>
**Severity:** BLOCKER | MAJOR | MINOR
**Evidence:** <file:line or named test, or "inference">
**Why it matters:** <the concrete failure>
**Suggested fix:** <specific change>
"""


# Endpoints that cannot produce a useful review: routers with no identity
# behind them, safety classifiers, and the decision model. `openrouter/free`
# cost one iteration — 553 seconds for ZERO findings, because whatever answered
# was not a model we can hold to a position. advisory.py already excluded these;
# the two lists living in different files is how they drifted apart, so the
# roster is now filtered as well and this is the third line of defence.
NOT_A_REVIEWER = (
    "typesafe/jev", "openrouter/auto", "openrouter/free",
    "safety", "guard", "lyria", "multi-agent",
)


def load_state():
    if os.path.exists(STATE):
        return json.load(open(STATE))
    return {"asked": {}, "order": [], "next_index": 0}


def save_state(s):
    json.dump(s, open(STATE, "w"), indent=1)


def call(model, content, max_tokens=24000, temperature=0.2):
    payload = {
        "model": model,
        "messages": [{"role": "user", "content": content}],
        "max_tokens": max_tokens,
        "temperature": temperature,
    }
    # Reasoning models bill thinking against the same budget as the answer, and
    # one that thinks for 6k tokens and gets cut off yields a review that looks
    # finished and is not. Ask for a modest reasoning effort where supported.
    effort = os.environ.get("LOOP_REASONING_EFFORT", "low")
    if effort:
        payload["reasoning"] = {"effort": effort}
    body = json.dumps(payload).encode()
    req = urllib.request.Request(ENDPOINT, data=body, headers={
        "Authorization": f"Bearer {KEY}",
        "Content-Type": "application/json",
        "HTTP-Referer": "https://github.com/shoemoney/langchain-php",
        "X-Title": "langchain-php-review-loop",
    })
    with urllib.request.urlopen(req, timeout=600) as r:
        return json.load(r)


def finish_reason(resp):
    try:
        return resp["choices"][0].get("finish_reason")
    except (KeyError, IndexError, TypeError):
        return None


def reasoning_share(resp):
    """How much of the completion budget went to reasoning rather than answer.

    Reasoning models bill the same budget for thinking and for output, so a
    generous `max_tokens` is still not a guarantee of a full answer — a model
    that thinks for 6k tokens and then gets cut off has produced a review that
    *looks* complete and is not.
    """
    try:
        d = resp["usage"]["completion_tokens_details"]
        return d.get("reasoning_tokens", 0), resp["usage"]["completion_tokens"]
    except (KeyError, TypeError):
        return 0, 0


def text_of(resp):
    """Some vendors return content=None with reasoning elsewhere; be tolerant."""
    try:
        c = resp["choices"][0]["message"].get("content")
    except (KeyError, IndexError, TypeError):
        return None
    if isinstance(c, str) and c.strip():
        return c
    # fall back to a reasoning field if the vendor puts prose there
    m = resp["choices"][0]["message"]
    for k in ("reasoning", "reasoning_content"):
        v = m.get(k)
        if isinstance(v, str) and len(v) > 400:
            return v
    return None


def slug(mid):
    return mid.replace("/", "__").replace(":", "_")


def main():
    args = sys.argv[1:]
    roster = json.load(open(".loop/roster.json"))
    state = load_state()

    if "--list" in args:
        asked = set(state["asked"])
        print(f"asked {len(asked)}/{len(roster)}")
        for m in roster:
            mark = "x" if m["id"] in asked else " "
            rec = state["asked"].get(m["id"], {})
            note = rec.get("status", "")
            print(f" [{mark}] {m['id']:<44} {note}")
        return

    if "--model" in args:
        model = args[args.index("--model") + 1]
        if any(k in model for k in NOT_A_REVIEWER):
            print(f"{model} is a router or classifier, not a reviewer — refusing to spend an iteration on it.")
            return
    else:
        pending = [
            m for m in roster
            if m["id"] not in state["asked"]
            and not any(k in m["id"] for k in NOT_A_REVIEWER)
        ]
        if not pending:
            print("every reviewer in the roster has been asked.")
            return
        # rotate: seeded by time so successive rounds differ, but never re-ask
        random.seed()
        model = random.choice(pending)["id"]

    _sel = next((a for a in sys.argv if a.startswith("--packet=")), None)
    _which = _sel.split("=", 1)[1] if _sel else None
    packet_file = (_which if isinstance(_which, str) and _which.endswith(".md")
                   else (".loop/packet_" + _which + ".md" if _which else ".loop/packet.md"))
    packet = open(packet_file, encoding="utf-8").read()
    png = base64.b64encode(open(".loop/arch.png", "rb").read()).decode()

    content = [
        {"type": "text", "text": PROMPT},
        {"type": "text", "text": "Architecture diagram:"},
        {"type": "image_url", "image_url": {"url": f"data:image/png;base64,{png}"}},
        {"type": "text", "text": f"\n\n# Review packet\n\n{packet}"},
    ]

    n = len(state["asked"]) + 1
    print(f"[{n}] asking {model} ...", flush=True)
    t0 = time.time()
    rec = {"model": model, "at": datetime.datetime.now().isoformat(timespec="seconds")}

    # A model with a small context cannot take the full packet. Rather than
    # letting it 400, give it the digest head only — the metrics, the layout and
    # the status ledger are enough for a second opinion.
    # Scale the packet to the model's actual context, not to a couple of tiers.
    # A 16k-context model was still being sent 30k chars plus an image and
    # 400ing; the previous fixed thresholds had no relationship to the number
    # that matters. ~3.5 chars per token is the usual English/code ratio, and
    # the diagram costs roughly 1.5k tokens whatever else happens.
    ctx = next((m.get("ctx") or 0 for m in roster if m["id"] == model), 0)
    if _which:
        ctx = min(ctx or 90_000, 90_000)
    if ctx and ctx < 90_000:
        # Deliberately conservative: reka reported 11,168 text tokens for a
        # 40k-char packet (a ~3.6 ratio, as expected) and still 400'd against
        # its own stated 16,384 limit, so its accounting over-reserves. Budget
        # for roughly half the context rather than all of it.
        budget_chars = max(3_000, int((ctx - 4_000) * 1.4))
        head = packet[:budget_chars]
        content = [
            {"type": "text", "text": PROMPT},
            {"type": "image_url", "image_url": {"url": f"data:image/png;base64,{png}"}},
            {"type": "text", "text": f"\n\n# Review packet (abridged for this model's context window)\n\n{head}"},
        ]
        print(f"    (context {ctx:,} tokens — packet trimmed to ~{budget_chars:,} chars)", flush=True)

    try:
        resp = call(model, content)
        text = text_of(resp)
        rec["actual_model"] = resp.get("model")
        rec["usage"] = resp.get("usage")
        rec["finish_reason"] = finish_reason(resp)
        r_tok, c_tok = reasoning_share(resp)
        rec["reasoning_tokens"] = r_tok

        if not text:
            rec.update(status="empty")
            print("    EMPTY RESPONSE")
        elif rec["finish_reason"] in ("error", "content_filter"):
            rec.update(status=f"error_{rec['finish_reason']}")
            print(f"    ABORTED finish_reason={rec['finish_reason']} "
                  f"({len(text)} chars of partial trace discarded)")
        elif rec["finish_reason"] == "length":
            # The common failure for reasoning models: they spend the whole
            # output budget thinking and never reach finding 5. One retry with a
            # compressed ask and a much smaller packet is usually enough, and
            # costs one call rather than losing the model entirely.
            print(f"    truncated (reasoning {r_tok}/{c_tok}) — retrying compressed ...", flush=True)
            try:
                # Every knob here points the SAME way — less work. The first
                # version RAISED max_tokens, which is precisely wrong for a
                # model that fills its budget thinking: it gave the runaway
                # more room to run. Fewer findings, a much smaller packet, and
                # a LOWER ceiling, so it has to answer rather than deliberate.
                resp2 = call(model, [
                    {"type": "text", "text": SHORT_PROMPT},
                    {"type": "image_url", "image_url": {"url": f"data:image/png;base64,{png}"}},
                    {"type": "text", "text": f"\n\n# Review packet (abridged)\n\n{packet[:8_000]}"},
                ], max_tokens=7000)
                text2 = text_of(resp2)
                f2 = len(re.findall(r"^#{2,4}\s*\d+[.)]\s", text2 or "", re.M))
                s2 = len(re.findall(r"Severity:?\**\s*:?\**\s*(?:BLOCKER|MAJOR|MINOR)", text2 or "", re.I))
                if text2 and f2 >= 5 and s2 >= 5:
                    path = f"{REVIEWS}/{n:02d}-{slug(model)}.md"
                    open(path, "w", encoding="utf-8").write(
                        f"# Review {n} - {model}\n"
                        f"_asked {rec['at']} - served by {resp2.get('model')} - compressed retry_\n\n{text2}")
                    rec.update(status="ok", file=path, chars=len(text2), findings=f2, compressed=True)
                    print(f"    ok(compressed) -> {path}  ({f2} findings)")
                    state["asked"][model] = rec
                    state["order"].append(model)
                    save_state(state)
                    return 0
                rec["status"] = "truncated"
            except Exception as e2:
                rec["retry_error"] = f"{type(e2).__name__}: {e2}"
                rec["status"] = "truncated"
            rec.update(chars=len(text))
            path = f"{REVIEWS}/{n:02d}-{slug(model)}.md"
            open(path, "w", encoding="utf-8").write(
                f"# Review {n} - {model} (TRUNCATED)\n"
                f"_finish_reason=length, reasoning {r_tok}/{c_tok}_\n\n{text}")
            rec["file"] = path
            print(f"    TRUNCATED (reasoning {r_tok}/{c_tok}) -> {path}")
        else:
            path = f"{REVIEWS}/{n:02d}-{slug(model)}.md"
            header = (f"# Review {n} - {model}\n"
                      f"_asked {rec['at']} - served by {rec.get('actual_model')} - "
                      f"{time.time()-t0:.0f}s_\n\n")
            open(path, "w", encoding="utf-8").write(header + text)
            f5 = len(re.findall(r"(?:#{2,4}\s*\d+[.)]\s)|(?:^\s*\*+\s*(?:\*\*)?Finding\s+\d+)", text, re.M | re.I))
            s5 = text.count("**Severity:**")
            rec.update(status="ok" if (f5 >= 5 and s5 >= 5) else "incomplete",
                       file=path, chars=len(text), findings=f5, severity_hits=s5)
            print(f"    {rec['status']} -> {path}  ({f5} findings, {s5} severities, {time.time()-t0:.0f}s)")

    except urllib.error.HTTPError as e:
        body = e.read()[:400].decode("utf-8", "replace")
        rec.update(status=f"http_{e.code}", error=body)
        print(f"    HTTP {e.code}: {body[:160]}")
    except Exception as e:
        rec.update(status="error", error=f"{type(e).__name__}: {e}")
        print(f"    ERROR {type(e).__name__}: {e}")

    state["asked"][model] = rec
    state["order"].append(model)
    save_state(state)
    return 0 if rec.get("status") == "ok" else 1


if __name__ == "__main__":
    sys.exit(main() or 0)
