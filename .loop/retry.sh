#!/bin/bash
# Second pass: models whose first review failed, retried with the improved harness.
cd "$(dirname "$0")/.."
python3 - <<'PY'
import json
s = json.load(open(".loop/state.json"))
# 404/403/400 are permanent endpoint or policy failures; 429 is transient and worth one retry.
keep = [k for k, v in s["asked"].items()
        if v.get("status") in ("truncated", "incomplete", "http_429", "error", "empty")]
json.dump(keep, open(".loop/retry.json", "w"), indent=1)
print(len(keep), "candidates")
PY
for m in $(python3 -c "import json;[print(x) for x in json.load(open('.loop/retry.json'))]"); do
  out=$(python3 .loop/ask.py --model "$m" 2>&1 | grep -vE '^\[[0-9]+\]' | grep -v '^\$')
  printf '  %-46s %s\n' "$m" "$out"
done
