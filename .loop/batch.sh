#!/bin/bash
# Ask the next N unasked reviewers, in sequence.
cd "$(dirname "$0")/.."
for i in $(seq 1 "${1:-6}"); do
  python3 .loop/ask.py 2>&1 | sed 's/^/  /'
done
