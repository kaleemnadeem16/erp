[← Phase 5 planning](phase-5-job-execution.md)

# Task C — optional J2 labour evidence, valuation and corrections

2026-10-26 · #44 · PROPOSED technical design only. No code, migrations, routes, grants or
activation. J2 is independently optional and cannot become a prerequisite for basic J1 execution.
ADRs 0146/0147/0149–0151 and accepted Phase 4 contracts remain unchanged.

## Evidence, authority and supported first boundary

Fresh branch from published develop `d4746d7d`, after D (#42) and E (#43) submission in queue order.
Owner directed sequential planning; #34 remains under review and untouched. Planning ownership
exception to helper dependency refusal is recorded on #44. No reviewed design is runtime proof.
Reuse [0146 labour](../domain/job-labour-recognition.md), [0147 coverage](../domain/job-cost-coverage.md),
[0150 reporting](../domain/job-unified-costing.md), [0151 closeout](../domain/job-closeout-reconciliation.md),
[labour research](phase-5-labour-evidence.md) and [costing resolution](phase-5-unified-costing-resolution.md).
Historical research wording is not current status; governing accepted domain contracts prevail.
Task B input is pinned to
[981ce196](https://github.com/kaleemnadeem16/erp/blob/981ce19608ef83fd9381be35db360fc4866e35e0/docs/phases/phase-5-task-b-j1-execution.md).
Existing research suffices; no payroll/accounting policy is introduced or re-researched here.

First J2 proposal: company-base direct incurred INTERNAL labour amount with genuine occurrence
date/period/basis, accountable source/declaration and supported Job scope. No fake worker, rate,
quantity=1, timesheet, attendance, eight-hour day or HR directory. Fixed-unit/day/hour methods are
separate later adapters; method-specific required evidence cannot be bypassed by choosing a lighter
entry form. External labour/subcontract/hire uses owning0149 Purchasing/external integration, not
the internal direct form. MATERIAL uses Inventory consumption/0040, never this form.

J1 still follows genuine release→assignment/start→fixed-deliverable attestation→safe closeout without
any J2 record, rate, amount, cost report or coverage declaration. Optional absence is not zero,
complete coverage or a fabricated pending timesheet. Known ordinary reports/disputed performance/
required effects remain source-owned operational blockers. Recording cost never grants start,
commercial fulfilment, recognition, closure or financial settlement.

## Candidate owning records — logical design, no schema files

One logical evidence/recognition/attribution foundation, extended by typed source owners. Do not
create a separate competing ledger for direct labour, external declarations and supplier services.
Physical extraction/reuse needs later proof; CRM receipts/Inventory valuation tables are not generic
Job storage. Every eventual record needs company composite keys, same-Job/source revision FKs,
append-only guards and stable business identities. Real activity/source FKs wait for actual tables;
do not invent string IDs or a shadow activity to satisfy an interface.

| Record family | Retained evidence / integrity |
|---|---|
| Source/component and immutable revisions | Stable economic component identity; method/category, company, Job or supported activity, actual original source/declaration, incurred scope/date/period and precision, recorder/time, reason and predecessor |
| Method-specific evidence | Direct internal: actual incurred base amount/currency/FX1, basis/authority. Later usage: supported exact quantity/unit or whole-second duration, class/worker only if captured/required. No mutually fabricated alternatives |
| Recognition facts | Exact source revision/fingerprint, usage versus value recognition, applicable policy/approval identity/version, actor/time and owning receipt |
| Economic allocation portions | Source component/revision/recognition identity, supported Job/activity/category, compatible amount/quantity and company remainder; conserved once |
| Correction/reconciliation | Original/effective revision and allocated portions, reason/authority, actual old/new scopes, signed economic adjustment evidence and owning receipt; no overwritten source or implicit repost |
| Coverage assessment/invalidation | Independent scope/cutoff/source vector, declared/confirmed basis, actor/time, predecessor and invalidation cause; never stored as a source's valuation status |

Initial direct money is decimal(19,4), transaction/base currency identical and pinned FX1. Store
both amounts/currency/FX basis under existing invariants. No binary floats. Missing monetary evidence
is not a money row with null base amount or invented conversion. Unsupported foreign observations
remain unposted; later FX adapter must supply date/source/direction/exact rounding and historical basis.
Any future monetary storage registers FinancialActivity in the same authorised batch to preserve
Company base-currency lock; this planning task registers nothing.

Later adopted class rates copy rate6/unit/currency/effective basis/authority before use; live master
edits/deletion never alter actuals. Existing ResourceRate latest<=date lookup supplies no Job expiry
policy. No quotation/wage/zero fallback. Missing/unsupported rate can leave recognised usage unpriced,
not discard quantity. Work occurrence date/period, not recording time, governs supported selection;
ambiguous periods need supported review, not fabricated timestamps. Worker-day/crew-day/person-hour
differ. Later duration adapters use exact whole seconds/hour arithmetic, not stock4 truncation or
automatic 8/24-hour conversions. No global UoM change is authorised.

## Recognition protocol and current authority

Candidate owning Actions: RecordLabourEvidence, RecogniseLabourEvidence,
RecordAndRecogniseLabourEvidence, CorrectLabourEvidence and ReconcileLabourSource. Names are proposals,
not files or permission literals. Each Request AND Action checks current company, operation-specific
eligibility and ALL RecordScope; workers/imports pass explicit actors. Recording own/on-behalf/
aggregate evidence, direct declaration, recognition, review, rate adoption, correction, coverage and
cost read/export are distinct. Assignment/admin or Sales handover duty supplies none automatically.
All proposed capabilities remain STAGED; actual vocabulary and individual holder rollout are GATED.

1. Validate supported method and truthful source/context, lifecycle including holds/termination,
   current authority and canonical intent. Capture during hold is truth only, not recognition.
2. Apply configured NONE/POLICY/REQUIRED approval to exact revision/generations. No universal
   second approver. Editing/revising relied-on evidence invalidates review; a form/method switch
   cannot bypass policy. Unknown historical execution context requires governed classification.
3. Record-only actor creates pending evidence. Combined record+recognise requires both eligibilities
   and actual policy proof; failure commits neither combined effect. No second manual posting
   after eligible successful recognition. Pending exposure is not recognised actual.
4. Recognise usage/performance independently from monetary valuation where supported. Direct amount
   needs no fake usage/rate. Commit recognition, effective allocation, source/coverage generations,
   invalidation and receipt in one transaction. No recognised source with missing mandatory allocation.
5. Return current-authorised safe result; capture authority reveals authorised input only, not
   other worker rates, Job margin or whole-Job cost. Replays recheck current access, including exports.

Request uniqueness is company/actor/key; source/component/revision/recognition and allocation
identities independently prevent fresh-key/actor duplicates. Same keyed intent returns original
safe references; changed intent conflicts. A client UUID or equal amount/date is not economic
identity. Current source/approval/generation checks for NEW work occur under source-compatible locks.
Existing exact authorised replay resolves original effect before obsolete mutable prerequisites.

All required effects share the outer transaction and actual runtime connection/savepoints. Proposed
order enters required owning source fences before Job, then source revision/allocation/coverage
anchors in stable IDs, jointly compatible with Sales/Inventory/Purchasing writers. Exact physical
locks remain GATED until those writers exist; no trusted callback or invented global service proves
them. Same-component recognition/correction/coverage/close/import races need independent-session proof.
Unique/deadlock recovery rolls back the whole operation and repeats bounded authority checks.

## Corrections, overlap and historical context

Append a successor revision/correction, retain original and explicit effective linkage. Proposed
amount correction representation: replacement stores its full non-negative supported value;
separate typed signed adjustment evidence records new effective minus old effective, with decimal4
scale and exact conservation. Projection chooses effective replacement once, never sums original+
replacement+delta. Do not encode a negative delta through non-negative Sales FinancialResult.
Detailed representation/constraints require implementation review before monetary storage.

Quantity correction revalidates quantity/value effects. Direct amount/rate/FX correction creates
no hours or physical consumption. Worker/category/Job/activity/date changes require old AND new
scope authority, truthful historical context and atomic dependent allocation/coverage changes.
If any mandatory participant is unknown, preserve report/discrepancy and refuse applied posting;
do not publish a partial correction or silently edit history. Closed/held/terminated corrections
use controlled historical path, never ordinary restart or new work disguised as late entry.

Later payroll/invoice/Purchasing source must declare economic component/covered portions as
confirmation, replacement or evidenced difference. Equal dates/amounts do not match identity;
unrelated equal expenses remain valid. Unknown overlap blocks automatic second attribution,
not truthful evidence capture. Partial reconciliation preserves unmatched remainder. Example:
recognised 12000.0000 with invoice covering 5000.0000 does not add 5000.0000 or erase 7000.0000;
an evidenced full replacement of 13000.0000 yields linked 1000.0000 adjustment once.
Future Finance owns payroll/AP/period/tax/accounting treatment; no such adapter is delivered here.

## Dependencies and separately authorisable slices

| Slice | Candidate scope and delivery gate |
|---|---|
| J2 preparation | Review exact physical source/allocation/correction contracts, method precision, monetary probe and authority; disabled shapes only if separately authorised |
| Internal direct amount | Real supported Job/receiver/lifecycle and source context, guarded record/recognise/approval/receipt, one effective allocation, correction and coverage invalidation together |
| Read projection | Actual source vector, independent access and reporting completeness; cannot activate from seeded fake costs |
| Later usage methods | Rate adoption, worker/team policy where needed, exact duration/unit precision and correction/recognition proof before each method |
| Inventory/external | GATED0040 Job-consumed participant,0149 acceptance/portion/direct-external adapter and writer inventory; no internal-labour workaround |
| Finance reconciliation | GATED future source/component overlap, period/FX/tax rules and correction path before authoritative arrival |

Every code batch needs separate owner authority, accountable module owner and reviewed migration
reservation after current/pending migrations. No implementation files/timestamps reserved by Task C.
J1 implementation can proceed independently of all J2 slices once its own gates are met.

## Related

- [J2 coverage/reporting and acceptance matrix](phase-5-task-c-j2-reporting.md)
- [Planning overview](phase-5-job-execution.md) · [Labour](../domain/job-labour-recognition.md)
- [External costs](../domain/job-external-cost-attribution.md) · [Domain integrity](../architecture/domain-integrity.md)
