import json, re, collections

models = json.load(open(".loop/vision_models.json"))

# Drop :batch (same model, 50% cheaper routing — not a distinct reviewer) and
# :free. The :free rule arrived at 487 with the roster refresh: :free is the SAME
# model on a different billing tier, so asking both asks one reviewer twice and
# spends a slot to learn nothing. Measured on the refreshed list, 6 of the 18
# never-asked entries were a :free twin of a model already asked or queued.
base = [m for m in models if ":batch" not in m["id"] and ":free" not in m["id"]]
# Drop free/quant experiment endpoints and image-OUTPUT-only variants
skip = ("image-preview", "image-generation")
keep = [m for m in base if not any(s in m["id"] for s in ("-image", "image-generation"))]

# Drop models that CANNOT review code, however good their vision support is.
# The 487 refresh surfaced these: a vision-capable roster is not a reviewer
# roster. Each of these was offered as an un-asked candidate and every one is
# disqualified by what it IS, not by a trial run — which is cheaper than
# spending an attempt to discover it returns a moderation verdict instead of
# five findings:
#
#   meta-llama/llama-guard-4-12b        a prompt-injection CLASSIFIER
#   nvidia/nemotron-3.5-content-safety  a content-safety CLASSIFIER (both tiers)
#   google/lyria-3-clip-preview         a CLIP image/text embedding model
#   openrouter/auto, auto-beta, free    ROUTERS — they pick a model per request,
#                                       so "reviewing" one is really a review by
#                                       an unrepeatable coin flip, which cannot
#                                       be attributed or reproduced
#   typesafe/jev-router                 a decision model, not a chat reviewer
#   ~google/gemini-flash-latest        a malformed catalogue entry (leading ~),
#                                       not a requestable model id
#   google/lyria-3-pro-preview          a MUSIC GENERATION model. It survived the
#                                       first pass of this filter because the
#                                       token was "clip-preview" and this one is
#                                       "pro-preview" — a reminder that a
#                                       hand-written blocklist only catches the
#                                       names you already thought of.
NOT_REVIEWERS = (
    "guard", "safety", "moderation", "clip-preview", "-clip", "lyria",
    "openrouter/auto", "router", "embedding", "judge-", "/auto",
)

def is_reviewer(mid: str) -> bool:
    low = mid.lower()
    if mid.startswith("~"):          # malformed catalogue entry
        return False
    return not any(t in low for t in NOT_REVIEWERS)

keep = [m for m in keep if is_reviewer(m["id"])]

def ver_key(mid):
    v = [int(x) for x in re.findall(r'\d+', mid)]
    return (v, len(mid))

# family = everything before the last version-ish segment
fam = collections.defaultdict(list)
for m in keep:
    mid = m["id"]
    vendor = mid.split("/")[0]
    base_name = mid.split("/")[-1]
    # strip trailing version-ish tokens to find the family stem
    stem = re.sub(r'[-.]?(?:v?\d[\d.]*(?:-[a-z0-9]+)*)$', '', base_name)
    fam[f"{vendor}/{stem}"].append(m)

roster = []
for f, ms in sorted(fam.items()):
    ms.sort(key=lambda m: ver_key(m["id"]))
    roster.append(ms[-1])   # latest in family

roster.sort(key=lambda m: m["id"])
json.dump(roster, open(".loop/roster.json", "w"), indent=1)
print(f"families: {len(fam)}   roster: {len(roster)}\n")
for m in roster:
    print(f"  {m['id']}")
