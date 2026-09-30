import json, os, urllib.request

req = urllib.request.Request("https://openrouter.ai/api/v1/models")
data = json.load(urllib.request.urlopen(req, timeout=60))["data"]

vision = []
for m in data:
    arch = m.get("architecture") or {}
    mods = arch.get("input_modalities") or []
    if "image" in mods:
        vision.append({
            "id": m["id"],
            "name": m.get("name"),
            "in": mods,
            "ctx": m.get("context_length"),
            "price": m.get("pricing", {}).get("prompt"),
        })

vision.sort(key=lambda x: (x["id"]))
json.dump(vision, open(".loop/vision_models.json", "w"), indent=1)
print(f"total models: {len(data)}")
print(f"vision-capable: {len(vision)}")
for v in vision:
    print(f"  {v['id']:<52} ctx={v['ctx']}")
