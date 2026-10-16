[← Decision index](README.md)

# 0147 — Bound Job cost coverage to scope, evidence and source version

- **Status:** accepted
- **Date:** 2026-10-16
- **Affects:** Phase 5, Job reporting, labour recognition; resolves Q52

## Context

A completely valued expense does not establish that all expenses were captured. SIMPLE businesses
need honest reporting without compulsory timesheets or a universal approval step. Owner approved
the refined Q52 proposal on 2026-10-16; this is architecture authority only.

## Decision

Keep individual-source valuation, category coverage and overall Job cost completeness independent.
Use Confirmed Complete, Declared Complete, Incomplete and Unassessed only within explicit scope and
through-date boundaries. Confirmation requires delivered authoritative-source reconciliation;
declaration preserves the accountable person's limited knowledge and never becomes confirmation.

Support an optional authorised SIMPLE declaration that all known applicable expenses are represented.
Retain actor, scope, through-date, evidence, recording time and relevant source version. Refuse known
omissions, disputes and pending authoritative sources. A recognised unpriced source can be represented
while valuation remains incomplete. Material/contradictory evidence invalidates or reassesses current
coverage, preserving history and report snapshots. Every relevant writer participates in versioning.

An empty source list never implies zero. Explicit zero-cost assertions are excluded from the initial
release; preserve their separate future authority/evidence research. Optional capture, timesheets and
declarations do not become prerequisites for supported completion. Known obligations still follow 0145.

## Consequences

- **Positive:** complete source valuation cannot overstate total-cost certainty; SIMPLE remains usable.
- **Negative:** reporting must explain multiple independent statuses and dated evidence limits.
- **Requires:** scoped authority, source-version concurrency, append-only history and honest invalidation
  before coverage activation. No new grant, implementation or accounting finality follows.

## Alternatives considered

**All captured rows valued means complete** — ignores missing sources.
**Mandatory coverage approval or timesheets** — burdens completion without proving exhaustive costs.
**Empty sources imply zero** — conflates absent evidence with an evidenced monetary claim.
**Declarations become confirmed after time/closeout** — invents stronger evidence than was obtained.

## Research basis

Reuse the [coverage comparison](../phases/phase-5-cost-coverage-review.md),
[refined resolution](../phases/phase-5-coverage-material-resolution.md) and
[zero-assertion research](../phases/phase-5-zero-cost-assertion.md), accessed 2026-10-16.
Business Central usage/expense evidence and ERPNext captured expense totals establish recorded
sources, not an exhaustive real-world universe. Odoo's missing-rate fallback is not authority for
zero under 0110. The project-specific synthesis is a bounded optional declaration, preserving
certainty distinctions rather than copying a product's aggregate or zero fallback.

## Related

- [Coverage contract](../domain/job-cost-coverage.md)
- [0146 labour](0146-recognise-flexible-labour-evidence-without-fabricating-usage.md)
- [Phase 5](../phases/phase-5-job-execution.md)
