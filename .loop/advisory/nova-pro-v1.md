# Advisory review — amazon/nova-pro-v1

_Generated 2026-09-30T06:15:37.606530+00:00_

### Layering
**Verdict:** sound
**Evidence:** The architecture diagram and the namespace inventory show a clear separation of concerns and adherence to PSR-4 layout.
**Finding:** The layering appears to be correctly implemented with no apparent violations.
**Suggested change:** none

### Public-api
**Verdict:** needs work
**Evidence:** The public surface of the core abstractions shows some inconsistencies, such as `generatePrompt` keeping a legacy signature while `generateMessages` uses `RunnableConfig`.
**Finding:** The public API could be more coherent and consistent.
**Suggested change:** Consider refactoring to align the signatures and improve coherence.

### Composition
**Verdict:** needs work
**Evidence:** The brief does not provide clear evidence that the subsystems actually compose end-to-end.
**Finding:** It is unclear whether the subsystems compose or merely coexist.
**Suggested change:** Provide concrete examples or tests that demonstrate end-to-end composition.

### Error-handling
**Verdict:** needs work
**Evidence:** The brief mentions that some exception types are not caught or handled correctly.
**Finding:** The error-handling strategy needs improvement to ensure that callers can trust the exceptions.
**Suggested change:** Review and improve the exception handling to ensure consistency and reliability.

### Fidelity
**Verdict:** needs work
**Evidence:** The brief lists several known non-exact behaviours where the PHP port diverges from the TypeScript upstream.
**Finding:** Some divergences are justified, but others may need review to ensure they are intentional and documented.
**Suggested change:** Review and document all divergences to ensure they are intentional and necessary.

### Test-strategy
**Verdict:** needs work
**Evidence:** The brief mentions that some areas have zero direct tests, such as `LangGraph\Checkpoint` and `LangGraph\Errors`.
**Finding:** The test strategy needs to be strengthened to cover all critical areas of the codebase.
**Suggested change:** Add direct tests for uncovered areas to ensure comprehensive coverage.

### Documentation
**Verdict:** needs work
**Evidence:** The brief mentions that `PORT_STATUS.md` and `HANDOFF.md` describe the code that exists, but there are known non-exact behaviours that may not be fully documented.
**Finding:** The documentation could be improved to more accurately reflect the current state of the code.
**Suggested change:** Update `PORT_STATUS.md` and `HANDOFF.md` to include all known non-exact behaviours and divergences.

### Dead-code
**Verdict:** needs work
**Evidence:** The brief does not explicitly mention any dead code, but the presence of unported areas and untested code suggests potential dead code.
**Finding:** There may be dead code that needs to be identified and removed.
**Suggested change:** Conduct a thorough review to identify and remove any dead code.

---

### Cross-cutting sections

#### A. Composition
**Trace 1:** Bind tools to a model and stream a response.
- **Seams:** The binding of tools to a model and the streaming of a response may dead-end at the seam between the `LangChain` and `LangGraph` layers.
- **Evidence:** The brief does not provide clear evidence that this journey works end-to-end.

**Trace 2:** Execute a StateGraph checkpointed run.
- **Seams:** The execution of a StateGraph may dead-end at the seam between the `LangGraph` and `LangChain` layers.
- **Evidence:** The brief does not provide clear evidence that this journey works end-to-end.

#### B. Layering violations
**Evidence:** The brief does not provide clear evidence of layering violations, but the presence of cross-package references suggests potential violations.
- **Finding:** There may be low-level utilities that know about high-level abstractions or vice versa.
- **Suggested change:** Review the cross-package references to identify and correct any layering violations.

#### C. The honesty of the record
**Evidence:** The brief mentions that some findings were rejected because they were already fixed or because they were fabrications.
- **Finding:** The record appears to be mostly honest, but there are some discrepancies that need to be addressed.
- **Suggested change:** Ensure that `PORT_STATUS.md` and `HANDOFF.md` accurately reflect the current state of the code and any known issues.

#### D. What the green suite hides
**Evidence:** The brief mentions several defects that passed the unit tests, such as the PartialJsonParser crashing on UTF-16 surrogate pairs and the stall guard throwing a generic `HttpException`.
- **Finding:** The green suite may hide critical defects that only surface under specific conditions.
- **Suggested change:** Improve the test suite to catch these hidden defects and ensure comprehensive coverage.

#### E. The single highest-leverage change
**Change:** Improve the test strategy to ensure comprehensive coverage of all critical areas, including direct tests for previously uncovered areas.
- **Reason:** A robust test suite is essential for maintaining the integrity and reliability of the codebase, especially in a port where divergences and non-exact behaviours are present.

---

### Ranking of findings by (impact x confidence)

1. **Improve the test strategy** (impact: high, confidence: high)
2. **Review and document all known non-exact behaviours** (impact: medium, confidence: high)
3. **Ensure the public API is coherent and consistent** (impact: medium, confidence: medium)
4. **Identify and remove dead code** (impact: medium, confidence: medium)
5. **Review and correct any layering violations** (impact: medium, confidence: medium)
6. **Improve error-handling to ensure consistency and reliability** (impact: medium, confidence: medium)
7. **Ensure the documentation accurately reflects the current state of the code** (impact: medium, confidence: medium)
8. **Review the composition of subsystems to ensure they actually compose end-to-end** (impact: medium, confidence: medium)
