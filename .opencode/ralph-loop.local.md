---
active: false
iteration: 4
maxIterations: 100
---

DONE. Fixed: orphan sweep counting string literals (my own instrument, which my own
guard had broken); StateSnapshot value object with all 7 upstream fields; the task
`path` field its own docblock claimed; engine toArray() wire-key shape.
RETRACTED: last iteration's camelCase/snake_case claim — it measured a revived object,
not the stored bytes, and 7 tests were pinning the wrong format as correct.
Scoped with committed oracles: `debug` stream mode; updateState() still deferred.
4422 tests, 9731 assertions, green in a fresh clone.
