[← Phase index](README.md)

# Job labour-cost coverage — lightweight SIMPLE declaration proposal

**2026-10-16 · PROPOSED protocol / Q52 AWAITING_OWNER, Origin: PLANNING RESEARCH.**
0146 accepts the three completeness axes and honest reporting. The exact declaration/confirmation
workflow below is research, not an accepted new permission, source of zero cost or implementation.

## Business problem and options

A painter records one recognised15000.0000 expense. It may be fully valued, yet another labour
expense is missing. A small business needs a way to say what it knows without inventing payroll
or timesheets; a larger company may reconcile an integrated source inventory.

Options: infer completeness from all visible rows priced (cheap but misses omitted work); require
full payroll/invoice integration (stronger bounded evidence but excludes small businesses); explicit
scoped declaration plus optional independent confirmation (recommended). No universal maker/checker.

Reuse [labour primary comparison](phase-5-labour-evidence.md) and0110/R7 evidence distinctions.
ERP project-cost reports enumerate source amounts; they do not prove all real-world expenses were
captured. The scoped assertion/invalidation proposal is project-specific inference under owner's
clarification, not a claim any vendor proves universal completeness. No further ceremonial search.

## Required axes versus proposed assessment mechanics

| Axis | Example |
|---|---|
| Source valuation | Expense15000.0000 is fully supported in its currency/basis |
| Labour coverage | Do all applicable labour expense sources for Job/scope/date basis appear? |
| Overall Job cost | Are applicable labour/material/plant/subcontract/overhead categories covered and valued? |

Recommended reporting labels (basis always visible):

| Coverage label | Proposed meaning |
|---|---|
| Confirmed complete | Authoritative supported source/writer inventory and reconciliation confirm all applicable sources within the named scope/basis; no unresolved exceptions. Never a universal audit assertion |
| Declared complete | Eligible accountable user states all labour expenses known for the named scope are represented; explicit human assertion, not machine confirmation |
| Incomplete | Known omitted, pending or disputed source/obligation, or required reconciliation unresolved |
| Unassessed | No current valid coverage assessment; source absence and later data changes cannot imply complete |

Valuation remains independently complete/partial/unknown. A declared-complete category with unpriced
usage has declared coverage and incomplete valuation, never a complete monetary total. A confirmed
labour category does not establish overall Job completeness. Any total based on declaration must be
labelled 'based on declared labour coverage'; never 'confirmed final Job cost'.

## Recommended SIMPLE interaction

After showing current recognised sources, pending/unknown evidence and amounts, offer one optional
statement: 'All labour expenses known to me for this Job/scope through [date/basis] are represented.'
Capture actor/time, covered scope/date/known-as-of, exact source generation/fingerprint, evidence basis
and reason/note where relevant. No hours, named workforce, payroll linkage or extra approval demanded.
This is a coverage assertion, not a monetary source, completion event or payment authorisation.

Reuse legitimate cost-reconciliation/report eligibility for this narrow declaration if its final
authority review finds an equivalent duty. Entry permission alone is insufficient; no new standalone
permission or admin role is approved here. MANAGED policy may require review through shared Approvals.
Do not let declaration conceal known pending entries, omitted liabilities, unpriced facts or disputes.
If required fields/sources are outside the actor's scope, refuse a whole-Job declaration rather than
infer completeness from a filtered list. A permitted narrower scope must be labelled narrower.

An authorised user may declare no incurred labour expense only through an explicit supported no-cost
assertion with basis/reason. Proposed result is declared zero for that scope, not a fake expense row
and never automatic confirmed zero. Until owner approves/delivers this path, empty sources remain
unknown/unassessed. An imported zero or unrelated free-issue stock does not establish zero labour.

## Invalidation and history

Declaration pins source/scope generations. A newly reported relevant expense, coverage-changing
correction or unresolved overlap prevents using it as current complete coverage: known omission means
INCOMPLETE; otherwise assess again rather than silently auto-trusting. Preserve original declaration
and report/closeout snapshots. Pure rate valuation changes need not erase a valid coverage assertion
if the covered source set is unchanged, but valuation completeness/version updates independently.
Scope changes or newly applicable categories invalidate dependent whole-Job completeness.

Confirmation needs a real bounded source inventory and reconciliation adapter; do not implement a
checkbox labelled Confirmed or auto-promote after elapsed time/closure/approval. SIMPLE declaration
does not automatically become confirmed when an invoice/payroll integration is later enabled.
An optional declaration is not a new closeout prerequisite.0145 guards known unresolved operational
facts; an honest incomplete/unassessed cost report can coexist with operational closure.

## Failure probes and remaining owner choice

- Fully priced15000 source + unassessed category → no final complete total.
- Declared-complete labour + unresolved material → whole Job incomplete.
- New expense after declaration → no stale current declaration, historical evidence retained.
- Known pending/disputed source → complete declaration refused or explicitly incomplete.
- Restricted source visibility → whole-Job assertion refuses; no hidden counts/amount leakage.
- Explicit zero assertion, if approved → declared zero only, never automatic empty-list zero.
- Rate-only correction → valuation changes, coverage is not silently promoted/demoted without cause.
- Declaration/relevant-source race → generation checks prevent falsely current complete coverage.

Owner choice: approve/revise the scoped optional self-declaration, zero-assertion boundary and
generation-based invalidation; alternative is keep SIMPLE coverage unassessed until independent
reconciliation. Recommend declaration with disclosed basis, no extra approval by default and no
claim of confirmed coverage without actual adapters. Deferring this protocol does not block the
accepted completion/direct-amount workflow; reports remain unassessed/incomplete honestly.

## Related

- [0146](../decisions/0146-recognise-flexible-labour-evidence-without-fabricating-usage.md)
- [Labour contract](../domain/job-labour-recognition.md) · [Phase5](phase-5-job-execution.md)
- [Q52](../OPEN-QUESTIONS.md#q52--simple-labour-cost-coverage-declaration)
