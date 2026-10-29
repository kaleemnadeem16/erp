[← Proposed access design](quotation-record-access-design.md)

# Quotation record access — proposed physical and Action contract

**2026-10-26 · PROPOSED, docs only (#45).** Every table, migration, permission and method below
is a future implementation target, not delivered code. Read the [architecture and open owner
decisions](quotation-record-access-design.md) first. Names are exact proposed names so subsequent
slices can be reviewed without inventing a parallel contract.

## Reuse and extensions

Retain `CompanyContext`, `BelongsToCompany`, Spatie company teams, `PermissionRegistry`,
`SalesDutyManifest`/catalogue, `FullAccessMandates`, `RecordScope`, organisation-unit membership,
`AccessAssignmentGuard`, `SalesRecords::eligibleMember`, existing quotation families/revisions,
`QuotationOperation`/receipts/`OperationIntent`, and current projection/exception conventions.
Do not repurpose CRM `sales.opportunity.assign` or retrospective attribution corrections for quotes.
Do not mutate `quotation_family_association_events` to represent ownership changes.

Extend shared `ResolvedScope` with proposed `ownsRecords()` and `allowsNamedDelegation()` flags
alongside existing `isAll()`, `isNone()` and `unitIds()`, preserving per-duty union. Extend `RecordScope` with proposed `resolveForResource(User, duty, resourceType)` and
`registerResourceAdapter(resourceType, adapter)` for module-owned supported-scope adapters; its generic existing path must refuse an unsupported new variant.
Extend Sales `DutyScope` with proposed `QUOTATION_FAMILY` and make `SalesDuty` accept explicit scope
metadata for the quotation duties only. Keep CRM/config duties COMPANY_WIDE. `SalesAccess` remains
the capability/manifest gateway: add a capability-only internal check used by the scoped service;
never replace every existing `may()` call with a relaxed quotation check. No general bypass method
may be called directly by a quotation Action without the record policy.

## Annotated pseudo-DDL — additive migrations only

All IDs below have same-company composite FKs, including actors, issuers, recipients, units,
predecessors and family references. Company-scoped tables have UNIQUE(company_id,id); indexes
and uniqueness include company. No cascading deletion of historical business/access evidence.

```text
ALTER quotation_families
  responsible_owner_id nullable -> users(company_id,id)
  access_unit_id nullable -> organisation_units(company_id,id)
  ownership_generation bigint NOT NULL DEFAULT 0 CHECK >= 0
  access_generation bigint NOT NULL DEFAULT 0 CHECK >= 0
  access_baseline_state UNRESOLVED | CAPTURED | NATIVE
  -- UNRESOLVED is legacy only, never a silently inferred historical owner
  -- creator/company stay immutable under quotation_identity_guard
  INDEX(company_id,responsible_owner_id,id)
  INDEX(company_id,access_unit_id,id)

quotation_ownership_events
  id, company_id, family_id, generation                 -- ownership_generation
  previous_event_id nullable -> same-company event in same family
  old_owner_id/new_owner_id nullable -> users(company_id,id)
  old_unit_id/new_unit_id nullable -> organisation_units(company_id,id)
  kind INITIAL | REASSIGNED | UNIT_CHANGED | CAPTURED_BASELINE
  baseline_source_reference nullable, approval_reference nullable
  actor_id -> users(company_id,id), reason nonblank, recorded_at timestamptz
  UNIQUE(company_id,family_id,generation)
  -- NATIVE/INITIAL needs active eligible new owner; old disabled owners retained
  -- baseline says captured now, never invents a previous occurrence time

ALTER users
  authorization_generation bigint NOT NULL DEFAULT 0 CHECK >= 0
  -- monotone security epoch, not a quotation ownership field

company_access_generations
  company_id PK -> companies(id)
  generation bigint NOT NULL DEFAULT 0 CHECK >= 0
  organisation_generation bigint NOT NULL DEFAULT 0 CHECK >= 0
  capability_generation bigint NOT NULL DEFAULT 0 CHECK >= 0
  -- shared security lock/read barrier; not another capability registry

quotation_access_grants
  id, company_id, family_id, issuer_id, recipient_id
  level VIEW | PREPARE
  starts_at timestamptz NOT NULL, ends_at timestamptz nullable
  issuer_authorization_generation, recipient_authorization_generation
  organisation_generation, capability_generation, ownership_generation
  actor_id, reason nonblank, recorded_at timestamptz
  CHECK issuer_id = actor_id AND issuer_id <> recipient_id
  CHECK ends_at IS NULL OR ends_at > starts_at
  INDEX(company_id,recipient_id,family_id,starts_at,ends_at)
  INDEX(company_id,issuer_id,family_id)
  INDEX(company_id,family_id,id)
  -- append only; no permission IDs/bundles or raw financial/source payloads

quotation_access_revocations
  id, company_id, family_id, grant_id, revoked_by, reason nonblank
  revoked_at timestamptz
  UNIQUE(company_id,grant_id)
  FK(company_id,family_id,grant_id) -> grant(company_id,family_id,id)
  -- grant must expose that composite unique target

quotation_access_rollout_entries
  id, company_id, rollout_key, entry_no, subject_user_id nullable, role_id nullable
  permission_name, permission_id -> existing registered permission
  grant_origin, introduced_grant_reference, before_scope/after_scope,
  approval_reference, manifest_hash, deployed_commit, actor_id, recorded_at
  UNIQUE(company_id,rollout_key,entry_no)
  -- origin/backout evidence only, never queried as a capability grant
  -- user and role/team references validated in company; permission is global vocabulary

ALTER access_scope_limits
  CHECK scope IN (MY_UNITS, OWN, OWN_OR_DELEGATED)
  -- same role-or-direct XOR, uniqueness, assignment provenance, default MY_UNITS
```

Ledger role FKs target roles(team_id,id); add a matching composite unique target if absent,
without changing Spatie team semantics. Permission IDs use the existing global vocabulary.
Projection and evidence commit atomically. Database guards reject creator/company edits, negative
or skipped generations, cross-family predecessor/grant references, ownership projection changes
without matching events and events without matching projections (deferred consistency checks).
Ownership/access-generation updates take the family lock. Grants pin the ownership generation;
adding/revoking a grant increments access_generation only, so it does not invalidate unrelated grants.
Database insert guards take the security/family fences and validate dates, actor/company references
and pinned generations. Reject overlapping non-revoked, epoch-valid same-level ranges (including scheduled future shares)
under that lock using tstzrange overlap;
revoked/epoch-invalid shares are not live blockers to replacement. Application duty/eligibility
checks remain necessary: a foreign key cannot prove authority. No generic row PATCH for evidence.

Evidence rejects UPDATE/DELETE/TRUNCATE. Retain user references when a member is suspended; hard
user deletion must refuse while referenced. No new money column or FinancialActivity probe needed.
Rollout ledger entries bind real origin without assuming Spatie pivots have row IDs: use their full
company/model/type/role-or-permission composite identity. A human actor and approval reference are
mandatory even when a deployment worker records the authorised migration.

## Exact proposed Actions and permission contract

All capability strings remain proposals until owner approval and the staged delivery; no current
registration changes. New sensitive duties enter the existing Sales catalogue/provider, excluded
from ordinary automatic grants, with separately reviewed mandate handling under R9.

| Action/port | Exact authority and result |
|---|---|
| Extend `CreateQuotationDraft` | Existing `sales.quotation.prepare`, active same-company actor; set native owner=actor and append initial ownership event atomically. Another owner additionally needs `sales.quotation.assign`; validate target and explicit unit, never assign capabilities. |
| `ReassignQuotationOwner` | Proposed `sales.quotation.assign` + independent view and their own non-delegated family scope; expected ownership/access generations, active target, reason/key; owner/unit change + event + epoch change, preserving creator/issued evidence. Company-wide recovery is an explicit holder scope, not an admin bypass. |
| `ChangeQuotationAccessUnit` | Same assignment/view authority; change unit without silently replacing owner; explicit null permitted only under approved own-only policy. Same event/fence protocol. |
| `GrantQuotationAccess` | Proposed `sales.quotation.access.delegate` + independent non-delegated view (and prepare for PREPARE); issuer and recipient checks in the design, bounded dates, reason/key, expected access_generation; append grant + increment access generation. |
| `RevokeQuotationAccess` | Proposed `sales.quotation.access.revoke` + independent view, both with current non-delegated family eligibility; named grant from same family, reason/key/expected access generation; append revocation. The issuer does not bypass this duty. |
| `ReadQuotationAccessHistory` | Existing specified `sales.quotation.history.view` + independent view; complete access-management evidence additionally requires an applicable assignment/delegation/revocation duty and non-delegated scope. Other readers see no other recipient roster, protected reasons or authorisation fingerprints. |
| Proposed `QuotationAccess::queryFor/requireRevision/requireFamily/requireNonDelegated` | Single SQL predicate/compiler plus matching scoped loader. Explicit actor, exact duty, operation and as-of instant. Owner/team/share paths; no capability creation. Scope tokens carry identity/epochs, not perpetual allow decisions. |
| Extend `ReadQuotationDraft`, `PreviewQuotation`, `UpdateQuotationDraft` | view / prepare / prepare respectively, via that loader before state/source/line reads or diagnostics; recheck inside transaction and before replay. Preserve safe metadata versus preparer projections. |
| Future `ListQuotationDrafts`, `SearchQuotationFamilies`, `ExportQuotations` | Use queryFor for the exact operation before count/order/page/stream. Export does not get a broader selector. Names specify future adapters, not new routes in #45. |

Source and field guards remain independent: a visible quote does not open an inaccessible current
Opportunity or protected Party/source facts. Omit the current CRM reference where its independent
read is absent; association mutations still validate old and new Opportunity eligibility under0099.
Do not broaden source lookups to make the quote projection work.

No separate manager-view, own-view, delegate-view, quotation-submit or catch-all manage permission.
No sharing of issue/withdraw/review/discount/cost/attachment duties. Each later operation must
register its resource adapter and independent field guards before activation, not assume prepare
covers it. Assignment/delegation rosters are not ordinary Party/member lookup data. Reuse the
id/name-only Sales selector pattern with exact quotation duty and company/active eligibility;
do not relax `SalesAccess::mayChoosePeople` to give existing CRM operators new access.

## Locking, security writers and replay

The existing QuotationOperation locks only its subject and does not coordinate authority writers.
Introduce shared `CompanyAccessFence` over company_access_generations, then take the Sales company
mutation fence when that R5 coordinator is delivered, then families ascending, revisions ascending,
source/policy rows in existing order, approval/numbering rows last. This is an explicit extension,
not a claim that a complete R5 fence already exists. Reads use shared security/family locks through
materialisation of the permitted DTO (one linearisation point); access mutations use exclusive locks.
Reload actor status, roles, direct grants and scope rows under that barrier; do not trust hydrated
User relationships, stale Spatie cache or an allow flag supplied by a job. Preserve custom Gate
refusals as well as proving current persisted capability/scope. Administration invalidates the
existing permission cache, while the generation barrier handles in-flight stale snapshots.
New mutations verify epochs after locking, authorise before any receipt/replay, then use the existing
QuotationOperation atomic receipt store and immutable result IDs. Never cache privileged result JSON.
A duplicate successful retry reprojects only under current access; stale new intent refuses 409.
Access-operation result envelopes reuse the receipt store: schema_version, family_id, applicable
event_id/grant_id/revocation_id and resulting generations, never an authorised response snapshot.
An authorised new key targeting an already revoked grant refuses QUOTATION_ACCESS_ALREADY_REVOKED
(409); the original key replays. An unchanged owner/unit emits no false ownership event. An
unrestricted recipient produces an explicit ALREADY_EFFECTIVE no-op, not a latent share.

Mandatory security-writer coverage, through shared fence helpers and database generation triggers:

| Writer/input | Invalidation and integration |
|---|---|
| `SyncMemberAccess`, `SaveRole`, `DeleteRole`, role/direct pivot writers, registration/bootstrap/mandate sync | Lock company security first; increment company generation and affected users' authorization_generation. For a role edit enumerate current affected members before deletion. Permission-cache invalidation stays with existing infrastructure. |
| `ChangeMemberStatus`, member company change/removal | Same fence and user epoch; actual current status checked for both principals at use, even for hydrated objects/jobs. Existing token/session revocation retained. |
| `SyncOrganisationUnitMembers` | Same fence and epochs for changed members; a manager's independent unit reach is freshly resolved. |
| `SaveOrganisationUnit`, `DeleteOrganisationUnit`, unit parent/status changes | Increment organisation_generation plus company generation; pinned grants become permanently ineffective, including if a tree later returns to its old shape. Reject cycles using existing unit invariants. |
| Owner/unit assignment and grant/revoke Actions | Security fence then family lock; monotone ownership/access generations as above. Ordinary line edits retain input_generation and do not revoke record access. |
| Raw DML / imports / seeders / console / code-deployment duty status changes | Register every writer; triggers resolve actual company through typed user/role-team references and advance the same counters. No unfenced runtime path, including generic unit-resource sync for a family. Deployment duty/authority-provider changes advance capability_generation and the security barrier. Missing coverage blocks activation. |

A company's generation is a read barrier, not a pinned share epoch: unrelated user edits must not
revoke everyone. Grants pin affected user epochs, organisation/capability generations and family ownership
generation; that avoids revival without needing a scheduled scanner or read-time writes. Organisation
changes conservatively invalidate all company shares; the proposed first implementation prioritises
correctness over fine-grained dependency tracking. Changed permissions on a global permission
vocabulary require fencing each affected company before delivery; reject unsupported global writers.
Triggers are the bypass-resistant freshness layer, not a replacement for governing Actions/audit.
Raw DML tests must prove both counter movement and refusal of invalid projections/evidence.

### SQL equivalence and bounded proof

The resource adapter compiles the same predicate for by-ID loaders and collection builders:

```text
company_id = actor.company
AND (scope.ALL
 OR (scope.OWN AND responsible_owner_id = actor.id)
 OR (scope.UNITS AND access_unit_id IN resolved_active_descendants)
 OR (scope.DELEGATED AND EXISTS effective_grant(family, actor, exact_operation, now)))
```

An effective-grant subquery joins issuer/recipient users, the family and company security counters;
checks active/current company, both user epochs, organisation/capability/ownership generations,
level compatibility, start <= now < end (nullable end), and NOT EXISTS revocation. Grant creation
has already proven the issuer's non-delegated exact-duty scope under these pinned generations;
unchanged epochs plus fresh status/manifest checks validate that proof at use without per-family
PHP lookups. Do not join untrusted cached authorisation JSON. A new dynamic/time-sensitive authority
provider must supply a bounded validity instant and an equivalent query/freshness adapter, or
sharing through that provider refuses. Its deployment changes capability_generation. Actor Gate
checks still run once per operation, in addition to this SQL record predicate.

Do not use SQL DISTINCT after pagination to hide duplicate grants: use EXISTS to avoid changing
visible page/count semantics. Reports with several required duties intersect their scoped family
sets before joining facts. Never add together scopes or authority ceilings from different duties.

## Future migrations and safe adoption

| Migration target (new timestamp selected during coding) | Required work / rollback |
|---|---|
| `add_quotation_ownership_and_evidence` | Nullable legacy owner/unit, baseline marker, generations, typed FKs/indexes, event table and immutable/deferred guards. New creation native owner required. No guessed historical reassignment. Populated evidence rollback refuses. |
| `extend_record_scope_for_quotation_families` | Explicit scope CHECK variants, supported-mode validation/registry, structured resolver; existing grants unchanged. Rollback refuses if new scope values remain referenced; never delete limits to make it succeed. |
| `create_quotation_access_grants_and_security_epochs` | Security counters, user epoch, grant/revocation tables, triggers, all registered writer integration. Fresh/upgrade/rollback-reapply and raw-DML fencing tests. Refuse destructive rollback after evidence. |
| `capture_quotation_access_baselines` | Separate rerunnable per-company approved mapping: owner/unit, source/approval reference and CAPTURED_BASELINE event at actual capture time. Keep unresolved families quarantined, not creator-as-historical-owner backfill. No live grants here. |
| `roll_out_reviewed_quotation_access` | Only separately authorised R9 slice: origin ledger, exact holder/scope mapping, approved mandate handling, registration/status activation and safe backout. Revoke only unchanged migration-introduced grants; retain history/necessary vocabulary. |

Use indexes for recipient/issuer/family live-share queries and owner/unit filtered lists; no N+1
per-family authority checks. Match the SQL selector to single-record decisions with property tests.
Require EXPLAIN on seeded scale (including hidden families and revoked/expired shares), bounded
pagination and query-count ceilings for list/count/report. Avoid indexing sensitive reason text.

## Related

- [Architecture and owner decisions](quotation-record-access-design.md)
- [Ordered slices and test matrix](../phases/phase-4-quotation-record-access-slices.md)
- [Domain integrity](../architecture/domain-integrity.md) · [Multi-tenancy](../architecture/multi-tenancy.md)
- [R9 rollout](../phases/phase-4-r9-permission-rollout.md) · [Quotation lifecycle](quotation-lifecycle.md)
