---
active: false
iteration: 2
maxIterations: 100
---

DONE. Fixed the task-id derivation: a PULL task's id now matches LangGraph JS
byte-for-byte (5bb82f69-da9c-5fa0-aeda-fd0eb14bd8ce), proven by a third uuid5
implementation under Node. One line of source; the whole suite passed unchanged
through it, which is itself the finding — nothing covered it.
Corrected the interrupts entry: last session guessed the wrong function. The real
one is tasksWithWrites in pregel/debug.ts, and the chain is two defects, neither
sufficient alone. Step counter measured one ahead of upstream (0 vs 1 on the same
paused state). Still open, honestly marked.
4440 tests, 9995 assertions, green in a fresh clone.
