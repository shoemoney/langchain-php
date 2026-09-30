#!/usr/bin/env python3
"""
Build the review packet sent to each reviewer model.

Produces:
  .loop/packet.md    - the text digest (docs, metrics, key source, test inventory)
  .loop/arch.png     - an architecture diagram, so the vision-capable reviewers
                       are actually reviewing something visual

Deliberately built from the live repo every iteration, never hand-written: a
packet that drifts from the code is worse than no packet.
"""
import json, os, re, subprocess, sys, collections

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)


def sh(cmd):
    try:
        return subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=180).stdout.strip()
    except Exception:
        return ""


def metrics():
    src = [l for l in sh("find src -name '*.php'").splitlines() if l]
    tst = [l for l in sh("find tests -name '*.php'").splitlines() if l]
    loc = lambda files: int(sh(f"cat {' '.join(files)} | wc -l") or 0)
    suite = sh("composer test 2>&1 | tail -3")
    m = re.search(r"OK \((\d+) tests?, (\d+) assertions?\)", suite)

    # The count comes from `--list-tests`, NOT from the OK line.
    #
    # The OK line is only present when the suite is GREEN, so parsing it meant a
    # red suite reported `tests: 0` — and build_packet wrote that into the
    # packet every reviewer reads. One iteration reviewed this repository as
    # having no tests at all, because a doc-drift failure had left the suite red
    # at the moment the packet was built. The count is a fact about the
    # repository and must not depend on whether the suite currently passes.
    listed = sh("./vendor/bin/phpunit --list-tests 2>/dev/null | grep -c '^ - '")
    tests = int(listed) if listed.isdigit() else 0

    if tests == 0:
        # Not a warning: a packet claiming zero tests is worse than no packet,
        # and silently proceeding is what produced the bad review.
        raise SystemExit(
            "refusing to build a packet: could not count the suite.\n"
            "A packet that reports `tests: 0` was read by a reviewer as a "
            "repository with no tests, which is how an entire review was framed "
            "on a false premise.\nSuite tail was:\n" + suite[-600:]
        )

    return {
        "src_files": len(src), "src_lines": loc(src),
        "test_files": len(tst), "test_lines": loc(tst),
        "tests": tests,
        "assertions": int(m.group(2)) if m else 0,
        "suite_ok": bool(m),
    }


def test_inventory():
    """Per-directory test counts — shows where coverage is thin."""
    out = sh("find tests -name '*Test.php' | xargs -n1 dirname | sort | uniq -c | sort -rn")
    return out


def source_inventory():
    out = sh("find src -name '*.php' | xargs -n1 dirname | sort | uniq -c | sort -rn | head -40")
    return out


# The packet rotates its focus. Without this the reviewers saw the same eight
# provider files every round and the loop ran dry: LangGraph holds 77 files and
# the fixed list showed one of them, while prompts (19) and output parsers (24)
# were invisible entirely. Diminishing returns were a property of the PACKET,
# not of the code.
FOCUS_GROUPS = [
    ("provider clients", [
        "src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php",
        "src/LangChain/LanguageModels/Chat/Anthropic/ChatAnthropic.php",
        "src/LangChain/LanguageModels/Chat/OpenAI/Utils/Completions.php",
        "src/LangChain/LanguageModels/Chat/Anthropic/Utils/MessageInputs.php",
    ]),
    ("messages & merge algebra", [
        "src/LangChain/Messages/AIMessageChunk.php",
        "src/LangChain/Messages/MessageMerge.php",
        "src/LangChain/Messages/MessageUtils.php",
        "src/LangChain/Messages/ContentBlock.php",
        "src/LangChain/Messages/BaseMessage.php",
    ]),
    ("langgraph engine", [
        "src/LangGraph/Pregel/Algorithm.php",
        "src/LangGraph/Pregel/Loop.php",
        "src/LangGraph/Pregel/IO.php",
        "src/LangGraph/State/StateGraph.php",
    ]),
    ("langgraph checkpointing", [
        "src/LangGraph/Checkpoint/BaseCheckpointSaver.php",
        "src/LangGraph/Checkpoint/SqliteSaver.php",
        "src/LangGraph/Checkpoint/MemorySaver.php",
        "src/LangGraph/Checkpoint/Serde/JsonPlusSerializer.php",
    ]),
    ("prompts & templates", [
        "src/LangChain/Prompts/ChatPromptTemplate.php",
        "src/LangChain/Prompts/PromptTemplate.php",
        "src/LangChain/Prompts/BasePromptTemplate.php",
        "src/LangChain/Prompts/MessagesPlaceholder.php",
    ]),
    ("output parsers", [
        "src/LangChain/OutputParsers/JsonOutputParser.php",
        "src/LangChain/OutputParsers/BaseCumulativeTransformOutputParser.php",
        "src/LangChain/OutputParsers/StructuredOutputParser.php",
        "src/LangChain/OutputParsers/PartialJsonParser.php",
    ]),
    ("tools & schemas", [
        "src/LangChain/Tools/StructuredTool.php",
        "src/LangChain/Tools/Schema.php",
        "src/LangChain/Tools/BaseToolkit.php",
        "src/LangChain/Tools/ToolRuntime.php",
    ]),
    ("composition & config", [
        "src/LangChain/Runnables/Runnable.php",
        "src/LangChain/Runnables/RunnableBinding.php",
        "src/LangChain/Runnables/RunnableSequence.php",
        "src/LangChain/Runnables/RunnableBranch.php",
    ]),
    ("tracing & callbacks", [
        "src/LangChain/Tracers/BaseTracer.php",
        "src/LangChain/Tracers/CallbackManager.php",
        "src/LangChain/Tracers/Run.php",
        "src/LangChain/Tracers/BaseRunManager.php",
    ]),
    ("text splitting", [
        "src/LangChain/TextSplitters/TextSplitter.php",
        "src/LangChain/TextSplitters/RecursiveCharacterTextSplitter.php",
        "src/LangChain/TextSplitters/TextLength.php",
        "src/LangChain/TextSplitters/Language.php",
    ]),
    ("model base & streaming", [
        "src/LangChain/LanguageModels/BaseChatModel.php",
        "src/LangChain/LanguageModels/StructuredOutput.php",
        "src/LangChain/Utils/Http/SseParser.php",
        "src/LangChain/Utils/Http/GuzzleHttpClient.php",
    ]),
]

# The base layer stays in every packet: it is what the rest hangs off, and a
# reviewer judging a subsystem without it will misjudge the seams.
ALWAYS = [
    "composer.json", "phpunit.xml", ".github/workflows/ci.yml",
    "src/LangChain/Runnables/RunnableInterface.php",
]

_state = ".loop/packet_focus.json"


def current_focus() -> int:
    try:
        return int(json.load(open(_state))["index"])
    except Exception:
        return 0


def advance_focus() -> None:
    json.dump({"index": (current_focus() + 1) % len(FOCUS_GROUPS)}, open(_state, "w"))


BUDGET = 110_000  # chars of source. A bigger packet makes reasoning models
                  # think for 20k+ tokens and return nothing; measured, not guessed.


def pack_sources():
    focus_idx = current_focus()
    focus_name, focus_files = FOCUS_GROUPS[focus_idx]
    out, used = [], 0
    for f in ALWAYS + focus_files:
        if not os.path.exists(f):
            continue
        body = open(f, encoding="utf-8", errors="replace").read()
        if used + len(body) > BUDGET:
            out.append(f"\n### {f}\n_(omitted: packet budget reached)_\n")
            continue
        used += len(body)
        out.append(f"\n### {f}\n```php\n{body}\n```\n")
    return "".join(out), used, focus_name


def build_packet():
    m = metrics()
    parts = []
    A = parts.append

    A(f"""# langchain-php — review packet

A faithful PHP port of the LangChain JS and LangGraph JS SDKs: same class
hierarchies, same algorithms, same LCEL composition semantics, same Pregel
durable-execution engine — translated to idiomatic PHP and verified by
converted tests. NOT a wrapper around a Python service.

## Measured state (live, not asserted)

| | |
|---|---|
| Suite | **{m['tests']} tests, {m['assertions']} assertions** ({'GREEN' if m['suite_ok'] else 'NOT GREEN'}) |
| src | {m['src_files']} files / {m['src_lines']:,} lines |
| tests | {m['test_files']} files / {m['test_lines']:,} lines |
| PHP | 8.5.11 local; CI matrix 8.2 / 8.3 / 8.4 |
| Deps | guzzlehttp/guzzle, psr/log, ramsey/uuid; phpunit 11 |

## Source layout (files per directory)

```
{source_inventory()}
```

## Test layout (suites per directory)

```
{test_inventory()}
```

## Project status and known divergences

The authoritative accounting is PORT_STATUS.md. Read it — it marks a subsystem
ported ONLY when converted tests exist and pass, and it carries a
"Known non-exact behaviours" table where every deliberate PHP-vs-JS divergence
is recorded with the reason.

""")

    for f in ("HANDOFF.md", "PORT_STATUS.md"):
        if os.path.exists(f):
            A(f"\n## {f}\n\n```markdown\n{open(f, encoding='utf-8', errors='replace').read()}\n```\n")

    src, used, focus_name = pack_sources()
    A(f"\n## Source in focus: **{focus_name}** "
      f"(this rotates so the whole port gets reviewed, "
      f"not just the provider clients)\n")
    A(f"_{used:,} chars of the {m['src_lines']:,}-line tree._\n")
    A(src)

    open(".loop/packet.md", "w", encoding="utf-8").write("".join(parts))
    return m, len("".join(parts)), focus_name


# ---------------------------------------------------------------- diagram

def build_diagram(m):
    """A dependency picture of the port, as a PNG the vision reviewers can read."""
    from PIL import Image, ImageDraw, ImageFont
    def font(sz, bold=False):
        p = "/System/Library/Fonts/Supplemental/Arial%s.ttf" % (" Bold" if bold else "")
        try:    return ImageFont.truetype(p, sz)
        except: return ImageFont.load_default()

    layers = [
        ("PROVIDER CLIENTS", ["ChatOpenAI", "ChatAnthropic", "HttpClient (seam)", "SseParser", "GuzzleHttpClient"], "#1f6feb"),
        ("CORE ABSTRACTIONS", ["BaseChatModel", "StructuredOutput", "BaseLangChain", "RunnableBinding", "RunnableAssign"], "#8957e5"),
        ("COMPOSITION (LCEL)", ["Runnable", "RunnableSequence", "RunnableLambda", "RunnableBranch", "RunnablePassthrough"], "#2ea043"),
        ("TOOLS + PARSERS", ["StructuredTool", "Schema", "JsonOutputKeyTools", "JsonOutputParser", "JsonOutputTools"], "#bf8700"),
        ("MESSAGES", ["BaseMessage", "AIMessageChunk", "MessageMerge", "ToolMessage", "ContentBlock"], "#cf222e"),
        ("LANGGRAPH ENGINE", ["PregelLoop (Generator)", "Algorithm", "StateGraph", "Channels", "Checkpoint savers"], "#0550ae"),
    ]

    W, H = 1500, 150 + len(layers) * 128
    img = Image.new("RGB", (W, H), "#0d1117")
    d = ImageDraw.Draw(img)
    d.text((36, 26), f"langchain-php  —  {m['src_files']} src files / {m['tests']} tests passing",
           font=font(30, True), fill="#e6edf3")
    d.text((36, 66), "every provider client reaches the port through one HTTP seam; Pregel loop is a PHP Generator",
           font=font(19), fill="#8b949e")

    x0, y0, bw, bh = 36, 108, 330, 78
    for i, (title, items, color) in enumerate(layers):
        y = y0 + i * 128
        d.rounded_rectangle([x0, y, x0 + 250, y + bh], 10, fill="#161b22", outline=color, width=2)
        d.text((x0 + 16, y + 14), title, font=font(19, True), fill=color)
        d.text((x0 + 16, y + 42), f"{len(items)} components", font=font(15), fill="#8b949e")
        for j, it in enumerate(items):
            x = x0 + 290 + j * 234
            d.rounded_rectangle([x, y, x + 218, y + bh], 9, fill="#161b22", outline="#30363d")
            for k, part in enumerate(re.split(r"\\\\| \(", it)):
                d.text((x + 12, y + 14 + k * 20), part[:30], font=font(15), fill="#c9d1d9")
        if i < len(layers) - 1:
            d.line([x0 + 125, y + bh, x0 + 125, y + 128], fill="#30363d", width=2)
            d.polygon([(x0+120, y+bh+10), (x0+130, y+bh+10), (x0+125, y+bh+20)], fill="#30363d")

    img.save(".loop/arch.png")
    return ".loop/arch.png"


if __name__ == "__main__":
    m, n, focus = build_packet()
    png = build_diagram(m)
    json.dump(m, open(".loop/metrics.json", "w"), indent=1)
    print(f"packet: {n:,} chars   focus: {focus}   tests: {m['tests']}")
    if not m['suite_ok']:
        print(
            "  WARNING: the suite is RED. The packet says so, and a reviewer may "
            "misread a failing run as missing coverage. Fix the suite before "
            "treating any review as authoritative."
        )
    advance_focus()
