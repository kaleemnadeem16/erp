[← Domain index](README.md)

# Quotation record access — proposed design (#45)

**2026-10-26 · PROPOSED / planning only / not accepted or implemented.**
Owner direction: [#45](https://github.com/kaleemnadeem16/erp/issues/45) and the revised
[#30 direction](https://github.com/kaleemnadeem16/erp/issues/30#issuecomment-6413435143).
Inspected baseline: `develop@d4746d7d`. This proposal authorises no code, schema, routes,
permission registration, grants or activation. Owner decisions below gate implementation.
It does not change #30's separate cross-company-404 correction. On 2026-10-27, #30's read adapters
and correction integrated at `2f74f66bd24d04015d53a919cf24f16f894cbe80`; they remain STAGED.

## Governing boundaries and evidence

Reuse [0106](../decisions/0106-separate-sales-ownership-and-access.md): responsibility is not
permission; [0138](../decisions/0138-authorise-sales-and-document-duties-with-explicit-scope-and-grants.md)
permits restricted scope only after complete enforcement. The new owner direction selects that
future work; it does not retrospectively activate R9 or reopen accepted CRM duties. Preserve
[0098](../decisions/0098-hybrid-sales-opportunity-requirement.md),
[0099](../decisions/0099-separate-quotation-issue-and-crm-association.md),
[0107](../decisions/0107-separate-opportunity-stages-and-outcomes.md),
[0108](../decisions/0108-expose-a-progressive-basic-crm.md),
[0139](../decisions/0139-bind-sales-authority-limits-approvals-and-execution-to-exact-evidence.md) and
[0140](../decisions/0140-publish-sales-measures-and-dated-forecasts-with-persistent-trust.md).

| Inspected repository source | What exists; consequence |
|---|---|
| `backend/app/Modules/Sales/Support/SalesAccess.php` | Manifest ACTIVE + actual capability + `RecordScope::resolve(...)->isAll()`; restricted Sales currently refuses. Do not just remove `isAll()`. |
| `Support/Authority/{SalesDuty,SalesDutyCatalogue,DutyScope,SalesDutyManifest}.php` within Sales | Duty scope is hard-coded COMPANY_WIDE; quotation view/prepare are STAGED. Both metadata and guards need a quotation-specific supported-scope contract. |
| `backend/app/Support/Scope/{RecordScope,ResolvedScope}.php` | Unrestricted conferring assignment wins; otherwise MY_UNITS, active descendant closure, or NONE. No OWN/delegation representation. `resolve` answers scope, not capability activation. |
| `backend/app/Modules/Administration/Models/{OrganisationUnit,AccessScopeLimit}.php` | Company units/tree/membership and per-user role/direct limits exist. No managerial membership marker. Membership alone grants nothing. |
| `backend/app/Support/Scope/ScopedResourceTypes.php` | Module-owned resource registry; current polymorphic unit bindings lack a target FK. Reuse registry conventions, not an extra quotation-unit mirror. |
| `backend/app/Modules/Sales/Models/{Opportunity,QuotationFamily,QuotationRevision}.php` | Opportunity has owner_id; family has immutable created_by, association and revision generations, but no responsible owner/unit. Revisions belong to one family. |
| `Actions/ReassignOpportunity.php`, `Support/SalesRecords.php`, `SalesOwnerController.php` within Sales | Active-company-member selection and audited responsibility transfer patterns exist. These are not a team graph and do not authorise quotation reassignment. |
| `backend/app/Models/User.php`; app/migration search for manager_id, reports_to, reporting_manager, sales_teams | No line-manager reporting relationship or Sales-team security model found. Do not infer one from role titles or Opportunity ownership. |
| `backend/app/Modules/Administration/Actions/{SyncMemberAccess,SaveRole,ChangeMemberStatus,SyncOrganisationUnitMembers,SaveOrganisationUnit}.php` | Reusable administration operations; no common quotation-authorisation generation/fence currently provided. Suspension removes sessions/tokens, insufficient for stale jobs/direct callers. |
| Sales `Actions/{CreateQuotationDraft,ReadQuotationDraft,PreviewQuotation,UpdateQuotationDraft}.php` | Current guards are company-wide. Detail uses view; preview/update use prepare. Create records creator. Preview checks input generation, not access freshness. |
| Sales `Support/Quotation/{QuotationOperation,QuotationOperationReceipts}.php` | Existing transactions, actor/company/key receipt, 85-character key, exact replay and stable result IDs. Reuse and extend authority-before-replay/lock ordering; no second receipt engine. |

The [five-source primary-documentation comparison](quotation-record-access-comparison.md)
was completed for the #45 CHANGES verdict on 2026-10-26. It distinguishes vendor behaviour from
project choices, reconciles creator/owner/reassignment/sharing and maps every OD approval gate.
Research is no longer missing; all seven OD entries still contain owner decisions. Reuse
[0106's earlier evidence](../phases/phase-4-r1-remaining-evidence.md) and
[0089](../decisions/0089-organisation-units-and-record-scope.md) alongside that comparison.

Repository entry points: [SalesAccess](../../backend/app/Modules/Sales/Support/SalesAccess.php), [RecordScope](../../backend/app/Support/Scope/RecordScope.php), [QuotationFamily](../../backend/app/Modules/Sales/Models/QuotationFamily.php), [ReassignOpportunity](../../backend/app/Modules/Sales/Actions/ReassignOpportunity.php), [QuotationOperation](../../backend/app/Modules/Sales/Support/Quotation/QuotationOperation.php).

## Recommended architecture

One Sales `QuotationAccess` policy service supplies both a SQL eligibility predicate and the
single-record loader/assertion. It consumes the **existing** RecordScope assignment resolver,
SalesDutyManifest, actual Spatie capability, fresh active-member/company checks, family ownership,
unit reach and typed delegation evidence. A Laravel policy/Request is an adapter to this service,
not another set of rules. Do not install an actor-dependent global Eloquent scope: internal
workflows still need explicit, purpose-bound reads, as RecordScope already documents.

Recommended shared scope extension, confined initially to quotation-aware duties:

| Assignment scope | Family eligibility, after exact ACTIVE capability and actor checks |
|---|---|
| No limit (ALL) | Every family in this company, only under reviewed explicit company-wide assignment/qualifying mandate. |
| Existing MY_UNITS | Family.access_unit_id in RecordScope.memberUnitIds(actor), including active descendants; unassigned/inactive units confer no team path. |
| Proposed OWN | Family.responsible_owner_id = actor.id. Creator is not considered. |
| Proposed OWN_OR_DELEGATED | OWN, or an effective named grant for this family and requested operation. Explicit opt-in to receiving delegation. |
| NONE / unsupported combination | No records. An unknown scope never falls through to MY_UNITS or ALL. |

Extend `access_scope_limits` and `ResolvedScope` rather than inventing a new role/permission store.
The two new names fit the existing varchar(16); extend its CHECK, not its default. Preserve the
union of alternatives from **conferring grants of the same duty**: OWN plus MY_UNITS means their
union; any unrestricted conferring grant still means ALL. Never take the widest of unrelated duties.
Structured scope must retain own/unit/delegation alternatives rather than collapsing all limits
to unit IDs. A role-level OWN limit affects that role's permissions: reject mixed unsupported
roles at administration validation; use dedicated quotation roles or appropriate direct limits.
Legacy warehouse/CRM adapters must explicitly refuse new unsupported variants, preserving old
ALL/MY_UNITS behaviour. Do not modify the eight CRM duty meanings in this workstream.

For an actor a, duty d, family f, at one server UTC instant t:

`eligible = same company AND active actor AND manifest ACTIVE(d) AND actual grant(d)
 AND union_of_supported_assignment_paths(a,d,f,t) AND independent operation/source/field guards`.

This is a record selector, never a substitute for issue/discount/cost/source/approval authority.
With several mandatory duties, apply each duty's predicate independently and intersect results.
No view right means no detail, even if prepare is held. No prepare means no commercial preview,
even if view and a PREPARE record grant are held. Holder recipes may assign both explicitly;
permission implication is not introduced. Counts and safe metadata cannot expose preview figures.

### Team/manager access

Recommend the existing unit tree as the first team boundary, with a typed nullable family
`access_unit_id`. Explicit MY_UNITS quotation grants and active membership in the appropriate
parent unit provide team oversight. A parent-unit member reaches active descendants under existing
0089 semantics; a leaf member does not gain parent/sibling reach. Membership in a unit is **not**
a manager flag. Only reviewed holders receive the relevant capability/scope; ordinary teammates
with OWN do not gain team visibility. No new Sales team, manager-user edge or hierarchy algorithm.

Do not derive the unit dynamically from the owner's possibly multiple memberships. Select one
explicitly, validate company/active eligibility and record changes. Own-only companies may leave it
null; MY_UNITS cannot reach such a family. Use a typed family→unit composite FK and direct query
predicate, not duplicate `organisation_unit_resources` rows. Its polymorphic binding path provides
no target FK and its generic mutable sync Action must not become an unaudited quotation writer.
If the owner wants actual direct-report hierarchy instead of unit oversight, that is a separate
unaccepted structure and scope adapter; this proposal does not pretend it already exists.

### Named delegation

A grant names company, family, issuer, recipient, VIEW or PREPARE, optional start/end, reason and
actor/time, with an explicit append-only revocation. It never assigns Spatie permissions, changes
scope limits, transfers ownership or grants access to an Opportunity, Party's protected fields,
other families, approvals, issue, costs or files. No team/wildcard/role recipient or transitive shares.

VIEW enables the record path for `sales.quotation.view` only. PREPARE enables record paths for
view and prepare, but issuer and recipient must independently hold **both** ACTIVE capabilities;
preview still checks prepare. The issuer additionally needs the proposed delegation duty and
current **non-delegated** eligibility for every delegated operation. Recipients must be active,
same-company and assigned a compatible scope path; reject ineffective grants rather than create
latent future privilege. An unrestricted recipient already reaches the record; report a no-op.
Reject self-sharing and overlapping non-revoked epoch-valid grants of the same level, including
scheduled future shares; to change level or
period, revoke and issue a new grant, never rewrite evidence. VIEW+PREPARE cannot combine ceilings.

Recheck issuer and recipient authority at use, not only creation. Pin monotone authorisation, organisation/capability and
ownership epochs (storage companion); suspension/removal/reassignment makes old grants ineffective
permanently, including after reactivation or return to the previous owner. Benign authority changes
also invalidate pinned grants in this conservative first design; the owner must approve that cost.
Optional start/end are UTC instants with [start,end) semantics; null start means recorded time,
null end means until revocation/epoch invalidation, if the owner permits unbounded grants. Expiry
needs no scheduler or deletion. Renew only by a fresh governed grant. Every grant has its own key;
retries after access loss must refuse before receipt replay and reveal no previous protected result.

### Lifecycle and disclosure

- Creator stays immutable on family and on each revision. Responsible owner is one current active
  eligible member selected at creation (default actor, not Opportunity owner); selecting another
  needs assignment authority. New revisions of the same family inherit its current access state,
  not the revising actor as owner. A copied **new family** gets new ownership and no copied grants.
- Ownership/unit reassignment is explicit and audited; neither a CRM relink nor a manager change
  silently transfers it. Existing issued snapshots, attribution and document hashes stay immutable.
  Reassignment updates administrative responsibility, not commercial facts or maker identity.
- Suspended/departed members retain historical references; flag recovery needed. Never auto-reassign,
  cascade-delete history or grant a replacement privileges. Only explicitly scoped recovery holders
  can see/reassign orphaned families. Unit membership/tree changes affect current team access
  immediately, while old access/assignment events remain evidence, not current entitlement.
- Record denial is indistinguishable from missing/foreign record: generic 404 on record-addressed
  adapters and the equivalent domain not-found for direct callers. Missing/inactive duty = 403
  before existence lookup; unauthenticated = 401. Lifecycle/stale/validation detail follows only
  after eligibility. Do not leak a hidden title, old owner, grant existence or counts in errors.
- Apply the selector before sorting/pagination/counting, candidate lookup, aggregate/export and
  nested includes. Current access governs old revisions/history; it never rewrites past evidence.
  Reports label their business as-of basis but do not restore a former employee's present access.
  Frozen report/PDF payloads mixing hidden records need an authorised separate rendition or refusal.
- Cache by company/actor/exact duty/family + authority/ownership/access generations and validity
  bound; first delivery should use no cross-request positive access cache. Queue execution and
  download/print must re-authorise the human at use/delivery. Tokens, URLs and old receipts are not
  bearer privileges. Already delivered bytes cannot be recalled; in-flight decisions linearise
  at the protected read described in the storage contract.

## Owner decisions required before implementation

| ID | Proposed default; actual decision needed |
|---|---|
| OD1 | **Owner approval required:** OWN/OWN_OR_DELEGATED choices, default scope and per-company real holder/mandate recipe. Preserve already accepted additive per-duty grants; ordinary preparers should not receive an unnoticed unrestricted path. |
| OD2 | **Owner approval required:** Approve unit-based team oversight with existing descendant reach, explicit family unit and null-unit own-only workflow. If actual line-manager reporting is required, specify its separate governed relationship first. |
| OD3 | **Owner approval required:** Approve new assignment/delegation/revocation duties and their independently scoped recovery holders; proposed recipes are in the delivery plan, no real names or grants inferred. |
| OD4 | **Owner approval required:** Approve default owner=creator for new families; confirm explicit legacy mapping and quarantine of unresolved owners/units instead of a fabricated historical owner. |
| OD5 | **Owner approval required:** Decide permitted delegation duration/unbounded expiry, whether PREPARE must require both view+prepare, and who may revoke others' grants. Recommendation: separate revoke duty, no onward delegation, no arbitrary issue/approval delegation. |
| OD6 | **Owner approval required:** Approve conservative permanent epoch invalidation (including benign access changes), owner/unit transfer invalidating shares, and current-authority read linearisation. Refine precision later only with equivalent revocation proof. |
| OD7 | **Comparison delivered:** review the attached primary-source synthesis. **Owner approval still required:** accept/reconcile this design and any future ADR before authorising slices; exact real-holder rollout/staging remains separately gated. |

The [comparison approval matrix](quotation-record-access-comparison.md) marks the remaining owner approval in **each** OD1–OD7; research delivery alone closes none of those decisions.
These are proposal choices, not unanswered questions about accepted CRM/financial semantics.
No accepted ADR is edited. A future accepted decision records the agreed extensions; no ADR number
is reserved here. Rejected alternatives: creator-as-owner forever, role-name manager bypass,
automatic Opportunity inheritance, generic permissions assigned by sharing, recipient-only checks,
frontend-only filtering, polymorphic-only tenant references, and an independent ACL permission engine.

## Related

- [Primary-documentation comparison and OD approval matrix](quotation-record-access-comparison.md)

- [Storage, Actions and concurrency](quotation-record-access-storage.md)
- [Ordered delivery and acceptance tests](../phases/phase-4-quotation-record-access-slices.md)
- [Quotation lifecycle](quotation-lifecycle.md) · [Sales duty matrix](sales-duty-matrix.md)
- [R9 rollout](../phases/phase-4-r9-permission-rollout.md) · [Authorisation](../architecture/authorization.md)
