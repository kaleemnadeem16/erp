[← Decisions](README.md)

# 0098 — Govern opportunity requirements through one hybrid Sales workflow

- **Status:** accepted — final owner-approved U1 direction reconciled 2026-10-09.
- **Date:** 2026-10-08
- **Affects:** Phase 4 R1-A, quotation entry/issue, Sales policy, permissions and audit.
- **Owner review:** final U1–U6 approval and consistency review completed 2026-10-09; detailed
  delivery gates retained. No implementation is authorised.

## Context

An enquiry requiring qualification (Lead), a specific potential deal (Opportunity), and a
commercial offer (Quotation) have different meanings. A small company may quote a repeat buyer
without tracking a deal; a managed sales organisation may require pipeline linkage. Inventing
opportunities to satisfy a mandatory relationship damages reporting and does not add control.

[R1 research](../phases/phase-4-r1-leads-and-relationships.md) already compares optional
qualification, multiple deals and direct quotation paths. No Sales workflow currently exists.
The earlier Sales domain sketch allows a nullable opportunity, but does not settle company
policy. Existing architecture separates domain capability, company configuration and UI exposure.

## Decision

Keep Leads and Opportunities as separate business concepts. Lead intake is always optional;
opportunities can be created directly, and one lead may yield multiple opportunities. Physical
storage remains R2; conversion foundations are accepted in ADR 0105.

One shared quotation workflow evaluates a company-scoped Sales opportunity-requirement policy.
Every entry path retains the same pricing, commercial approval, revision, issue and acceptance
controls. Direct quotations are legitimate records; do not generate fake opportunities.

| Preset | Opportunity at draft creation | Opportunity at issue | Requirement exception |
|---|---|---|---|
| FLEXIBLE (default) | Optional | Optional | None needed |
| MANAGED | Optional | Required | Explicit authorised bypass with reason, at issue only |
| STRICT | Required | Required | None; lead intake still optional |

### Policy representation and ownership

Expose these three coherent presets initially. Internally describe
their resolved semantics as a requirement gate (NONE / ISSUE / CREATE) and exception rule
(NONE / AUTHORISED_REASON). Do not expose independent switches that permit contradictory
combinations such as STRICT plus bypass. This is a conceptual contract, not a chosen schema.
Unknown/invalid settings fail closed; absence of configuration resolves the explicit default.

The policy belongs to Sales even though its scope is COMPANY. ADR 0081 says module policies do
not belong in the shared Company-profile field registry. Reuse the governed-setting pattern,
company context and audit foundation; do not introduce a generic key/value settings bag.
Policy changes require appropriate authority, reason, actor/time and effective version evidence.
Exact capability vocabulary, storage and version protocol remain R5/R9 implementation design.

Keep policy resolution separate from quotation relationships. A resolved result carries source
scope, effective version and rules. Only COMPANY policy scope is accepted here; team overrides,
precedence, weakening/hardening rules and assignment races require later explicit decisions.
No Sales team or owner-based visibility capability is assumed to exist.

### Narrow MANAGED exception

Provisional capability: `sales.quotation.bypass-opportunity-requirement`. It is additional to
normal quotation issue authority and record scope; role names have no meaning in application code.

The issue Action checks the effective policy, current actor authority and a non-blank explicit
reason. The request/controller repeats the relevant guard. STRICT never admits this exception;
FLEXIBLE needs none. A previous reason, an earlier permission check or a draft checkbox is not a
standing authorisation. Revocation prevents a future issue from relying on that permission.

A bypass waives **only** the missing-opportunity requirement. It does not waive pricing rules,
commercial approvals, recipient readiness, financial setup, scope, freshness, tax/FX or any other
quotation control. No new bypass grant is inferred for managers or existing roles; rollout must
be explicit and least privilege when implementation is planned.

### Policy transitions and historical evidence

Issued quotations and their issue-policy/exception evidence remain immutable when policy or
permissions change. Every unissued draft is checked against the effective policy at issue.
A draft created under FLEXIBLE/MANAGED is not deleted when STRICT is enabled; it requires an
opportunity before issue. STRICT checks new creation and cannot be evaded by removing a link
before issue. Under STRICT, correcting a wrong link requires atomic replacement; ordinary
temporary unlinking is prohibited. Earlier-policy unlinked drafts may remain and be repaired,
but cannot issue without a valid link. [ADR 0099](0099-separate-quotation-issue-and-crm-association.md)
settles current-family association and eligible OPEN targets; physical revision-entry mechanics
remain R2/R5. Neither a historical draft nor a new revision grants an issue-policy exception.

Capture at successful issue: company, quotation/revision identity, actor, issue timestamp,
resolved policy source/version/rules, opportunity identity or explicit absence, and whether the
MANAGED exception was used, with its capability and reason. Store sufficient immutable rule
values to explain the result without today's settings or permission grants. Generic activity logs
are supplementary; domain evidence is authoritative. Exact physical evidence storage is pending.

Reports must distinguish issued quotations with opportunity linkage, ordinary direct quotations,
and MANAGED exception quotations. Do not reinterpret historical compliance under today's policy.
R1-B/R9 decide how draft origin and later linkage appear in reports and prevent forecast duplication.

### Concurrency, failure and retry review

The intended guarantee is atomic issue: status transition, snapshots, numbering allocation and
successful exception evidence commit together. Failure creates no successful-issue or successful-
bypass record. Attempt/refusal audit, if added, is separate and clearly labelled unsuccessful.
External communications happen only after commit and must be retry-safe; R8 defines delivery.

Issue locks/re-reads quotation and applicable policy in a stable order; policy changes and issue
must have a defined serialisation point. Re-read live authority rather than trust UI/session
permission snapshots. The exact coordination with concurrent role/direct-grant revocation remains
an implementation-design question: current foundations do not prove a globally atomic authority
snapshot. Revocation committed before the issue authorisation point must be observed; a stronger
commit-order guarantee needs a shared locking/version protocol, not an unsupported claim.

A retry key binds company, actor and normalised intent (including explicit exception reason).
Different intent with the same key is refused. A completed retry returns the original immutable
result without issuing again or appending exception evidence; current access/authority is still
checked under domain-integrity §3. This does not create a future bypass after revocation. An
uncompleted retry re-evaluates current policy and permissions; it cannot reuse a failed exception.
Exact replay permissions and policy-change response belong to R5/API design; stricter current
policy must never rewrite an already completed result.

### UX direction

MANAGED permits uninterrupted drafting. The readiness checklist visibly requires an opportunity,
with a straightforward create/link path subject to its own permissions and scope. Show the bypass
choice only to eligible users; collect the reason at issue. A user with no create duty may link an
eligible existing opportunity or seek an authorised person. Server refusal remains authoritative
if policy/permission changes after rendering.

FLEXIBLE has no unnecessary CRM warning. STRICT requires a valid link when creating a quotation.
Do not confuse an opportunity link with customer onboarding, approvals or readiness.

## STRICT: imports, integrations and historical migration

**No exception accepted.** Ordinary imports and integrations are entry paths to the same Actions:
STRICT requires a real eligible opportunity before draft creation and at issue. Staging may retain
invalid rows for correction; staging is not quotation creation. Source IDs map existing genuine
deals, never synthetic opportunities. MANAGED integrations must provide an explicit actor,
capability and reason for any issue exception; a service identity is not an implicit bypass.

Legacy quotations without valid opportunity relationships may initially be retained only as
**read-only historical records with provenance**. They are not normal operational quotations and
cannot silently become eligible for revision, acceptance or ordinary handover. Do not fabricate
opportunities, temporarily weaken policy, backdate a new issue or use the MANAGED exception for
migration. A separately governed live historical-import purpose is not authorised here; detailed
migration and eligibility require explicit later R5/R9 decisions.

## Reconciliation and unresolved questions

- **Compatible:** ADRs 0028/0062/0089 (capabilities, company scope, independent record scope),
  0070/0085 (approval controls/evidence), 0055 (independent Sales pricing), 0079 (numbering),
  0096 and invariants 1/12/13/14 (shared guards, immutable issue, retry-safe domain integrity).
- **Placement conflict avoided:** a company-scoped Sales policy placed in shared Company settings
  would conflict with ADR 0081. Sales owns it.
- **Import conflict avoided:** generic import committing/issuing quotations would violate the
  implemented ADR 0027 staging/domain-Action boundary. Initial historical records are archive-only; live historical migration is deferred.
- **No accepted ADR requires leads or an opportunity for every quotation.** Nullable opportunity
  in the Sales sketch remains structurally useful; a global NOT NULL would defeat FLEXIBLE/MANAGED.
- **Preset trade-off:** fewer valid combinations and simpler explanations vs reduced customisation;
  independent rules can be exposed later only with demonstrated need and validation.
- **Resolved business boundaries:** atomic STRICT replacement, repair-before-issue for earlier
  drafts, archive-only initial legacy records and company Sales-policy governance are approved.
  ADRs 0099 and 0103–0108 settle relationship, identity, readiness, ownership/outcome and CRM
  foundations; no genuine R1 business blocker remains.
- **Deferred delivery:** policy/version schema, locks/revocation serialisation, retry mechanics,
  revision and historical migration design remain R2/R5/R9. Acceptance does not approve a
  generic import/integration exception, team policy override or physical schema.


Multiple independent accepted scopes and explicit exclusive groups are now owner-reviewed in
[accepted ADR 0100](0100-independent-quotation-scopes-and-alternative-groups.md). They do not
replace opportunity entry/issue rules, approve forecasts, close a deal or decide job multiplicity.

## Consequences

- **Positive:** small companies avoid unnecessary CRM; managed companies get auditable controls.
- **Negative:** policy-sensitive drafts need readiness and stale-state handling; issue is more than
  checking a nullable field; historical migration may need an additional governed purpose.
- **Requires:** common enforcement for HTTP, imports, integrations and console Actions; policy/
  revocation race, failure/rollback, retry, cross-company and preset tests before delivery.

## Alternatives considered

**Always require lead then opportunity** — rejected by owner direction: unnecessary intake and
fake deals for routine quoting. **Always allow direct issue** — rejected: no managed control.
**Three quotation engines** — rejected: controls and bug fixes would diverge.
**Manager-role bypass or generic override** — rejected: role coupling and excessive authority.
**Independent user-facing flags initially** — rejected for initial exposure: unsupported combinations.
**Temporary STRICT unlink** — rejected: use atomic replacement.
**Operational legacy import by default** — rejected: initial provenance-bearing archive only.
**Grandfather every draft** — rejected by owner direction: effective issue policy must apply.
**Automatic import/system exception** — rejected as an implied permission; needs its own decision.

## Research basis

Question: can optional qualification/direct quotes coexist with stronger governance, and do
import/integration paths require a STRICT exception? Access date: 2026-10-08.

Reuse [R1's Odoo/Dynamics/ERPNext comparison](../phases/phase-4-r1-leads-and-relationships.md#primary-source-comparison).
Fresh targeted evidence:

- [Dynamics quotation creation](https://learn.microsoft.com/en-us/dynamics365/sales/create-edit-quote-sales)
  documents direct quote creation and creation from an opportunity: one commercial capability
  has multiple entry paths, supporting common controls rather than separate engines.
- [ERPNext Data Import](https://docs.frappe.io/erpnext/data-import) offers Submit After Import
  for submittable records. Import/finalisation behaviour differs between products; this feature
  cannot be copied across our explicit staging boundary.
- [Odoo export/import](https://www.odoo.com/documentation/19.0/applications/essentials/export_import_data.html)
  explains external IDs for relationship mapping and repeat updates. Stable source identity helps
  reconciliation; it does not establish permission to waive a required relationship.

Synthesis: transport and business finalisation differ. The three presets and initial read-only
legacy boundary are owner-approved project policy, not vendor standards. Existing comparisons
sufficiently distinguished the alternatives; final U1 approval on 2026-10-09 required no repeated
research. No live historical-migration or general transport exception is authorised.

## Related

- [R1](../phases/phase-4-r1-leads-and-relationships.md) · [Phase 4 plan](../phases/phase-4-sales-quotations-and-handover.md)
- [R1 closure](../phases/phase-4-r1-decision-matrix.md)
- [Sales pipeline](../domain/sales-pipeline.md) · [Authorisation](../architecture/authorization.md)
- [Domain integrity](../architecture/domain-integrity.md) · [Imports](../architecture/data-import.md)
- [ADR 0081](0081-central-company-profile-and-company-wide-settings.md) · [ADR 0027](0027-generic-column-mapping-import.md)
