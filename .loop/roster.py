import json, re, collections

models = json.load(open(".loop/vision_models.json"))

# Drop :batch (same model, 50% cheaper routing — not a distinct reviewer)
base = [m for m in models if ":batch" not in m["id"]]
# Drop free/quant experiment endpoints and image-OUTPUT-only variants
skip = ("image-preview", "image-generation")
keep = [m for m in base if not any(s in m["id"] for s in ("-image", "image-generation"))]

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
