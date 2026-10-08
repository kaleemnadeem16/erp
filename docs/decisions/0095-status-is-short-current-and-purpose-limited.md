# 0095 — STATUS.md is short, current and limited by purpose, not by a row count

- **Status:** accepted (2026-10-08)
- **Date:** 2026-10-08
- **Affects:** docs/STATUS.md, docs/STATUS-LOG.md, docs/phases/ handoffs, AGENTS.md's maintenance rules
- **Supersedes:** [ADR 0044](0044-status-log-context-budget.md)

## Context

[ADR 0044](0044-status-log-context-budget.md) kept `STATUS.md` small with a hard budget: at most eight one-line "Recently done" rows and about ninety lines, rotating older
rows into `STATUS-LOG.md`. In practice the budget did not hold. By 2026-10-08 `STATUS.md` carried 104 "Recently done" rows (many several lines long), a "Current phase"
paragraph that narrated three weeks of slices, and "Next" paragraphs that described work long since completed ("implementation is next", "tracking is still disabled",
"issue and transfer remain next"). A fixed row count invited two failures: agents either ignored it, or satisfied it by moving a row without ever reconciling what the file
*said* the state was. The cost the ADR wanted to avoid — a large, partly wrong file loaded first in every session — returned, and a stale "next" is worse than a long file
because the next agent acts on it.

The owner (2026-10-08) therefore asked for a different rule: `STATUS.md` should be **short, current and useful**, with no arbitrary line target, and chronological detail
belongs in the status log.

## Decision

1. **`STATUS.md` answers four questions and nothing else:** which phases and workstreams are complete; what is in progress (if anything); what the important next design or build
   areas are, clearly separated from unfinished *required* work; and where to go for history, handoffs and detail. It also records the release state (what production runs
   versus what is on `develop`) because that changes what an agent may assume.
2. **Purpose limits replace the row and line limits.** `STATUS.md` must not contain: chronological implementation logs, per-task or per-commit history, test counts or
   assertion counts, demo-data inventories, or "next" items that are already done. If a statement would be false the day after the work it describes lands, it does not belong
   there — it belongs in the owning phase/domain doc or the log.
3. **One canonical home per kind of information.** Current state: `STATUS.md`. History: `STATUS-LOG.md` (append-only, detailed, never loaded routinely). Phase plans and
   acceptance state: the phase document. Workstream resume points: the workstream handoff, which tells an agent where to resume rather than repeating history. Design history:
   ADRs, which are immutable. Where two documents describe the same current state, one is canonical and the other links to it.
4. **Updating the file.** In the commit that completes or starts a workstream, update that workstream's row in `STATUS.md` (state and link), reconcile any "next" statement the
   change makes false, and append the detailed entry to `STATUS-LOG.md` in the same commit. Day-to-day completed tasks that are not a workstream milestone go to the log
   only.
5. **Historical text is marked as historical.** A document, or part of one, that records how things stood on an earlier date says so at its head and points to the current
   canonical document; it is not left to read as current guidance.
6. The ~300-line one-topic-per-file rule for docs and the code file-size rule in [code-structure.md](../architecture/code-structure.md#file-size-and-splitting) are unchanged.

## Consequences

- **Positive:** the first file every agent loads is concise and true; the reason for brevity (accuracy and load cost) is stated, so judgement replaces a counting rule; history
  stays complete in the log.
- **Negative:** "short" is a judgement. Reviewers must check the purpose limits in point 2 rather than count rows, and a workstream that never updates its row will mislead —
  the handoff rule in point 3 and the reconciliation pass are the backstops.
- **Requires:** a one-time rotation of the existing rows and narratives into `STATUS-LOG.md` (done in the same commit as this ADR), updated maintenance text in `AGENTS.md`,
  `docs/README.md` and `STATUS.md`'s own footer.

## Alternatives considered

**Keep ADR 0044's budget and enforce it** — rejected: it had already failed once and enforcement by counting does not catch a stale statement inside a short row.
**Delete completed work from STATUS entirely** — rejected: a reader needs to know what is complete; a compact workstream table does that without a log.
**Generate STATUS.md from the log or from git** — rejected: the current state, the next design areas and the release state are judgements, not derivable from history.
**Split STATUS per phase** — rejected again for the reason in ADR 0044: agents would load several files or miss context.

## Related

- [ADR 0044](0044-status-log-context-budget.md) — superseded by this decision
- [STATUS.md](../STATUS.md), [STATUS-LOG.md](../STATUS-LOG.md)
- [AGENTS.md](../../AGENTS.md) — maintenance rules
