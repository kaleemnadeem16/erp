[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# CRM corrections — implementation handoff

**Delivered 2026-10-12.** This records what the implementation agent built for the accepted
[correction specification](phase-4-crm-correction-specification.md) and the
[security correction](phase-4-crm-security-correction.md) (ADRs [0111](../decisions/0111-disqualify-leads-independently-of-derived-opportunities.md)–[0114](../decisions/0114-authorise-historical-attribution-correction-as-a-dedicated-duty.md)).
The specification stays the contract; this file is the as-built record, the verification results and
the operational steps that code cannot do. Behaviour is described in [sales-pipeline.md](../domain/sales-pipeline.md)
and [crm-corrections.md](../domain/crm-corrections.md).

## What was delivered, in commit order

| Slice | Commit | As built |
|---|---|---|
| Security | `33a9806` | `SalesAccess::may` resolves record scope for the requested duty alone (an unrestricted stage/numbering grant no longer widens a restricted duty). `PartyCandidateAccess::allow` takes a scope-aware eligibility predicate, Sales registers its own, and an independent `parties.party.manage` grant is unaffected. |
| Stage fix | `ad56186` | The stages screen cleared a typed rename after the previous one succeeded, which was the cause of the intermittent "stages can be renamed" failure. |
| A | `be70bda` | `DecideLead::disqualify` through `CrmOperation`: reason, `request_key`, `expected_decision_event_id`; derived deals untouched; prior qualification recorded; `LEAD_HAS_OPEN_OPPORTUNITY` removed. Table `sales_crm_operation_receipts`. |
| C | `8e8b9c1` | `RecordLead` writes immutable intake evidence atomically; `CorrectLeadEnquiry` replaces `UpdateLead`; tables `sales_lead_intakes`, `sales_lead_enquiry_corrections`, `_fields`; `leads.enquiry_version`; database guards. |
| B | `219bec0` | `CorrectOpportunityAttribution`, `ReadOpportunityAttributionMembers`, table `sales_opportunity_attribution_corrections`; the new duty and its grant safety (below). |

Migrations (all additive, each `down()` refuses to drop accepted evidence; fresh → rollback → re-apply checked on an
isolated database): `2026_10_12_100000_create_sales_crm_operation_receipts`, `2026_10_12_110000_create_sales_lead_intake_evidence`,
`2026_10_12_120000_create_sales_opportunity_attribution_corrections`. Existing leads received a `CAPTURED_BASELINE` row on rollout.

## The new permission `sales.opportunity.attribution.correct`

- Registered in the Sales vocabulary like any capability, but **excluded from every automatic "all capabilities" grant**:
  `PermissionRegistry::automaticGrants()` omits it, and `erp:create-admin --permissions=all`, `erp:sync-admin-permissions` and the
  demo seeders (`StockDemoSeeder`, `PurchasingDemoSeeder`, `PackagedPurchasingDemoSeeder`) use it. `syncAutomatic()` keeps an explicit
  direct grant across a re-sync.
- Authority to use it = the duty **and** an independent right to read the pipeline, each judged by its own record scope
  (`SalesAccess::mayCorrectAttribution`). Owning a deal, assigning, closing, editing or a manager title confer nothing.
- Granting: only explicitly, through access administration, which cannot grant what the granting person does not hold
  (`AccessAssignmentGuard`), or automatically only under a verified mandate: `config('access.full_access_mandates')`
  (JSON in `ACCESS_FULL_ACCESS_MANDATES`; each entry names company, an existing role id, `ALL_REGISTERED_CAPABILITIES`, an approval
  reference and an effective date, and the person must hold that role). The default is empty, so nobody is covered.
- **Not assigned to any real role or person.** The only holder is the demo `Sales attribution reviewer` persona (read + correction),
  bootstrapped directly by the demo seeder because no demo administrator holds the duty. Production holders are an owner decision.

## Granting `sales.opportunity.attribution.correct` — procedure (nothing below has been run)

The duty is **assigned to no real role or person** and must stay that way until the owner approves the intended holders. Nothing in
code, deployment (`permission:register`, `erp:sync-admin-permissions`) or seeding grants it. Approval of holders is recorded first
(who, why, reference); only then is any step below taken.

1. **Register the permission** — happens on every deploy (`permission:register`). It creates the row and grants it to nobody.
2. **First holder (bootstrap).** An administrator cannot grant a permission they do not hold (`AccessAssignmentGuard`, used by
   `SaveRole` and `SyncMemberAccess`), and no administrator holds this one, so the first holder needs the privileged path the deployment
   guide reserves for bootstrap and recovery ([deployment.md](../architecture/deployment.md#production-data-changes)):
   - *Available today, for a new person:* `erp:create-admin --email=… --name=… --permissions=<explicit names>` with the duty and the
     independent read right it requires (for example `sales.pipeline.view`), never `--permissions=all` (which deliberately omits the duty).
   - *Gap — an existing person:* no command grants one named permission to an existing user, and an ad-hoc console edit is not a
     reviewed procedure. If the approved first holder is an existing person, a small audited one-off command would have to be added
     (named permission, target email, approval reference, refuses a permission that is not marked sensitive, writes an access-audit
     entry). That is new operational surface: it needs the owner's approval before I build it.
3. **Later holders and roles — normal access administration.** The first holder, now holding the duty, can build an explicitly approved
   company role that includes it and assign that role (or the permission directly) to approved members through Access Administration;
   each change needs a reason and is audited, and the guard keeps refusing anyone who tries to grant more than they hold. A role that
   contains the duty must also give its members an independent right to read the pipeline, because authority is the duty **and** read,
   each judged by its own record scope (a "my units"-limited assignment of either part refuses).
4. **Automatic inclusion (optional, only for a genuinely full-access role).** If an existing company role is meant to receive every
   registered capability including this one, add a reviewed entry to `ACCESS_FULL_ACCESS_MANDATES` (company id, existing role id,
   `ALL_REGISTERED_CAPABILITIES`, an approval reference, an effective date). A role name, description or permission count is never accepted
   as proof; the entry is the owner's attestation of an approved role. Leave it empty until then.
5. **Verify** (after any grant): the person can correct a closed deal's recorded responsibility and cannot assign, close or edit; a
   person without the duty is refused over HTTP and in the Action; `permission:register` plus an admin re-sync did not remove an
   explicit grant and did not add it to anyone else.

## Verification recorded

- Backend: Pint and Larastan clean; the full suite passes apart from two `LoginTest` cases that fail only locally because the
  local `.env` raises the login limit (hosted CI passes them); an intermittent timing failure in an Inventory receipt test
  (`ReceiptPackageEvidenceTest`) passed on rerun and is outside this work.
- Regression coverage: `CrmFoundationTest` (mixed grants over HTTP and direct Actions, lists, owner lookup, no mutation),
  `PartyIdentityReuseTest`, `LeadDisqualificationTest`, `LeadEnquiryCorrectionTest` (including raw-SQL attacks on the evidence
  and projection), `OpportunityAttributionCorrectionTest` (authority matrix, chain, races, raw-SQL attacks),
  `AttributionGrantRolloutTest` (re-sync, explicit grant survives, mandates, `create-admin`), `SalesCrmDemoSeederTest`.
- Frontend: three typechecks and `vite build`; `sales-crm.spec.ts` (8 journeys including mixed-grant denial, duplicate-intake
  disqualification, original-versus-corrected enquiry, attribution correction, phone width and light/dark accessibility).
- Hosted CI on `develop` at `f1ffb19` (run 38246902801): lint-backend, lint-frontend, codegen-check, backend tests (2,405 passed, 12,809 assertions; the two login-throttle cases pass there) and the whole browser suite (218 passed) all green; the image build and deploy jobs are skipped off `main`.
- One slip found by CI and fixed: the first push (`219bec0`) failed `codegen-check` because a generated type file for the Items agent's list filter had been committed without the backend change that produces it; publishing that change (`f1ffb19`) made the generated client consistent again. Generated files are staged only for the change that produces them.

## Not in scope here

Forecast and acceptance semantics, remaining-business disposition, CRM unit visibility and pricing stay with their own research gates
(see the [policy review](phase-4-crm-policy-review.md)).

## Related

- [Correction specification](phase-4-crm-correction-specification.md) · [Security correction](phase-4-crm-security-correction.md) · [Policy review](phase-4-crm-policy-review.md)
- [CRM correction evidence](../domain/crm-corrections.md) · [Sales pipeline](../domain/sales-pipeline.md) · [Authorisation](../architecture/authorization.md)
- [Sales module README](../../backend/app/Modules/Sales/README.md)
