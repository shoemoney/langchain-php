import json
r = json.load(open(".loop/roster.json"))
DROP_SUBSTR = [
    "content-safety", "llama-guard", ":free", "~",           # safety classifiers, free dupes, aliases
    "openrouter/", "typesafe/jev-router", "lyria",            # routers, non-text
    "perplexity/sonar", "bytedance-seed/seed-2.0-code",       # search wrappers, code-specialised
    "nemotron-3-nano-omni", "gemma-4-26b", "prism-ml/",
]
clean = [m for m in r if not any(s in m["id"] for s in DROP_SUBSTR)]
# one per vendor+stem already; keep only models that look like general reasoners
clean.sort(key=lambda m: m["id"])
json.dump(clean, open(".loop/roster.json", "w"), indent=1)
print(f"reviewers: {len(clean)}")
for m in clean: print("  ", m["id"])
