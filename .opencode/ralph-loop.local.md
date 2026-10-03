---
active: false
iteration: 1
maxIterations: 100
---

DONE. Found and fixed SILENT HISTORY LOSS: a superstep of checkpoint history was
being overwritten, not appended. Upstream records -1,0,1,2; the port recorded
-1,0,1,3 — step 2 destroyed, replaced by a step that never ran. Resume kept
working and the head was correct, so nothing failed. Two missing conditions: an
unconditional `exiting: true` where upstream has an identity test on the metadata
object, and a final save that upstream gates on `durability === "exit"` when the
default is "async". Fixing only the first produced FIVE checkpoints with correct
content and one spurious — which is how the second became findable.
Measured why the interrupt path still offsets: this port is exit-style, so it
NEEDS the save upstream skips; removing it loses the pending writes and breaks
resume. Recorded, not half-fixed.
4450 tests, 9998 assertions, green in a fresh clone.
