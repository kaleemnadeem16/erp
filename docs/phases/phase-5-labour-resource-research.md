[← Phase index](README.md)

# Phase 5 — Flexible labour recording and recognition: revised L1–L6

**Historical research basis,2026-10-15; revised L1–L6 business direction ACCEPTED0146 on2026-10-16.**
Origin: PLANNING RESEARCH. Q51 is closed by owner approval; body below preserves reviewed proposals,
not current delivery status. Use the [bounded labour contract](../domain/job-labour-recognition.md).
The exact [coverage-declaration protocol](phase-5-cost-coverage-review.md) remains PROPOSED/Q52.
No implementation is authorised.
ADR0145 lifecycle remains ACCEPTED; bounded lifecycle SPECIFIED; labour methods not IMPLEMENTED
or VERIFIED. This revision replaces the earlier proposal that ordinary actuals require named
workers/hours and that crew actuals must universally wait. Those were proposals, not authority.

## Business direction and limits

A small contractor may know only completion and PKR15000.0000 labour incurred. An installer
may know six worker-days, a service business six person-hours, and a larger company each worker's
timesheets. None should fabricate the others' evidence. One Job and costing framework accepts
different supported evidence; method/policy determines required fields, not a SIMPLE schema.

Recommended first candidate: authorised handover, responsible person or supported team, start,
progress/fulfilment evidence, optional direct amount, known-cost/coverage view and operational
closeout. No optional timesheet/rate/worker blocks completion. Keep0145 explicit start: no fake
backdated start from a cost entry. Historical reports lacking start/context use governed review.

## Methods, minimum evidence and proposed delivery order

Common to monetary/usage records: company/Job/activity or supported Job-wide scope, occurrence
date/period at recorded granularity, recorder, evidence reference or reasoned internal declaration,
source/economic-component identity, recognition authority/review and immutable revision lineage.
Physical files are not universally mandatory; secure attachments depend on0137 delivery.

| Method | Additional minimum evidence | What it proves / does not prove | Recommendation |
|---|---|---|---|
| Completion only | Supported outcome/checklist, confirmer and actual completion context | Fulfilled work; no labour quantity/cost or zero-cost claim | SIMPLE first |
| Direct incurred labour amount | Amount/currency, incurred basis/date/period, source declaration/reference, supported classification, duplication coverage and authority | Attributable incurred operational expense; no invented worker/qty/rate/payment | SIMPLE first, narrow company-base path |
| Worker-days / supported fixed units | Quantity, precise unit/basis, class or work descriptor, date/period; aggregate team basis if unnamed | Usage at that granularity; valuation optional, no hours/attendance inference | Next SIMPLE increment, not dependent on named workers |
| Hourly usage | Duration/basis/date, class or supported work descriptor; named identity only where required | Person-hours or explicit aggregate basis; rate optional | Subsequent SIMPLE increment; named detail/MANAGED review as needed |
| Fixed-price external labour | Contractor/Party, agreement scope/value, commitment identity; separate incurred-work/valuation evidence | Commitment first, not automatically actual or payment | Purchasing/subcontract integration later; not standalone Job expense shortcut |

Quantity-based capture need not be MANAGED merely because a company uses days/hours; review
and detail are independent. First useful release need not implement all methods. A bounded
fixed-unit path follows direct amounts; full detailed crew rosters/rate mixes, person-specific
rates, scheduling/attendance, payroll, piecework output-quality rules and external matching are
later capabilities. Piecework may share the quantity shape but cannot ship on a label alone.
No five company mode switches. Offer supported entry choices progressively; enforce only genuine
contract/policy requirements. Existing mandated records cannot be waived by choosing a lighter UI.

## What shares one underlying costing contract

Use one logical source-recognition/allocation/correction boundary, with method-specific evidence:
usage quantity/UoM is present only for usage; adopted rate only for rate-based valuation; amount
only when actually evidenced. Amount-only entries must not invent qty1 or rate=amount. Completion
alone creates no labour cost source. This is a logical proposal, not a universal table/schema.

Recorded evidence → applicable review → recognised usage and/or monetary contribution. All
contributions retain source/covered-work scope, cost kind/basis, currency, certainty and lineage.
An entry may carry usage and a directly evidenced amount together; that is one source, not two
additive expenses. A derived quotient is descriptive, not a retrospectively adopted rate.

Different owning sources feed the same cost projection. Job owns bounded internal incurred-cost
declarations; Purchasing owns commitments/service procurement; Finance owns supplier invoices,
payroll/payments/GL. External work may be SUBCONTRACT rather than LABOUR under the source contract;
do not reclassify every contractor invoice as internal labour. Their adapters share attribution,
not legal recognition lifecycles. Supplier/payroll interfaces remain implementation-dependent.

## L1 — Conditional worker identity and accountability

**Problem/example:** a team expense or six worker-days may be credible without individual names.
**Options:** universal Employee/User (easy join, fabricates/excludes evidence); anonymous-only
(easy capture, loses detail); method-specific identity in one model (slightly more validation).

**Recommend:** third. Separate worker, class, recorder, reviewer, approver and responsible party.
Named worker identity is company-scoped/stable, optional login and reuse of existing Party/person
identity where appropriate, no mandatory HR/payroll or duplicate general address book. Employees,
contractors/other eligible workers can be identified without implying a supplier or employment law
classification. Deactivation preserves historical attribution and controlled late evidence.

Require a worker only when method, contract or policy requires it. Direct amounts need no invented
worker/Item. An accountable named recorder is still required. Team-level entry can use an evidenced
team label/responsible person without a new crew-management module. Three people × two days is six
worker-days, not six named attendances. A crew-day (one crew for one day) is a different measure.
Where a method permits aggregate person-hours, label it aggregate; never claim individual overlap
checks. Crew planning, simple aggregate usage and detailed crew allocation are separate capabilities.
**Trade-off/defer:** conditional validation supports both scales; defer detailed crew costing but
not all small-business records. Deferring worker design blocks named tracking only, not completion.

## L2 — Recognition appropriate to evidence

**Problem/example:** recognise an incurred15000.0000 declaration without a manufactured timesheet.
**Options:** trust all records immediately; require independent approval/manual posting for all;
or distinct record/recognise authority with review only where applicable.

**Recommend:** third. SIMPLE user holding both authorities can record-and-recognise in one command;
neither permission implies the other. If only recording authority exists, persist a reported entry
pending eligible recognition. MANAGED reuses NONE/POLICY/REQUIRED Approvals for exact method/source
version; no universal maker/checker. A configured required review cannot be avoided by method switch.

Usage recognition validates actual quantity/basis/context even when valuation is absent. Amount
recognition validates incurred basis and economic scope, not hours. A recorded amount can be
monetarily known but pending recognition; these are separate axes. Show pending/provisional versus
recognised views without adding them. No unnecessary second manual posting after eligible review;
recognition and applicable attribution commit together once. Exceptional/hold/closed reports stay
under0145; capture is never automatic approval, cost recognition or fulfilment.
**Trade-off/defer:** simpler authorised workflow with explicit duty checks; review delays remain
visible. Deferral blocks the affected recognition path, not unrelated completion-only execution.

## L3 — Rates are one valuation method, not a universal prerequisite

**Problem/example:** six worker-days may be valued at a daily class rate, or a credible direct
expense may already state total cost. Neither requires conversion to hours.
**Options:** force hourly rates; duplicate an operational catalogue; reuse dated class-rate facts
through adoption where suitable, plus directly evidenced amounts.

**Recommend:** third. ResourceRate already supports a rate_uom_id (not only hour), rate6/base currency
and effective date. Candidate resolution is not actual authority. Adopt/copy immutable rate, unit,
currency, validity/date, source, costing basis and authority. Use occurrence date/period, not entry
date; split only where actual dated evidence supports it. Missing/conflicting/expired/unsupported
adoption leaves usage valid but valuation incomplete. Do not invent validity expiry for estimating
rows that have only effective_from; operational validity must be explicit. No silent fallback to
quotation price, wages, old unrelated rate or zero. Class first; person-specific rules later.

Direct amount requires no rate adoption. A fixed contract price is committed, not incurred; a
validated incurred portion requires appropriate service/economic evidence and owning integration.
Payment/advance/receipt alone is not proof of incurred cost. Explicit attestation can support an
internal direct-cost declaration, labelled as such, not a fake voucher/invoice/payroll-final fact.

Initial monetary path: company-base currency, with pinned currency and FX1/base amount. Foreign
source amounts should be retained pending supported valuation, not silently relabelled base.
Later support needs explicit FX date/basis, source/rate/direction, transaction/base amounts and
rounding evidence. Reuse existing Currency/manual-rate facts/arithmetic; Sales FR1 is quote-specific,
not an automatically reusable Job policy. Exact-date FX is proposed default; missing rate leaves
base valuation incomplete, not zero. Any bounded prior-date fallback needs governed policy approval.
Historical valid amounts stay pinned; later catalogue/FX movement is not recosting authority.

Worker-day/workday is an agreed operational unit, not automatically a TIME-day or eight hours.
Same dimension alone is insufficient proof of equivalent labour basis. Use existing UoM identity
only with declared compatible semantics; no global conversion/master change. Reject unsupported
conversion, precision/overflow or ambiguous basis. Hours/minutes may use proposed whole seconds
and exact rational costing; preserve entered units. Fixed units retain their actual precision,
not fabricated seconds. Rate6/money4/FX9 follow0141–0144, with explicit rounding stages/strings.
**Trade-off/defer:** reuse avoids duplicate catalogues; unsupported conversions/FX remain incomplete.
Base-only first is limited but useful; foreign/mixed currencies can follow without history loss.

## L4 — Independent usage, progress, fulfilment and coverage

**Problem/example:** completion with no optional labour records can be valid while cost is unknown.
**Options:** require timesheets for every Job; treat absent cost as zero; or independent evidence
coverage and source certainty. **Recommend:** third, consistent with0145's required-known-record rule.

No optional hours/rates/worker record blocks completion. Require supported outcome/checklist and
authorised confirmation, plus legitimate execution/start context. A responsible person or supported
team can be assigned; no full crew system inferred. Money/days/hours/percent do not prove commercial
fulfilment. Do not derive runtime from cost or completion, or calculate attendance from aggregate use.

Track separately: evidence method/coverage; reported/recognised usage; pending/recognised cost;
source valuation COSTED/PARTIAL/UNKNOWN with known contribution; Job overall completeness. A fully
supported15000.0000 direct entry may be COSTED as a source without proving all Job labour is captured.
No entries means 'not captured/not assessed', not free labour. Even all visible entries priced
cannot establish full Job cost without supported source coverage/reconciliation.

Closeout: optional uncollected evidence is not an invented pending timesheet. Where evidence was
required or is known outstanding,0145 guards still apply. Known unpriced records need missing
sources/reasons/owner and an actually supported follow-up path; unknown impacts/disputed quantities
or unresolved ordinary entries block. Completion-only must not conceal a known contractor liability.
Historical additions after close use reviewed historical evidence, never new execution authority.
**Trade-off/defer:** honest coverage is less reassuring than false profit, but permits small-business
use. Detailed weighting/capacity/crew progress remains deferred without blocking basic completion.

## L5 — Corrections across all methods

**Problem/example:** correct15000.0000→14000.0000, six days→five, or a wrong Job attribution.
**Options:** overwrite recognised records; prohibit corrections; linked append-only adjustments.
**Recommend:** linked corrections with original source, reason, actor and occurrence/recording times
at evidenced granularity (a work date is not a fabricated timestamp).

| Correction | Proposed controlled effect |
|---|---|
| Hours/days/fixed quantity | Adjust supported quantity and dependent valuation, not parent accepted capacity |
| Direct amount | Monetary adjustment only; no invented usage |
| Adopted rate/FX | Linked valuation difference; no new consumption/ordinary catalogue recost |
| Worker/source/Job/activity | Correct attribution with tenant/eligibility/readiness checks; atomically release old contribution and establish corrected one |
| Occurrence date/period | Re-evaluate historical rate/hold/assignment/approval and dependent effects; preserve original |
| Provisional edit | New revision invalidates relied-on review; cannot post stale approval |
| Held/completed/closed |0145 reviewed historical path; no implicit restart or hold bypass |

Unsafe/unknown dependent effects preserve a visible discrepancy and refuse applied changes. No
automatic period reopening; Finance owns future posting/period treatment. Generic non-negative
financial result types cannot represent signed adjustment semantics without an explicit adapter.
**Trade-off/defer:** more lineage, truthful corrections. Method can be delayed until its correction
path is supported; no broad freeze of other methods.

## L6 — Authority, source conservation and future Finance

**Problem/example:** a15000.0000 declaration later overlaps a payroll/invoice allocation.
**Options:** broad Job-manage + sum all records; separate ledgers per method; common source
attribution with owning adapters and explicit reconciliation. **Recommend:** third.

Separate own/on-behalf recording, team usage, direct incurred-amount recording, review/recognition,
rate adoption/read, sensitive correction/history, cost report/export eligibility. Monetary input
necessarily reveals the submitted amount to its authorised recorder, not wider wages/Job margins.
Reuse RecordScope/Request+Action guards and shared Approvals; no assignment/role-name inference,
universal maker/checker, new grants or separate SIMPLE permissions engine. Exact vocabulary later.

Stable source/component/revision identity and allocation coverage prevent repeat attribution.
Recognise/attribute and their receipts share transaction/connection and compatible Job/source locks;
recheck state+holds/termination, generations/approval, source ownership and capacity under locks.
Concurrent recognition/correction/closeout must conserve one effective source chain. Fresh-key
same intent is not another expense; equal amounts alone are not duplicate identity. Disjoint
legitimate expenses may be equal. Unlinked text cannot guarantee semantic deduplication.

Provisional/recognised views never sum; activity totals are projections. Replacing aggregate costs
with named detail needs explicit covered-work reconciliation, not additive entry. Invoice/payroll
matching later declares whether it confirms existing operational evidence, economically replaces it
or supplies a difference. Proposed policy: preserve both originals and one effective allocated cost;
differences append, including separate genuine FX components. GL representation/payment is not another
Job cost. Partial matching needs exact scoped coverage; ambiguous overlap stays unresolved and the
affected automatic second attribution refuses. Never match solely by date/amount/worker/Job.
Initial direct declaration must identify whether later authoritative settlement evidence is expected;
retain a follow-up/source-coverage link. No supplier AP/payroll matching engine is authorised now.
**Trade-off/defer:** manual reconciliation initially, not perfect automated duplicate discovery.
Defer owning adapters, while requiring lineage/correction support before exposing their paths.

## Practical examples and proposed failure probes

| Business | Safe representation |
|---|---|
| Painting | Complete supported checklist; recognise internal incurred labour declaration15000.0000; no worker/hours/rate. Source can be known while overall coverage remains unassessed |
| Installation | Three workers × two workdays = six worker-days; at adopted2500.000000/worker-day →15000.0000, else recognised usage/unpriced. No assumption of48 hours |
| Fixed-price installer | Contract50000.0000 remains commitment. Verified incurred20000.0000, advance10000.0000 and later invoice are separate facts; actual never automatically80000.0000 |
| Repair/service | Two technicians × three hours = six person-hours, not six visits. At600.000000/hour →3600.0000; named attribution only where captured/required |
| Larger organisation | Named sources, required review, pinned rates and explicit cost allocations; detailed evidence uses same attribution path |

Review probes, not implementation tests: completion-only cannot show complete zero cost; amount
without hours can recognise; six worker-days cannot convert to hours without evidenced compatible
basis; required named policy refuses anonymous input; same source retry recognises once; later
invoice cannot silently add matching amount; unknown FX preserves original amount but no base total;
mixed records cannot fake complete cost; monetary correction adds no hours; held/closed reports
retain context; stale review/unsafe attribution correction refuses; concurrent close/recognise serialises.

## Historical owner decisions — business direction resolved by0146

1. Approve/revise first release: completion-only + narrow direct incurred amounts; fixed units/time next.
2. Approve/revise conditional worker identity and explicit aggregate basis, no anonymous accountability claims.
3. Approve/revise separate record/recognise authority with combined SIMPLE command and applicable managed review.
4. Approve/revise class-rate adoption for rate methods, base-only initial amounts, unit/date/FX gates.
5. Approve/revise linked multi-method correction and explicit later-source reconciliation boundaries.

No new ADR accepted. No accepted0145/R7/R9 conflict identified: required known actuals are reconciled,
not fabricated optional timesheets. Earlier research's worker/hour-only restrictions are superseded
proposals. Historical Job Costing's employee/hour schema is not a mandatory labour contract.
Exact worker master/units, review subject/duties, rounding/FX adapters and source integration contracts
follow business approval. Materials/package prerequisites, Q24,0040 Job-cost correction lineage,
0137 shared Documents and Finance period boundaries remain preserved, not designed or waived here.

## Related

- [Evidence and architecture inspection](phase-5-labour-evidence.md) · [Q51](../OPEN-QUESTIONS.md#q51--phase-5-labour-resource-usage-and-recognition)
- [Phase5 plan](phase-5-job-execution.md) · [Lifecycle](../domain/job-execution-lifecycle.md)
- [0145](../decisions/0145-separate-job-lifecycle-execution-and-operational-closeout.md) · [Historical costing](../domain/job-costing.md)
- [Items/rates](../domain/items.md#resource-rates) · [Currency](../domain/multi-currency.md) · [Approvals](../domain/approvals.md)
