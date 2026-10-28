[← Jobs holder and activation plan](phase-5-task-f-jobs-access-plan.md)

# Task F — smallest disabled access batch and review gates

2026-10-27 · #51 · PROPOSAL ONLY. No implementation authority, migration reservation, grant or
activation follows. Companion [catalogue/holder plan](phase-5-task-f-jobs-access-plan.md) governs
exact names, projections and per-company holder evidence. Existing Sales/shared policies unchanged.

## J-AU0 candidate — module-local, non-operative foundation

If separately authorised, implement only immutable catalogue/status/manifest and Jobs access
predicates, with tests. Shipped catalogue contains the 23 SPECIFIED names, zero STAGED/ACTIVE names
because no guarded consumer exists. Tests may construct synthetic states to prove denial mechanics;
they do not mark a real consumer delivered. No permission vocabulary constants for undelivered
consumers, no registry callback, no permission row and no empty Jobs owner metadata group.
No new migration/table/RecordScope policy or grant ledger is needed for J-AU0.

| Proposed owned path, repo relative | Bounded responsibility |
|---|---|
| backend/app/Modules/Jobs/Support/Authority/JobDutyStatus.php | SPECIFIED/STAGED/ACTIVE, explicit lifecycle semantics |
| backend/app/Modules/Jobs/Support/Authority/JobDuty.php | Exact validated name, status, operation, safe projection, company-wide scope, delivery gate, explicit-only grant policy |
| backend/app/Modules/Jobs/Support/Authority/JobDutyCatalogue.php | Exact 23 rows from companion; no aliases/wildcards or auto-grantable class |
| backend/app/Modules/Jobs/Support/Authority/JobDutyManifest.php | Unique lookup, shipped/all/withStatus/activeNames/isActive; unknown/non-ACTIVE fails closed; no active-registration implementation yet |
| backend/app/Modules/Jobs/Support/JobAccess.php | may/require/mayAll, independent per-duty RecordScope ALL, empty conjunction denies; no broad mayRead(any writer) |
| backend/app/Modules/Jobs/JobsServiceProvider.php | Bind manifest only; no PermissionRegistry hook, route or consumer |
| backend/tests/Unit/Modules/Jobs/JobDutyManifestTest.php | Catalogue/name/status/duplicate/pure-state tests |
| backend/tests/Feature/Modules/Jobs/JobAccessFoundationTest.php | Actual company/scope/hand-made-row/replay-boundary predicates and absence of registration |
| backend/app/Modules/Jobs/README.md | Disabled access boundary, not a claim of delivered Job Actions |

No shared file edit in J-AU0. No Models, database schema, SalesAccess, Sales manifest, RecordScope,
PermissionRegistry, FullAccessMandates or registration command change. Any expansion requires
separate assigned scope. No docs/STATUS.md, docs/STATUS-LOG.md, docs/phases/README.md or
docs/phases/phase-5-job-execution.md edit in task branches. Integrator records status/navigation.

## Separate prerequisite before ACTIVE registration

A shared access owner must review/implement strict automatic exclusion for Jobs, because current
ordinary exclusions still permit verified-mandate growth. Proposed affected shared paths for that
SEPARATE task are backend/app/Support/PermissionRegistry.php and its focused registration/grant
tests, with consumer regression in backend/app/Console/Commands/CreateAdminUser.php,
SyncAdminPermissions.php and Demo/PackagedPurchasingDemoSeeder.php, PurchasingDemoSeeder.php,
StockDemoSeeder.php under backend/database/seeders. These consumers already call the registry;
prefer fixing the shared predicate with their tests rather than spreading Jobs-specific checks.
Do not change those files in J-AU0 or silently alter FullAccessMandates/Sales behaviour.

The reviewed shared design must distinguish ordinary exclusions (accepted mandate semantics) from
strict Jobs exclusions, preserve explicit held permissions during sync, and cover both direct user
and role-wide all-vocabulary consumers. Existing Sales/attribution grant tests must retain their
accepted outcomes. If strict support or its ownership is unresolved, Jobs remains unregistered/
non-ACTIVE; no registration workaround. Exact shared API and affected consumer inventory are
reviewed at implementation, not claimed delivered by a proposed catalogue property.

Later J-AU1 registration/holder rollout is a different owner-authorised slice alongside actual
guarded consumers. It adds only supported slice constants/provider registration, provenance-tracked
explicit per-company assignments and safe backout where required. It needs a fresh migration stamp
after latest develop/pending reservations if storage/migration is needed; this plan reserves none.
Registration and assignment are distinct; no demo/production seed or default-role widening here.

## Required future verification — not executed tests

| ID | Required scenario and result |
|---|---|
| JF01 | Exact names match catalogue/guards; duplicate/unknown/foreign/wildcard refuses; job.cancel_unused preserved, no alias |
| JF02 | All shipped SPECIFIED and synthetic STAGED duties refuse, even hand-made permission/ALL grant/verified mandate; zero Jobs registry rows/owner metadata |
| JF03 | Synthetic ACTIVE predicate requires permission and ALL scope of THAT duty; empty mayAll/null user denies |
| JF04 | Restricted start + unrestricted view/receive/other duty → start denies; combined assign/start requires both independent ALL scopes |
| JF05 | Same-duty explicit ALL role/direct grant has existing additive meaning; limited grant alone fails; no hidden deny semantics or widening scope migration |
| JF06 | Foreign company/changed team/current membership or revoked actor → owning operation denies; safe absence/error, no cached scope reuse |
| JF07 | Assignment/admin/owner/CRM/Sales releaser implies no Jobs authority; exact receive also needs independent Sales coordinator eligibility |
| JF08 | Close-only actor gets reviewed safe operation context with no job.view/job.cost.view; view/complete/close do not expose rates/costs/private evidence/files |
| JF09 | Record/recognise/declaration/rate/correction/export remain independent; J1 works without optional J2 rights/records |
| JF10 | Real consumers later guard Request+Action/worker/import and current-authorised replay; historical/context/old-new-source rights checked independently |
| JF11 | Later hypothetical ACTIVE Jobs registration creates names only, repeated sync adds no grants for ordinary OR verified-mandate targets; explicit legitimate held Jobs rights survive sync |
| JF12 | Existing Sales/CRM/mandate/role/direct grants and scope limits unchanged by strict Jobs exclusion; no automatic role membership expansion |
| JF13 | Later holder rollout is per-company/provenance/idempotent; backout disables entries then removes only owned unchanged assignments, never evidence/independent grants |
| JF14 | List/count/selectors/history/receipt/errors/cache/export/download apply current safe field/scope profiles; no hidden total/hash/cross-company leak |

JF01–06 and disabled absence assertions fit J-AU0. JF07–14 require later real consumers/shared
rollout; catalogue/predicate tests alone cannot prove those paths. For authorised PHP changes run
Jobs unit/module regression, Pint, whole-project PHPStan and applicable permission/read/write/
RecordScope/Action-boundary ratchets. Shared growth changes require focused shared registration,
SalesDutyRegistrationTest and AttributionGrantRolloutTest regression. No full local suite by feature
agent; CI/coordinator covers it. Later migrations/UI/API need their matching checks. Planning now
runs docs links/whitespace/ownership only, no application or live-holder audit.

## Approval checklist and owners

1. Jobs implementation reviewer: exact vocabulary, safe operation reads versus general views,
   SPECIFIED/STAGED meanings, source/correction boundaries and minimal J-AU0 files.
2. Shared security/RecordScope owner: per-duty ALL behaviour, strict no-growth contract and
   consumer inventory; no copied Sales mandate exception or unreviewed new authority engine.
3. Sales/R7 reviewer: independent release/receive authority and actual source prerequisites.
   Inventory/Purchasing review affected correction/readiness profiles before their activation.
4. Deployment coordinator + human owner: actual company/principal/scope/projection recipe,
   explicit grant provenance/backout, staging evidence and exact-commit activation authority.

First requested owner decision is whether to authorise J-AU0 after review. It activates nothing.
Actual duties stay non-ACTIVE until their own consumer, source, strict-growth, holder and staging
gates pass. J1 and J2 are independently reviewed; no full-access wildcard resolves missing evidence.

## Related

- [Exact catalogue/holder/activation plan](phase-5-task-f-jobs-access-plan.md)
- [R9 rollout](phase-4-r9-permission-rollout.md) · [Pre-push checks](../architecture/pre-push-checks.md)
- [Authorisation](../architecture/authorization.md) · [Task D batch](phase-5-task-d-j1-f1-proposal.md)
