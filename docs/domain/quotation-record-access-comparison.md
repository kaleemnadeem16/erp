[← Proposed quotation access design](quotation-record-access-design.md)

# Quotation access — primary-documentation comparison and decision reconciliation

**2026-10-26 · #45 CHANGES response · research complete, design still PROPOSED.**
Read all five owner-supplied primary pages below on 2026-10-26; each canonical URL returned HTTP 200.
This replaces the earlier missing-comparison gate. It does not approve OD1–OD7, select live holders,
authorise implementation, change accepted ADRs, or claim vendor defaults are ERP policy.

## Question, scope and primary sources

Question: how should operation capabilities combine with own/team/shared record eligibility,
while keeping creator, responsible owner, reassignment and collaboration separate?

| ID | Primary documentation and relevant section |
|---|---|
| D1 | [Dynamics 365 / Dataverse: Sharing and assigning](https://learn.microsoft.com/en-us/power-apps/developer/data-platform/security-sharing-assigning) — Sharing records, RevokeAccess, Sharing and inheritance, Assigning records. |
| D2 | [Dataverse: Access teams and owner teams](https://learn.microsoft.com/en-us/power-apps/developer/data-platform/use-access-teams-owner-teams-collaborate-share-information) — team ownership, security roles and access-team rights. |
| O1 | [Odoo 19.0: Security in Odoo](https://www.odoo.com/documentation/19.0/developer/reference/backend/security.html) — Access Rights, Record Rules, Global rules versus group rules, Field Access and public-method/ORM pitfalls. |
| E1 | [ERPNext: Role Based Permissions](https://docs.frappe.io/erpnext/permissions) — operation/field-level permissions, User Permissions and the Leave Approver example. |
| E2 | [ERPNext: Sharing](https://docs.frappe.io/erpnext/sharing) — document-specific sharing, sharing rights, User Permission exception and recipient selection. |

These pages describe product mechanisms, not a tested local configuration. O1 is specifically
version 19.0; the other supplied pages are live documentation, not a version-pinned product test.
No page here establishes this ERP's quotation-family schema, grant expiry protocol or holder recipe.

## Observations — what each product actually establishes

| Approach | Source-supported observation | Difference to preserve in the comparison |
|---|---|---|
| Dynamics sharing/assignment (D1) | Specific records can be shared with selected access rights; recipient table privileges still bound usable rights. Multiple access paths combine. Assignment changes ownerid; sharing can be removed. | Previous-owner sharing and related-record sharing have configurable/cascading behaviour. Do not copy automatic retention or cascades into quotation ownership. |
| Dynamics team types (D2) | Owner teams own rows and have security roles. Access teams neither own records nor hold roles; they receive record rights while members rely on their other privileges. | This is a distinct team architecture, not evidence that our organisation units already implement it. Access-team rights alone do not provide creation authority. |
| Odoo security (O1) | Model ACLs grant operations; record rules follow. ACL grants accumulate. Global rules intersect; group rules combine within global bounds. Field restrictions are separate. | Record rules permit access when no applicable rule exists after an ACL grant. Public methods and ORM bypasses require care. This permissive fallback is not our restricted-quotation default. |
| ERPNext permissions (E1) | Roles define document operations; User Permissions restrict selected documents/linked values; field permission levels remain separate. The approver example combines operation rights and explicit employee-document eligibility. | A role title does not establish our manager hierarchy. The supplied page does not define our transferable quotation responsible-owner field. |
| ERPNext sharing (E2) | A holder of sharing rights can share a named document with users and selected rights; the UI also offers Everyone. A share can make a document visible despite User Permission restrictions. | Sharing is an explicit exception to that restriction mechanism. Do not claim every ERPNext share obeys our proposed strict recipient capability/scope ceiling; these pages do not prove that. |

## Common pattern and the project's stricter contract

**Synthesis:** distinguish capability permission (what operation may be done) from record-level
eligibility (which record may be reached). Owner, team and sharing mechanisms affect the latter;
field/lifecycle protections remain another boundary. The sources do **not** have identical rule
composition or default behaviour: Odoo's missing-rule fallback and ERPNext's sharing exception
are material differences, not minor terminology changes.

For this ERP adopt `effective capability AND record-level eligibility AND independent guards`.
That is the project inference/recommendation, constrained by accepted
[0106](../decisions/0106-separate-sales-ownership-and-access.md),
[0138](../decisions/0138-authorise-sales-and-document-duties-with-explicit-scope-and-grants.md) and
[0139](../decisions/0139-bind-sales-authority-limits-approvals-and-execution-to-exact-evidence.md),
not a claim that all three products enforce this exact equation for every sharing path.

Use current `SalesDutyManifest`/Spatie permissions and `RecordScope` for capability and supported
grant scopes. Use existing Sales responsibility patterns and company organisation units for the
family's owner/unit path. Named grants supply only a controlled family eligibility path. No second
permission store, duplicated role engine, mandatory Sales-team model or inferred Manager privilege.
The [storage specification](quotation-record-access-storage.md) remains the proposed implementation
contract; this comparison supplies rationale, not alternative tables or an implementation patch.

## Creator, responsible owner, reassignment and sharing

| Concept | Recommended ERP meaning; how the comparison informs it |
|---|---|
| Creator | Immutable created_by on family and revisions records provenance. None of these five pages requires treating the creator as the perpetual responsible owner; do not infer this from a vendor field called owner. Existing quotation immutability remains governing. |
| Responsible owner | One current accountable eligible member on the family, distinct from creator and Opportunity owner. D1's transferable ownership illustrates the distinction from a share; D2's team ownership is a different option we are not adopting. |
| Reassignment | Explicit governed change of responsibility with old/new actor/time/reason evidence; it grants no capability and leaves creator/issued facts intact. Do not copy D1's previous-owner retention or relationship cascades; old shares become ineffective under the proposed epoch rules. |
| Sharing/delegation | Additional, named, bounded record eligibility without ownership transfer. D2 demonstrates non-owning collaboration; E2 demonstrates why widening through a share must be a conscious policy. Our grant never assigns a role, changes a scope limit or delegates onward. |

A later revision in the **same** family follows current family eligibility; a copied new family
does not inherit shares. These are project lifecycle choices, not vendor facts established by the
supplied pages. Owner approval of OD4/OD6 remains necessary for the proposed defaults/adoption rules.

## Reconcile manager access and named delegation

**Manager/team:** D2 separates team roles, ownership and record collaboration. E1's approver example
uses configured employee-document restrictions. Neither supplies our repository with a reporting
relationship. O1 supplies configurable predicates, not a quotation-manager policy. Therefore keep
existing organisation-unit membership/active descendant reach and explicit MY_UNITS grants as the
proposed first oversight mechanism. A parent-unit member with an approved duty may oversee its
scoped families; a Manager-named role, a unit membership alone or responsibility for an Opportunity
confers nothing. Actual direct-report hierarchy would require a separate owner-approved relationship
and adapter; do not introduce one by analogy. OD2 remains open.

**Delegation:** use a named recipient and family, VIEW/PREPARE, optional interval, explicit revoke,
and actor/reason/time evidence. Keep recipient capabilities, issuer's own non-delegated authority,
company boundary and compatible receiving scope mandatory. PREPARE does not supply issue, approval,
cost, source or file rights. Existing view/prepare duties remain separate; preview still requires
prepare. D1's bounded recipient privileges are a useful precedent; E2's broader restriction override
and Everyone option are deliberately not adopted. Our no-onward-delegation, optional-expiry policy,
revocation duty and epoch semantics remain project choices needing OD3/OD5/OD6 approval.

## Why default-deny and no privilege escalation are essential

1. Missing scope support must not expose the company. In particular, a missing restricted-quotation
   predicate cannot be treated as permission to query all records. Preserve STAGED denial until
   every Action, Request, list/count/export/report and replay path has the same supported policy.
2. Sharing must not mint capabilities: otherwise a view-only recipient could obtain prepare/issue
   access, or a manager could distribute authority they do not hold. Validate both principals and
   the exact operation at creation and use; no onward shares, permission writes or combined ceilings.
3. Company and confidential-field boundaries are mandatory intersections. A share cannot open a
   foreign quotation, hidden cost, protected Party evidence or linked Opportunity by implication.
4. A title, stale session, receipt or historical share is not current authority. Suspension,
   revocation, expiry and ownership/organisation changes must stop future use across direct Actions,
   jobs and cached/download projections, not merely hide a screen control.

These requirements follow the accepted repository security contracts and the revised #30 direction;
they are not optional owner choices. Proposed mechanisms for meeting them still need design approval.
Default-deny here does **not** reverse existing additive grant semantics: a deliberately approved
unrestricted conferring grant still means ALL. OD1/R9 rollout must inventory and scope every path
so an unnoticed role, direct grant or mandate cannot defeat OWN restrictions.

## OD1–OD7 reconciliation — approval status after this comparison

| Decision | Evidence/reconciliation | Status / remaining owner approval |
|---|---|---|
| OD1 | Layered capability/record checks are supported by the comparison; product composition differs. Reuse existing additive RecordScope semantics, not Odoo's missing-rule fallback or an independent ACL. | **Owner approval required:** new OWN/OWN_OR_DELEGATED choices, default scopes and actual holder/mandate recipe. Accepted per-duty/no-escalation safeguards are not reopened. |
| OD2 | D2/E1 justify distinguishing configured team/approver eligibility from a job title. Existing units are reusable; no manager graph exists. | **Owner approval required:** unit-based oversight versus genuine reporting hierarchy, explicit family unit and null-unit handling. |
| OD3 | Separate assignment and sharing concepts do not decide our exact duty names or real holders. Reuse Sales catalogue/registry and sensitive-grant exclusions. | **Owner approval required:** proposed assign/delegate/revoke duties, scopes and recovery holders. No registration or grant is authorised. |
| OD4 | Transferable ownership is separate from sharing (D1); these sources do not select our creator/default/backfill rules. Preserve existing immutable creator and family identity. | **Owner approval required:** owner=creator default for new families and explicit legacy capture/quarantine mapping. Creator immutability is already required. |
| OD5 | D1/E2 illustrate selected record rights, but do not settle this ERP's VIEW/PREPARE bundle, expiry or revoke authority. Do not copy Everyone or the User Permission override. | **Owner approval required:** duration/unbounded-end policy, whether a PREPARE share requires both view+prepare, and who may revoke others' shares. Preview's prepare gate is already required. |
| OD6 | D1's configurable previous-owner access shows reassignment retention is a policy choice. These pages do not establish our permanent epoch invalidation protocol. | **Owner approval required:** invalidation on transfers/benign changes, non-revival and decision-time linearisation. Current-authority enforcement itself is mandatory. |
| OD7 | The five-source comparison requested by the CHANGES verdict is now supplied, with observations separated from project choices. | **Research delivery complete; owner approval still required:** accept/reconcile the design and any future ADR, then separately authorise implementation and real rollout/staging. No external comparison remains a missing input. |

All seven OD entries retain an owner-approval component. Completing research is not design
acceptance; design acceptance is not implementation or deployment authority. Continue the existing
five-slice order and reuse list. No accepted ADR, #30 correction or permission infrastructure is
changed by these documentation revisions.

## Related

- [Architecture and owner decisions](quotation-record-access-design.md)
- [Exact proposed storage/Actions](quotation-record-access-storage.md)
- [Ordered delivery slices](../phases/phase-4-quotation-record-access-slices.md)
- [R9 rollout](../phases/phase-4-r9-permission-rollout.md) · [Sales duty matrix](sales-duty-matrix.md)
