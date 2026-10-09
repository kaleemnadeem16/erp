[← Decisions](README.md)

# 0105 — Support organisation and individual Parties with controlled identity reuse

- **Status:** accepted — owner-approved U3; final consistency review passed.
- **Date:** 2026-10-09
- **Affects:** Party identity, R1-C3/C4/D/G, qualification/conversion and future B2C compatibility.
- **Gate:** bounded business contract only; no fields, backfill, migration or implementation chosen.

## Context

ABC Industries is a contracting organisation. Fatima may buy personally or act as ABC's contact.
Those relationships must remain distinct. Existing Party can have customer, supplier, both or
neither financial role, but its current organisation-oriented model lacks a kind discriminator.
A separate prospect master or duplicated customer identity would fragment history.

## Decision

Support both organisations and individual contracting customers through the existing Party
foundation in the initial design. Use organisation-oriented default UX and expose individual
controls where relevant. Party identity stays independent of financial roles. Contacts and sites
remain distinct from the contracting Party: Fatima acting for ABC does not make Fatima its customer
account; personal purchase requires explicitly resolving her contracting identity.

Search eligible company-scoped identities and warn about potential duplicates before creation.
Explicitly reuse or restore the appropriate identity through governed operations. Preserve code/
account uniqueness; name, email or phone matches do not authorise automatic merge. Search and
archived-candidate discovery remain permission/scoped; restricted matches require authorised
resolution rather than disclosure of hidden identity. Ambiguous matches need human resolution.

Qualification/conversion preserves original enquiry, selected identity, actor/time and resulting
deal provenance. Do not silently overwrite master fields or create CustomerAccount. Financial
roles, including restoration of each role, remain independently governed through appropriate Party
Actions and financial permissions. No automatic restore of Finance authority or financial roles.
One enquiry may produce multiple genuine opportunities through explicit operations; retrying the
same intent cannot duplicate them. Reusing supplier identity must not change its payable role.

Advanced B2C, master merging and jurisdiction-specific validation are not required immediately by
this agreement. Exact fields, person validation, existing-data classification/backfills, privacy,
restore mechanisms and physical conversion references remain later implementation research.

## Reconciliation and consequences

ADR 0034 retains ADR 0030's unified identity and parent-derived child scope; this extends the kinds
of contracting identity without replacing that architecture or making account roles exclusive.
ADRs 0084/0103/0104 preserve Finance authority, recipient evidence and readiness separation.
Immutable issued identity/contact/address details survive master corrections and conversion.
Tenant boundaries, current Request/Action authority, append-only audit and atomic retry guarantees
apply. R2 selects quotation identity references; R4 jurisdiction/tax; R5 issue corrections; R9
privacy, permission and restore integration. No accepted ADR needs superseding.

- **Positive:** generic customer identity and B2C compatibility without parallel masters/duplicates.
- **Negative:** person validation/privacy and controlled archived reuse require additional design.
- **Requires:** eligible scoped matching, explicit conversion/restoration, preserved provenance,
  independent financial roles and retry/concurrency proof before delivery.

## Alternatives considered

**Organisation-only foundation** — rejected for initial business scope: delays individual customers.
**Separate prospect/person/customer master** — rejected: existing Party can hold contracting identity.
**Automatic merge or master overwrite** — rejected: ambiguous matches can combine distinct entities.
**Qualification creates CustomerAccount** — rejected: violates independent financial setup/authority.
**Create a fresh identity instead of governed restoration** — rejected as an archive workaround.

## Research basis

Reuse [remaining R1 evidence](../phases/phase-4-r1-remaining-evidence.md#primary-source-comparison--bounded-additional-evidence)
and [C1 comparison](../phases/phase-4-r1-c1-prospect-quotations.md#primary-source-comparison), accessed
2026-10-09: Odoo Person/Company contacts and create/link conversion, ERPNext customer types and
Dynamics duplicate-candidate review. These support shared identity and explicit human reconciliation;
our immutable evidence/financial-role contract rejects vendor-style automatic merge/defaulting.
Owner-approved direction, not a copied vendor schema; existing evidence was sufficient.

## Related

- [R1 closure](../phases/phase-4-r1-decision-matrix.md) · [Parties](../domain/parties.md)
- [ADR 0034](0034-party-child-tables-inherit-company-scope.md) · [ADR 0084](0084-separate-financial-master-data-fields.md)
- [ADR 0103](0103-issue-quotations-to-party-backed-prospects.md) · [ADR 0104](0104-separate-customer-acceptance-and-readiness.md)
