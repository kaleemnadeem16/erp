[← Phase 5](phase-5-job-execution.md)

# Phase 5 Batch 1 — authorisation package (Sales release + Jobs receiver)

2026-11-12 · Task #111 (docs only) · Plan [#108 revised plan](phase-5-revised-implementation-plan.md) · ADR 0186 · Integration #69.
**Status 2026-11-13: owner authorised Batch 1 start; the technical findings (§0b) are resolved and the disabled backend is implemented (commits below). Permissions stay SPECIFIED/STAGED; nothing is activated or granted.**
Baseline inspected: develop `528e40b1` (contains verified green-CI head `9220f01d`). Source inspection only; no runtime verification was run for this package.

## 0. Items that are NOT yet decided (blockers to the code start)

Everything marked **OPEN** below must be answered by the owner/coordinator; none is invented here.

| # | Open item | Why it blocks |
|---|---|---|
| O1 | Appoint Agent A (Sales) and Agent B (Jobs). At inspection both sessions were **offline**; #76 (A) and #77 (B/C) are still open, and #77's remaining work was split to #116 (QA4) and #115/#76 follow-ups. Their current assignments and uncommitted work must be confirmed live before appointment. | Plan: "appoint only after checking current assignments and shared-file ownership". |
| O2 | Appoint frontend owner (Batch 2 only), shared-security owner, Inventory owner, Purchasing owner, and **named independent reviewers** (Sales/security, Jobs/security, transaction). | Authors cannot approve their own milestone. |
| O3 | Pilot company and the fixed service used as the pilot source. | Decides which writers must be restricted. |
| O4 | Which Inventory/Purchasing writers can be fenced (see §6). If any writer cannot be fenced the pilot stays inactive. | Genuine no-material/no-subcontractor proof. |
| O5 | Whether the **existing quotation/acceptance text** is sufficient as the agreed completion basis (see §3.1). Needs a source read by Agent A at the start; if insufficient, a new Sales agreement record is required before release. | Shapes `service_completion_bases`. |
| O6 | Migration timestamps (reserved by coordinator after all prerequisites; latest on develop is `2026_12_12_200000`). | Order, §5. |

## 0b. Resolution of the technical findings and delivered commits

| Item | Outcome |
|---|---|
| O5 completion basis | Existing issued/acceptance evidence is **not** sufficient (no agreed conditions anywhere). New append-only Sales `service_completion_bases` record; issued-line text referenced verbatim. |
| Inventory/Purchasing writers | **Corrected (#122):** absence of Job foreign keys does NOT prove no obligation — Inventory accepts generic `source_type/source_id` and rejected-exit `document_type/document_id`, which are provenance only, never a Job allocation. Applicability is now proved by customer-agreed scope evidence + source-owner family authorities (`app/Support/JobSource`) + the FK catalogue; anything else is UNKNOWN and blocks release. See [job-source-safety](../domain/job-source-safety.md). |
| Strict no-growth | `PermissionRegistry::excludeStrictly` + `JobDutyManifest::registerWith`; mandates cannot carry Jobs duties. |
| Appointments (O1/O2) | Implemented by delegated sessions acting as Agent A (Sales), Agent B (Jobs) and the shared-security change; independent reviewers are **not yet appointed**. |

Commits: `0ddf5306`, `f3ea6069` (security), `cc4c8940` (Sales), `914c51bf` (Jobs), `4f47c581`, `e90ef6bc`, `b1cac392` (follow-ups), `9ab38ae7` (neutral source-family contract), `8ead9694` (scope-evidence applicability + agreement-bound completion basis), `0441ff9f` (receiver coverage gaps; fixes replay-after-revocation), plus the source owner's `67aa379f` (Inventory/Purchasing family authorities) and `dc983345` (#123).

**Status (2026-11-13):**

| Area | State |
|---|---|
| Backend code | Implemented, disabled (no route, grant or activation). |
| Source safety | Code and tests landed; **independent source/security review and Inventory/Purchasing owner sign-off pending.** Real releases need a customer-agreed EXCLUDED scope with dedicated written evidence. |
| Tests | Local combined regression 2810 passed / 1 skipped on the pre-push tree; exact-SHA CI on `0441ff9f` pending at the time of writing. |
| Independent reviews | **Not done** (Sales/security, transaction, shared-security, Inventory, Purchasing). |
| Batch 1 | **Not complete.** Pilot activation is not authorised. |
 Known limits: default release cost policy is "not required" (owner decision 3); no mid-COMMIT failure injection, release-versus-source-mutation race, or two-stage same-parent test yet; independent Sales/security and transaction review and Inventory/Purchasing sign-off still required before any activation.

## 1. Finite scope (what Batch 1 delivers, disabled)

In: Sales-owned completion basis and immutable release evidence; conditional cost readiness; Sales-origin source FKs on Jobs; baseline component + default activity; one numbered RELEASED Job per released stage in one atomic transaction; verifier binding for the Sales origin only; strict no-automatic-growth support for the Jobs duties (bounded shared change); fence/writer restrictions for the pilot lineage.

Out: any HTTP route, Request, Resource, OpenAPI or frontend (Batch 2); completion/closeout (Batch 2); cancellation/stop/withdrawal/reopen; labour, material, supplier, costing, multiple activities, PDFs, signatures; duty activation or holders (Batch 3); any other origin type.

Exit (from plan): a genuine issued → accepted → prepared → released source/Job transaction works internally, with rollback after every write, no duplicate on a fresh key, and no second acceptance claim consumed. Mock/fixture verifier success does not count.

## 2. File ownership (enumerated; no directory-wide authority)

Existing files inspected: Sales `Actions/PrepareHandoverPlan.php`, `Support/Handover/{HandoverAccess,HandoverInput,HandoverSourceRegistry,HandoverSubject,ServiceHandoverAssessment}.php`, models `Handover{Plan,Allocation,Stage,Baseline,ReadinessEvidence}`; Jobs `Actions/{AssignJobResponsibility,StartJob,PlaceJobHold,ResolveJobHold}.php`, `Support/{JobAccess,ReleasedServiceInput,ReleasedServiceValidator}.php`, `Support/Execution/{JobExecutionContext,JobExecutionContextVerifier,UnavailableJobExecutionContextVerifier}.php`, `Support/Lifecycle/*`, `Models/{ServiceJob,JobHandoverOrigin,JobBaseline,JobEvent,...}`, `JobsServiceProvider.php`.

**Agent A — Sales (new unless noted):**
- `Sales/Actions/ReleaseHandoverComponent.php` (new)
- `Sales/Support/Handover/Release/{SalesCompletionBasis,ServiceReleaseReadiness,VerifiedServiceRelease,HandoverTransactionContext,ReleaseCoordinator}.php` (new)
- `Sales/Support/Handover/Release/SalesJobExecutionContextVerifier.php` (new; implements the Jobs port)
- `Sales/Models/{ServiceCompletionBasis,HandoverReleaseEvent}.php` (new)
- `Sales/Support/Handover/ServiceHandoverAssessment.php` (**edit**: conditional cost requirement)
- `Sales/SalesServiceProvider.php` (**shared edit**, registration lines only)
- one additive Sales migration; tests under `tests/Feature/Sales/Handover/Release/`

**Agent B — Jobs:**
- `Jobs/Actions/ReceiveReleasedService.php` (new)
- `Jobs/Support/ReleasedServiceInput.php` and `ReleasedServiceValidator.php` (**edit**: real FK fields, drop "nothing accepts it")
- `Jobs/Models/{JobBaselineComponent,JobActivity}.php` (new); `JobHandoverOrigin.php` (**edit**: Sales lineage)
- `Jobs/Support/Release/{ReceivedJobResult,JobNumbering}.php` (new)
- `Jobs/JobsServiceProvider.php` (**shared edit**: replace Unavailable binding for Sales origin; registration lines only)
- one additive Jobs migration; tests under `tests/Feature/Jobs/Receiver/`

**Conflict rule:** the two `*ServiceProvider.php` files are single-owner; edits to the other side's module go through the other agent. Neither agent edits Inventory, Purchasing, Access/Administration, STATUS files, `phases/README.md`, or generated client/OpenAPI (no API in Batch 1).

**Shared security owner (OPEN, O2):** the bounded no-growth support in the access layer — exact files to be named by that owner before code (not enumerated here because I have not inspected the access module for this). **Inventory/Purchasing owners:** their fence files are listed in §6 once they confirm.

## 3. Interface signatures (proposed; freeze before code)

PHP, `declare(strict_types=1)`. Names are proposals.

```php
// Sales (A)
final class SalesCompletionBasis {
  public function resolve(int $companyId, int $acceptanceLineId): ResolvedCompletionBasis; // refuses on missing/ambiguous agreement
}
final readonly class ResolvedCompletionBasis {
  public function __construct(public int $issuedLineId, public string $agreedText, public int $conditionVersion,
    public string $provenance, public string $attestationPolicy /* 'AUTHORISED_ATTESTATION' default */) {}
}
interface ServiceReleaseReadiness {
  public function assess(HandoverSubject $s): ReleaseReadiness; // blockers, NOT_APPLICABLE-with-proof vs UNKNOWN, generations relied on, cost status separate
}
final class ReleaseHandoverComponent {
  public function __invoke(User $actor, int $stageId, string $requestKey, ReleaseIntent $intent): ReleaseResult;
}
final readonly class VerifiedServiceRelease { /* constructed only by ReleaseCoordinator inside its transaction */ }

// Jobs (B)
final class ReceiveReleasedService {
  public function __invoke(User $actor, VerifiedServiceRelease $release): ReceivedJobResult; // requires job.receive independently
}
// existing port, now bound for the Sales origin:
interface JobExecutionContextVerifier { public function enter(User $actor, ServiceJob $job, string $operation): JobExecutionContext; }
```
`VerifiedServiceRelease` must not be constructible by callers outside the coordinator (internal constructor + runtime check of connection/transaction level); a boolean or hash argument never proves authority.

### 3.1 Completion basis
Default basis = pinned authoritative agreed deliverable text + authorised whole-deliverable attestation (ADR 0186). Agent A's first deliverable is a short read-only finding on #69: is the exact agreed text in `quotation_issued_lines` / the acceptance evidence sufficient to reference verbatim? Yes → reference/copy with provenance. No → new Sales agreement record, authorised by a Sales duty, before release (O5).

## 4. Composite FKs (candidate; company-leading, same-parent)

Existing keys to reference (verified in the 2026_12_12_100000 and 2026_10_22_090000 migrations): `handover_stages (company_id,id)`, `handover_allocations (company_id,id)`, `handover_baselines (company_id,stage_id)` and `(company_id,id)`, `customer_acceptance_lines (company_id,id)`, `quotation_issued_lines (company_id,id)`, `service_jobs (company_id,id)`, `job_baselines (company_id,job_id,id)`.

| New table (owner) | Keys |
|---|---|
| `service_completion_bases` (A) | UNIQUE `(company_id,id)`; UNIQUE `(company_id,issued_line_id,version)`; FK `(company_id,issued_line_id)`→`quotation_issued_lines` |
| `handover_release_events` (A) | UNIQUE `(company_id,id)`; UNIQUE `(company_id,stage_id,purpose)` (business uniqueness); UNIQUE `(company_id,actor_id,request_key)` (intent identity); FK `(company_id,stage_id)`→`handover_stages`; FK `(company_id,baseline_id)`→`handover_baselines`; FK `(company_id,basis_id)`→`service_completion_bases`; same-parent check that baseline.stage = stage |
| Jobs Sales lineage (B) — extends `job_handover_origins` or adds `job_sales_origins` | UNIQUE `(company_id,job_id)`; FK `(company_id,release_event_id)`→`handover_release_events`; UNIQUE `(company_id,release_event_id)` so one release yields one Job |
| `job_baseline_components` (B) | FK `(company_id,job_id,baseline_id)`→`job_baselines (company_id,job_id,id)`; FK to `quotation_issued_lines` / allocation via the release event |
| `job_activities` (B) | FK `(company_id,job_id)`→`service_jobs`; exactly one default activity per Job (UNIQUE `(company_id,job_id)` while the basic mode applies) |

All new tables: INSERT-only guards (UPDATE/DELETE/TRUNCATE refused) following the preparation tables. Whether lineage extends the existing origin table or adds a table is Agent B's decision, recorded before code.

## 5. Migration order (timestamps OPEN, O6)

1. A: `service_completion_bases`, `handover_release_events` (after `2026_12_12_200000`).
2. B: Sales lineage, `job_baseline_components`, `job_activities`, receipt/result bindings — strictly after (1), because it FKs into it.
3. Shared security: only if it needs schema (not expected).
Each: empty apply → rollback → reapply, and populated refusal (per plan). No existing migration is edited; no preparation table is weakened.

## 6. Lock / fence inventory

**Verified now** (`HandoverAccess::lock`, `JobLifecycleOperation`): fresh actor decision → `AuthorityFence` (`sales_authority_heads`) → `customer_acceptances` → `customer_decision_cases` → `customer_scope_claim_heads` (ordered by id) → quotation revision requirement. Jobs: `verifier->enter()` (source fences) **before** `service_jobs` row lock. Numbering locks `numbering_counters` row (`LockedCounterStrategy`/`CounterStore`).

**Proposed release order (must be confirmed by real two-session tests, not assumed):**
actor/access → AuthorityFence → acceptance → case → claim heads → quotation revision → **Inventory/Purchasing source fences for the pilot lineage (OPEN: owners)** → stage/baseline → numbering counter → insert Job and children → release event + receipts. Release must not call back into any lock after the Job row exists.

**Not yet inventoried (OPEN, owners):** the Inventory/Purchasing writers that could attach an obligation to the lineage — linked issues, transfers/custody, reservations/demand, POs/services/hire, external expense/allocation, imports, workers, corrections — and which of them already take a fence. Until each is classified (fenced / cannot reference a Jobs lineage by construction, with proof / unfenceable), the pilot cannot be declared supportable. This section cannot be completed from Sales/Jobs source alone and I have not claimed it.

## 7. Source writer restrictions
Enforce at the owning module (not by an empty-query check, company setting, human declaration or SERVICE_ITEM discriminator): any write that would attach material, supplier, hire, labour or cost to the pilot lineage is refused by its owner under the shared fence; checked at release, start, completion and close. Same restriction applies to optional labour/cost writers. Exact refusal code per writer comes from the owners (O4).

## 8. Permissions required
- `job.receive` (Jobs, SPECIFIED, independent of release); the release duty and handover duties on the Sales side (existing `HANDOVER_PREPARE` plus a new release duty, to be named by A). All stay **disabled/SPECIFIED**; no holder grants in Batch 1.
- Request-time and Action-time authority checked independently; revoked/suspended actor refused before replay.
- Strict no-automatic-growth for Jobs duties: bounded shared change, delivered with its own independent reviewer (ordinary verified-mandate exclusions currently permit growth).

## 9. Independent review assignments (OPEN, O2)
Roles required, names to be appointed by the owner: (1) A↔B cross-review of the source/atomic contract; (2) one non-author security/transaction reviewer for the paired milestone; (3) a reviewer other than the author for the shared-security change; (4) Inventory and Purchasing owners certify §6/§7. Authors never approve their own work. Exact-SHA hosted CI green before any integration; the coordinator integrates only owner-approved commits.

## 10. Evidence required (summary of plan §"Engineering evidence")
Raw-SQL cross-company/same-parent attacks; immutability and TRUNCATE checks; migration cycle; two real PostgreSQL sessions for release/release, source-mutation/release and access-change races; failure injection after every write and at deferred COMMIT; proof of a single runtime connection and the agreed acyclic order; request/action/scope/read-profile ratchets; Pint, PHPStan, relevant regressions; no OpenAPI change.

## 11. Decisions requested from the owner
1. Approve this finite scope and the file lists in §2 (or amend).
2. Answer O1–O6, in particular the appointments.
3. Approve the proposed first step: Agent A's read-only completion-basis finding (§3.1) and the Inventory/Purchasing owners' writer inventory (§6) — neither writes code — before the final signature/lock freeze is attached to #69.

After that, the coordinator attaches the frozen lists to #69 and requests a second, explicit "start code" authorisation.
