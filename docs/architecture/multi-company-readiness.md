[← Architecture](README.md)

# Multi-company readiness gate

**Status: gate defined; not passed. Nothing here enables multi-company operation.** Legend: **[V]** verified in code on 2026-11-12 (develop at `0d046f09`); **[I]** inferred or not yet proven.

## 1. Current state

The system is **single-company operational with multi-company-oriented schema foundations**. It is **not operationally multi-company** and must not be described as such. The foundations are:
`company_id` columns, company-scoped composite unique and foreign keys, `CompanyAccessFence`, company-keyed cache and storage helpers (defined, see below) and record scopes
(`BelongsToCompany`, `BelongsToCompanyThroughParent`). See [multi-tenancy.md](multi-tenancy.md) and [ADR 0004](../decisions/0004-multi-tenancy-ready-schema.md).

## 2. Readiness gate checklist

Each area lists the current state and what must be **proven** before enabling. "Proven" means automated tests or recorded independent review, run with a second real company in the database.

| Area | Current state | Must be proven before enabling |
|---|---|---|
| Company-context resolution | [V] `CompanyContext::id()` returns the constant `1`; 54 migrations default `company_id` to 1 (60 reference `default(1)` alongside `company_id`); 234 literal `company_id => 1`-style assignments across app, seeders and tests | A resolver that derives the company from the authenticated principal; no code path, default, seeder or fixture silently assumes company 1; defaults removed or proven harmless |
| Member-to-company access and switching | [V] One login belongs to one company (`users.company_id`; [ADR 0062](../decisions/0062-administer-company-members-through-invitations-and-additive-roles.md) v1 excludes multi-company membership). No company CRUD, membership model or switcher | A membership model, switching flow and its authority rules; access administration (roles, scope limits, suspension) acting only within the target company |
| Authentication and session | [V] Sanctum tokens; `EnsureUserIsActive`; no company claim in the session or token. [I] token/session isolation across companies | Tokens and sessions bound to one company context; suspension and revocation per company; no cross-company token reuse |
| Permissions and roles per company | [V] Spatie teams enabled (`config/permission.php`); `SetPermissionsTeam` sets the team id from `CompanyContext::id()` (so always 1); roles and role names are company-scoped ([ADR 0062](../decisions/0062-administer-company-members-through-invitations-and-additive-roles.md)) | Team id follows the resolved company on every entry point (HTTP, console, jobs); permission cache cannot leak between companies; role and direct-grant isolation tests with two companies |
| Background jobs, queues, scheduler, commands | [V] Default queue is `database`; no application job class and no `ShouldQueue` use found in `app/`; the only scheduled task is `sanctum:prune-expired` (global); four commands (`erp:create-admin`, `erp:sync-admin-permissions`, the permission-registration command and `GrantQuotationDevPreview`) read `CompanyContext` and therefore act on company 1; `erp:validate-integrity-constraints`, the audit and currency-sync commands are not company-aware [I] | Every command and any future job declares its company explicitly and restores context (rule 5 of multi-tenancy.md); a test that a job without context fails closed |
| Caches | [V] `CompanyContext::cacheKey()` yields `company:{id}:{key}` but **no application code calls it** (only its unit test); no `Cache::` use found in `app/`; the framework cache prefix is `APP_NAME`-based, not company-based. Spatie's permission cache is process-wide | Any cache in use is keyed by company; permission cache proven per team; a test with two companies for each cache |
| Files, storage and Documents | [V] `CompanyContext::storagePath()` exists but is **not used**; the Files module stores objects under a random key (`substr(key,0,2)/key`), with company ownership held in database rows, not in the path. [I] row-level company checks on read, download and generation | Every file and Document read, download, generation and recovery checks company ownership of the blob and the owner record; backup and restore by company; no path or key guessable across companies |
| Reports and exports | [I] Reads go through scoped models; 9 `withoutGlobalScope` uses in `app/` need review. No cross-company reporting exists by design | Each report and export proven to filter by company, including raw SQL and joins; the `withoutGlobalScope` uses justified |
| Per-company configuration and financial policies | [V] Company profile, timezone and settings are on `companies` and company-scoped tables; numbering `Sequence` is one row per company and document type; approval policies and discount authority are company-scoped. [I] FX tolerance (0.5%) and rate age (7 days) are fixed in code ([0165](../decisions/0165-reconcile-purchase-order-fx-with-the-governed-rate.md), interim; [0185](../decisions/0185-configure-company-business-values-and-fix-technical-invariants.md)); exchange-rate and currency tables may be global | Each setting, numbering sequence, FX source, approval policy and financial constant resolved per company or explicitly global; no global business constant; per-company evidence kept with transactions |
| Demo seeders and test fixtures | [V] Demo seeders and many tests hard-code or assume company 1; tests do cover foreign-company refusal in about 88 files, but all run with `id()` fixed at 1 so they prove scoping of reads and writes, not context resolution | Seeders and fixtures take an explicit company; a test suite variant that resolves a second company through the real resolver |
| Database-level isolation | [V] Company-scoped composite unique and foreign keys (89 migrations declare composite unique keys with `company_id`; `2026_08_21_090000_enforce_company_scoped_foreign_keys`); 98 migrations define triggers or functions; no row-level security found. [I] coverage of every business table by a composite FK | A catalogue check that every independently queried table carries `company_id` with composite FKs; trigger and guard behaviour with two companies; a decision on RLS (present: none) |
| Observability and audit evidence | [V] `activity_log.company_id` is stamped from `CompanyContext`; there is no read-side scope on `Activity` ([multi-tenancy.md](multi-tenancy.md)). Request logging is not company-tagged [I] | Audit and activity reads scoped per company; logs and metrics attributable to a company without leaking another's data |
| Onboarding and offboarding | [V] Not built (tenant provisioning is listed under "deliberately not built"). Server-side admin creation targets company 1 | Company creation, first administrator, defaults, numbering and policy seeding, suspension, export and deletion or retention, each with tests and audit |

## 3. Gate status

This gate is a **HARD blocker to enabling multi-company operation**: no second company may be created or served in any environment until every row is proven and independently reviewed. It is **not a Phase 4 development-closure blocker** (owner decision, [ADR 0188](../decisions/0188-record-phase-4-closure-owner-decisions-second-response-2026-11-12.md)). Counts above are point-in-time and must be recomputed when the gate is run.

## Related

- [multi-tenancy.md](multi-tenancy.md), [authorization.md](authorization.md), [invariants.md](invariants.md)
