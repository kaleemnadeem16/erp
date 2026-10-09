[← Decisions](README.md)

# 0106 — Separate Sales responsibility from access and preserve reassignment history

- **Status:** accepted — owner-approved U4; final consistency review passed.
- **Date:** 2026-10-09
- **Affects:** Sales ownership/assignment, inactive-member recovery and R9 access integration.
- **Gate:** no Sales team, scope enum, permission catalogue or physical schema selected/implemented.

## Context

Assigning Bilal to follow up a deal after Aisha leaves should preserve responsibility history and
must not confer Finance rights or unrestricted access. Company membership, suspension, organisation
units and additive record-scope foundations exist; Sales ownership/scope integration does not.

## Decision

Each opportunity has one accountable eligible owner; new owners must be active company members.
Lead intake may be unassigned. Ownership means responsibility for follow-up, not automatic record
access, unrestricted visibility or Finance authority. New assignment validates the destination's
eligibility and appropriate authority, rather than silently creating grants.

Reassignment requires appropriate authority and audited old/new ownership history. Preserve prior
owners, including suspended/inactive users. If eligibility later changes, flag reassignment needed
rather than silently deleting an owner or losing work; allow authorised, scoped management recovery
of inactive/orphaned assignments. No suspension-triggered grant or automatic reassignment rule.

Company-wide visibility may be the simple initial configuration through explicit capability grants.
Restricted own/assigned/unit visibility must be properly integrated before exposure, across API
reads/writes, searches, lookups, counts, exports, history and reporting. Management oversight also
requires appropriate capabilities and record scope; a role name or organisational title is not
an exception. Assignment and scope changes never weaken field confidentiality.

Sales team structures/team visibility are not assumed implemented. Shared selling teams, routing
and team automation may be deferred. Exact scope integration, capability names, assignment/queue
mechanisms and management reporting remain R9. Unsupported restricted modes must fail closed,
not present a UI filter as security.

## Reconciliation and consequences

Compatible with ADRs 0028/0089: capabilities and record scope are separate, grants remain additive,
company-wide by default unless deliberately limited, units do not replace company tenancy. This
does not accept a second resolver/company/team boundary. Reuse Administration identity/suspension,
shared audit and current Request/Action authority checks. Every future Sales surface needs actual
scope/field enforcement; accepted business foundation is not proof that current warehouse scope
already protects Sales. Finance fields retain ADR 0084 guards; published/issued evidence remains.

- **Positive:** accountable follow-up, controlled recovery and company-size-appropriate visibility.
- **Negative:** restricted Sales coverage and reassignment eligibility need careful integration.
- **Requires:** auditable reassignment, retained inactive history, tenant/field protection and
  complete scope coverage before restricted exposure; concurrency/retry checks on owner changes.

## Alternatives considered

**Ownership grants rights automatically** — rejected: responsibility is not authority.
**Delete or silently replace suspended owners** — rejected: loses accountability/history.
**Mandatory selling-team structure for every company** — deferred: unnecessary initial overhead.
**“My deals” UI filter as security** — rejected: APIs/history/exports could still leak data.
**Manager title grants unrestricted access** — rejected: explicit capability/scope applies.

## Research basis

Reuse [ownership evidence](../phases/phase-4-r1-remaining-evidence.md#primary-source-comparison--bounded-additional-evidence),
accessed 2026-10-09: Dynamics ownership/unit privileges/reassignment, Odoo team assignment and
ADR 0089's record-scope comparison. Ownership and security differ; vendor disabled-owner assignment
or cascades are not copied. The owner chose eligible active new owners with preserved historical
inactive responsibility. No repeated research or new implementation mechanism was needed.

## Related

- [R1 closure](../phases/phase-4-r1-decision-matrix.md) · [Authorisation](../architecture/authorization.md)
- [ADR 0089](0089-organisation-units-and-record-scope.md) · [ADR 0084](0084-separate-financial-master-data-fields.md)
- [Sales pipeline](../domain/sales-pipeline.md) · [Phase 4](../phases/phase-4-sales-quotations-and-handover.md)
