[← Decisions](README.md)

# 0111 — Disqualify Leads independently of derived Opportunities

- **Status:** accepted — owner-approved A, 2026-10-10
- **Date:** 2026-10-10
- **Affects:** Sales Lead decisions, conversion concurrency and historical reporting

## Context

The CRM foundation refuses disqualification while a linked Opportunity remains OPEN. A duplicate
or mistakenly qualified enquiry can nevertheless have produced a genuine pursuit. Closing that
pursuit to stop intake confuses separate business facts. ADRs 0105/0108 preserve optional intake,
conversion provenance and multiple genuine derived deals; they do not prescribe this coupling.

## Decision

Allow authorised, reasoned Lead disqualification while derived Opportunities remain open.
Preserve relationships, qualification/conversion history and every Opportunity fact. Disqualification
has no automatic cascade, merge, Opportunity closure or duplicate pursuit.

The existing Lead-management duty and relevant scope apply at Request and Action. Preserve
reasoned revival before new conversion of disqualified intake. An authorised retry of an already
completed conversion returns its original deal rather than making a new one. Disqualification
and new conversion serialize on the Lead: conversion winning first can be followed by intake
disqualification; disqualification winning first refuses new conversion until revival.

Current Lead status and historical qualification/conversion events are distinct reporting bases.
Revival preserves decisions and provenance; a genuinely new buying event normally creates a new
pursuit rather than rewriting the old one. No structured reason catalogue or deduplication engine
is introduced. This extends 0105/0108 without changing their accepted identity/optional-intake rules.

## Consequences

- **Positive:** stop inappropriate intake without losing valid independent business.
- **Negative:** current intake state alone cannot measure historical conversion success.
- **Requires:** reasoned append-only history, guarded retry-safe transaction and race tests,
  unchanged derived records and UI explaining the independent effect.

## Alternatives considered

**Block until all deals close** — rejected: forces unrelated pursuit decisions.
**Automatically close or unlink derived deals** — rejected: destroys independence/provenance.
**Introduce duplicate/void/merged states now** — deferred: reasoned disqualification suffices;
identity matching is not authority to merge.

## Research basis

Reuse [CRM policy comparison](../phases/phase-4-crm-policy-review.md#bounded-primary-comparison--accessed-2026-10-10)
and [R1 evidence](../phases/phase-4-r1-remaining-evidence.md), accessed 2026-10-10/09. Dynamics blocks
disqualification when an Opportunity is linked; ERPNext combines manual and downstream Lead states.
Reasoned lifecycle evidence converges, coupling differs. Independent intake is the owner's chosen
project policy, not a vendor standard; no repeated comparison is needed to specify the accepted rule.

## Related

- [Implementation specification](../phases/phase-4-crm-correction-specification.md)
- [CRM correction domain](../domain/crm-corrections.md) · [Policy review](../phases/phase-4-crm-policy-review.md)
- [0105](0105-support-generic-party-identity-and-conversion.md) · [0108](0108-expose-a-progressive-basic-crm.md)
