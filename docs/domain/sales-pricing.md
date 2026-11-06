[← Domain index](README.md)

# Sales pricing

Sales owns customer-facing commercial prices, separately from Inventory cost, Estimating guidance,
Purchasing offers and tax. **R3 P1–P8 business architecture is accepted, 2026-10-12**, in
[0115](../decisions/0115-resolve-sales-pricing-by-explicit-eligibility-and-commercial-intent.md),
[0116](../decisions/0116-govern-sales-discounts-and-final-net-controls.md) and
[0117](../decisions/0117-preserve-pricing-evidence-across-capability-transitions.md).
These refine the pricing provisions of [0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md)
retained from historical 0055; other 0109 contracts remain. No Sales pricing code is authorised or
claimed delivered. The [resolution specification](sales-pricing-resolution.md) and
[implementation slices](../phases/phase-4-r3-implementation-slices.md) are planning handoffs with
explicit R4–R9 gates, not permission to implement those phases.

## Problem statement and sources

One contract serves direct stocked/non-stock Items, service Items, Assembly work and governed manual
lines. Do not invent an Assembly, Item or CustomerAccount to sell normal scope. Source-neutral
price basis remains distinct from offered, stock and technical estimating quantities. Assembly
components use global technical conversions, never commercial package factors (0050/0109).

Maintained price remains fixed for its selected effective version; cost-derived price depends on
complete applicable cost. Example: complete cost `100.0000`, markup 20% gives `120.0000`, target
margin 20% gives `125.0000`. Existing Assembly sell guidance is not maintained Sales price and must
not receive another markup as if it were base cost. Tax follows commercial net under R4.

## Eligibility and precedence

Party identity, optional financial CustomerAccount, descriptive commercial classification and pricing
eligibility are separate. Groups are multiple flat, effective-dated Party memberships; group labels
can satisfy commercial description without duplicate classification entry. No exclusive distributor/
dealer Party kind, hardcoded industry list, automatic label enrolment or account prerequisite exists.

Reuse existing Item category ancestry; configured EXACT_CATEGORY/SUBTREE rules authorise effects,
not category membership alone. Assembly resolves Assembly → Assembly Group → company; manual scope
has explicit basis and company defaults, not text/category matching. Named family/plan exclusions
filter first, deny within that family even against more specific positive rules, and do not implicitly
ban independent agreement terms. Universal bans are named mandatory controls.

```text
validated company, scope, Party, source, measures, date/currency and enabled context
  → explicitly selected agreement terms for covered scope
  → otherwise Party maintained → group maintained → general maintained
       → source policy → permitted manual base, otherwise refusal
  → permitted typed adjustments / deliberate final-net exception
  → independent final-net controls and authority
  → R4 rounding/comparability recheck and tax
  → R5 adopted evidence / issue snapshot (gated, not delivered)
```

Within each audience: Item → nearest eligible category → ancestors → company; then explicit priority
and applicable effective version. Priority does not promote general terms above Party terms. Stable
identity orders equivalent candidates/explanations; materially conflicting equal-rank terms refuse.
Known conflicts refuse activation. No cheapest-price policy or silent selected-agreement fallback.
Uncovered agreement lines use ordinary pricing only if its coverage permits; fixed-term departure
is distinct and initially unavailable, never an ordinary override or discount approval.

## Effects, quantities and commercial controls

Base effects are maintained price, cost plus markup and target margin. Authorised manual entry is an
explicit base/exception fact; agreement and campaign describe applicability, not new arithmetic types.
Percent, per-priced-unit and per-line adjustments declare compatible basis and reference gross.
Final-net override is reasoned, retains calculated/final, replaces net and runs no discount afterwards.

Default one discount slot. Automatic candidates compete under ranking; discretionary entry replaces
only after explicit review/confirmation of **both existing and proposed results**, including any
increase from replacing a larger automatic reduction. No silent increase. In-limit routine discount
captures actor/time without requiring exceptional reason; above-limit needs configured approval or
refusal. Named ordered plans combine distinct benefits only if configuration/rate/agreement permits;
no automatic percentage addition, and no benefit twice in base/tier/slots.

A rate/tier declares allowed adjustment plan or exclusive final-net terms. Tier declares measure and
package/unit eligibility separately from rate basis. Initial line-local all-units breaks do not pool
separate lines or alternatives. Ten cartons at `900.0000` costs `9000.0000` before explicitly allowed
adjustments; marginal `9900.0000` is a different deferred effect. Exact conversions and increments
are not MOQ or tier eligibility; supplier MOQ/multiples do not become Sales constraints.

Advisory failure warns; advisory unknown is unevaluable. Mandatory failure/unknown prevents issue;
floors and margin are independent, applied to final net in comparable quantity/currency basis.
No automatic clamping. Cost-derived and cost-consuming operations need complete evidence under
0110; PARTIAL/UNKNOWN cannot become zero or a complete subtotal. Maintained/manual may sell with
incomplete cost absent a mandatory cost-dependent test. Genuine zero is valid; percentage margin
at zero net is undefined. Separate discretionary and aggregate reference-based authority limits;
manual base changes cannot reset an authority reference.

Risk-exception evidence is an architectural seam, **no initial general bypass**. Exact exceptionable
controls, permissions, separation of duties and approval validity remain R5/R9. Neither approval nor
price entry supplies missing cost, enables incomplete cost-derived arithmetic or departs from contract.

## Modes, context and evidence

| Mode | Approved exposure | Implementation boundary |
|---|---|---|
| SIMPLE | Maintained/authorised manual bases, complete-cost markup/target margin, bounded percent/per-unit/per-line routine discounts, controlled final exceptions | Initial operational target, requires safe duties and R4/R5 integration before quotation delivery. |
| MANAGED | SIMPLE plus dated lists, Party/group/category terms, tiers, agreements, simple promotions, named plans and controls | Optional complete slices; no hidden automatic rules in SIMPLE. |
| ADVANCED | Typed/versioned allowlisted formulas, complex controlled conditions/conditional stacking | Deferred execution until its configuration/preview/writer/UI contract is complete. |

Company enablement is independent of CRM/tax and user preferences. Activation validates dependencies;
downgrade reviews/reconciles dependent drafts, active rules/agreements and approvals, preserving history.
Unresolved dependency refuses change; no silent hiding, auto-conversion or cancellation of obligations.
R5 owns permissible reprice/resolve/withdraw transitions. Company controls cannot be bypassed by mode.

Company-wide context is default; optional flat channels/locations nominate rules only where enabled
and validated. They are not warehouse/region hierarchies or record-access grants. Date/currency/FX/
rounding/tax authority stays R4. Currency conversion is not inferred from an eligible foreign list.

Draft dependency evidence includes source versions, membership/category path, exclusions, absent
candidates where relevant, measures/factors, selected terms, benefit plans, cost/control/authority
and date/context. Changes mark affected drafts stale; explicit before/after reprice or valid no-change
confirmation evaluates current facts. Reads never reprice. Manual final price rechecks the same gates.
Issue freezes resolved figures and provenance under R5; historical price never comes from live masters.

Customer projection shows chosen commercial scope/net and explicitly customer-facing discounts.
Sensitive competing terms, policy/formula/cost/margin internals require independent read authority;
apply on nested APIs, history, lookups, filters/sorts, UI, print/export and errors. Cost-blind legitimate
control evaluation returns safe status, not operands or inferable threshold traces.

## Data model and authorisation

The [typed resolution/model specification](sales-pricing-resolution.md) replaces the old universal
Assembly pseudo-DDL. Company-safe Party/group/catalogue/version references and separate base,
adjustment, exclusion, control and evidence records are required. Money is `decimal(19,4)` with
currency/base/FX evidence under 0012; used economic versions and issued evidence are immutable.
No generic audit trait substitutes for typed reason/actor/time/results. Physical migrations are future.

Existing CRM duty names do not authorise price configuration, discount approval or confidential price
reads. Reuse genuinely matching capabilities only; the slice permission matrix defines proposed duty
contracts and gates vocabulary/grants through R9. Guard Request and Action, constrain exact-duty scope
and independently legitimate source/Party/document access. No role-name inference, authenticated-only
price lookup or blanket manager/administrator risk grant. Coding owner registers/grants/tests all
new surfaces before enabling them; planning does not assign roles.

## Integration gates and exclusions

R4: authoritative pricing date/time bounds, FX/currencies, tax/inclusive display, precision/rounding/
allocation and final comparison. R5: quotation draft/revision persistence, cost adoption/staleness,
issue/expiry/withdrawal, locks/retries and approval fingerprints. R6: options/partial acceptance and
benefit/quantity reconciliation. R7: order/job baseline/commitment fulfilment, actual cost and correction
ownership. R8: customer templates/delivery evidence. R9: duties/limits/scope/audit/import/reporting and
expected-value/forecast/remaining-scope integration. These are not closed by R3 acceptance.

Deferred: marginal/cumulative/pooled tiers, free-goods/promotion engines, loyalty/retrospective rebates,
contract departure/settlement, Sales returns/credits, hierarchy/geography and kit master/stock expansion.
No forecasting, acceptance, reservation, Inventory posting, invoice or job is implied. Accepted scope
cannot be counted twice; current Opportunity expected amount remains unreviewed assumption (0101/0102).
C1d remains separately gated; existing C2/CRM delivery and Supplier Return/Scrap remain untouched.

## Local quotation navigation — M1 preparation

The Quotations workspace is an entry by revision number, not a list or search API.
It links entitled users to metadata (`sales.quotation.view`) or read-only review
(`sales.quotation.prepare`); opening requires a positive safe whole revision number.
Empty, zero, fractional and unsafe input stays on the page with an associated text error.
The shared shell's navigation and the direct workspace URL use the same access policy.
Prepare holders can create a header-only draft and then add or edit lines, or open an existing draft by revision number for editing. The editable read is independent of metadata-view permission.

The frontend exposure opt-in is `VITE_QUOTATION_UI_ENABLED=1` in the main app's local
Vite environment (or its process environment). Restart that local Vite process after
changing its environment. No opt-in is committed as an active default. Bootstrap
requires Vite's built-in `DEV` plus the exact flag value and a loopback hostname
(`localhost`, `127.0.0.1` or IPv6 loopback). Production builds and remote/LAN hosts
remain denied even if the flag is accidentally set. Local storage and URL query
parameters cannot enable the app's gate. Actual duties remain required, and this
flag never registers/grants a permission or changes server authorisation.

Integration prerequisites: #54 slice 1, the corrected #53 write contract and Agent A’s M1 editable-read contract must land before this dependent branch can be submitted for integration.
The shared gate uses this dev policy while its production rollout constant stays false. The independent dev policy tests cover default-off,
production refusal, loopback/remote/opt-in combinations, reconfiguration and the
shared screen rule with/without duties. Mounted shell/workspace fixtures cover absent
navigation and direct denial in production/default-off/no-duty/remote-host cases,
keyboard validation/opening a revision, and the phone More menu. Their destination is a
fixture route; these tests do not prove the real detail/preview screens or an enabled
backend journey. Run a local frontend build first, then
`docker/frontend/run-e2e.sh --project=unit`; no running application or database is used.
Agent A owns the separately reviewed local-only backend mechanism. No real backend
permission activation or database operation is performed by this frontend preparation.
## Quotation draft create/edit — M1

The create page saves a header, then opens the editable draft; adding lines is a separate PATCH. The edit page hydrates only from the generated editable read and retains the input generation. Header changes, direct Item or manual lines, optional/choice grouping and removal with undo are reviewed before saving. Unit prices and quantities remain exact strings and no financial totals are calculated in the browser. The server remains authoritative for source eligibility, pricing precision, currency locking and evidence. An optional Opportunity picker is offered only with pipeline view access.

Each new attempt gets a distinct key via the generated client’s required typed `Idempotency-Key` parameter. PATCH includes the baseline `expected_generation`. An uncertain response retains the exact key and payload in the per-user tab session; retrying sends those same bytes of intent, including after a reload. A definitive 409 permits a newly reviewed attempt; 404 is non-disclosing; stale 422 preserves unfinished input and requires a fresh baseline. None of these controls grants backend access.

The isolated form tests mount the real forms against intercepted responses and cover default denial, minimal changes, uncertain retries across reload, 404, 409, stale 422, decimal refusal and a 390px accessibility/overflow check. A mounted production-bundle test verifies that a quotation-duty holder still sees access denied and issues no quotation request; it also passed locally when the production build was made with `VITE_QUOTATION_UI_ENABLED=1`. A separate 5178 Vite smoke check loaded both actual screens with all API requests intercepted; neither proves a live backend journey. Real backend verification waits for the write and editable-read contracts, an explicitly enabled local preview and the agreed isolated test data.

## Quotation detail/review UI — #54 slice 1

Owner-approved scope is a deep-link page (no list exists): `/quotations/:revision`
for safe metadata and `/quotations/:revision/review` for financial preview. This page
exception is intentional: there is no parent list behind a record sheet. Users inspect
draft identity, readiness and uncertain amounts; no submission or lifecycle action exists.
The shared display components are reusable by the subsequent create/edit form.

`QUOTATION_UI_ENABLED` remains false. In the slice-1 baseline navigation has no quotation entry, direct
routes default-deny, and the screen guards itself before mounting queries. The separate M1 branch adds the strictly local opt-in above. Future activation needs separate owner approval and server
rollout. Metadata requires `sales.quotation.view`; preview independently requires
`sales.quotation.prepare`. A prepare-only holder must never trigger the metadata GET,
and view alone never triggers preview. Backend Request/Action guards remain authoritative;
company/record scope is not inferred by the client. No permissions or grants are changed.

| Contract element | UI destination | Coverage and acceptance |
|---|---|---|
| Safe identity, revision/family, state, generation, line count | Details | Generated show hook; no commercial data fetched |
| Recipient, currency, opportunity, creator | Labelled references | Temporary numeric-reference fallback: these projections contain IDs only; no inferred names or permission-expanding lookup calls. Human labels need a separately reviewed projection/lookup contract before activation |
| Pricing, tax-estimate, local validity dates | Details | Shared date formatting; absent dates show placeholder |
| Preview identity, generation, pricing date, authority | Review heading/summary | Independent preview GET; explicitly read-only, never adopted as authority |
| Lines: scope/baseline/choice, description/section, source, offered/priced/stock units and quantities | Responsive line cards | Quantities remain distinct; no page-wide horizontal table; empty lines explicit |
| Entered/base unit prices, settled line net, optional/base totals | Line cards/totals | Decimal strings only; zero is known, UNKNOWN never zero, PARTIAL shows the known lower bound as “at least”; currency reference always visible |
| Settlement computed amount/fraction/residual, FX freshness | Line evidence | Server evidence only; opaque FX fingerprints/routes/ratios remain internal metadata, not operator controls |
| Rounding policy and stale-policy revalidation | Review sections | Policy basis/scale/mode; review reasons and affected line references |
| Tax, total including tax, controls, issue readiness/blockers/issues | Review summary | Unknown tax, unconfigured controls and unavailable issue remain explicit; no issue button |
| Loading, 403, non-disclosing 404, 422, network/server/malformed response | Shared failure/loading components | 404 never repeats response details; 422 preserves the server message; Refresh review is offered only for QUOTATION_DRAFT_CHANGED. Other 422s need input/state correction; transient request failures offer retry |
| Filters, sorts, pagination, bulk, print/export, history | Deferred/not applicable | No list endpoint (#55), no history projection or output adapter in this slice; do not fabricate |
| POST/PATCH, 409 request-key reuse, Idempotency-Key, expected_generation | Slice 2 | No mutations here; dependent on #53 integration and a fresh branch |

Automated evidence is deliberately split:

- Policy and server-render checks cover independent capability combinations and denial
  despite supplied grants while the production gate stays false. They do not activate routes.
- A standalone browser fixture mounts the real internal `QuotationRecord` renderer with
  React Query, MemoryRouter and intercepted generated-client requests. The stale-preview
  test tabs through the actual links/button, presses Enter on Refresh review, asserts a
  second preview GET, then asserts the review replaces the error. `/quotations/abc` is
  mounted and checked for zero API requests. Metadata requests no financial preview;
  review navigation retains the query string. Nonrecoverable recipient/noneditable 422s
  offer no refresh; malformed previews render the safe unsupported-response error.
- Static display-boundary fixtures check complete zero/partial/unknown values, empty/manual
  lines, missing conversion/base-price evidence and rejection of negative unit prices;
  protected extra cost/margin fields nested on preview lines and totals are not rendered.
  The 404 fixture checks non-disclosing text, not a live hidden-record lookup.
- Separate 390px light/dark fixtures inspect static display markup with the production CSS:
  long descriptions, no horizontal page overflow and no serious/critical axe findings.
  These are layout checks, not an enabled production-route user journey.

These isolated tests use no running application or database. From the worktree, first run
`cd frontend/apps/main && npx vite build`, then from the repository root run
`docker/frontend/run-e2e.sh --project=unit`. The isolated project contains both pure-function
checks and browser fixtures; it needs Chromium and the built CSS. The renderer fixture seam
is not exported through the UI package barrel; production entry remains the guarded screen.

Before activation: independently verify the live enabled routes, backend entitlement/scope
and real hidden/missing-record 404 parity after QA1–QA3; supply entitled human labels for
Party/currency/unit/creator references; apply the separately reviewed holder/activation plan.
This slice does not claim those checks. Current non-negative monetary parsing follows
QL1a's `StorageAmount` contract (negative amounts refused); signed settlement residuals are
separate evidence and remain signed strings. Credits would require a new server/UI contract.
The work-area link provides a safe fallback with no invented list.

## Draft concessions — discounts and final-net overrides (PR4/PR5, delivered)

A DRAFT holds the seller's concessions as inputs, stamped with who entered them and when; the server calculates every amount through the kernel (`CalculateSimplePrice`, the settlement
rule and largest-remainder allocation) and the browser calculates nothing.

| Concession | Stored on | Duty (STAGED, quotation-family scope) | Rule |
|---|---|---|---|
| Line discount (one slot): PERCENT, PER_PRICED_UNIT, PER_LINE | `quotation_draft_lines` | `sales.quotation.discount` | Greater than zero; never larger than its line; settled once |
| Final-net override: amount + mandatory reason | `quotation_draft_lines` | `sales.quotation.price.override` (sensitive) | Needs an entered price; may only **reduce** the calculated net; excludes a discount; a price or quantity change must restate or clear it |
| Document discount (one slot): PERCENT or AMOUNT | `quotation_draft_document_discounts` | `sales.quotation.discount` | Spread over the counted lines (base and baseline choices) by largest remainder, so the header stays the sum of its settled lines; optional lines take no share |

**Approval is a recorded fact that the shared Approvals engine can now decide** ([ADR 0158](../decisions/0158-approve-quotation-concessions-through-the-shared-approvals-engine.md); see
[Approving a draft's concessions](#approving-a-drafts-concessions-delivered)). An override always needs approval (no risk bypass). A discount needs it unless the company's published
control set holds a reduction control (`INPUT_REDUCTION_*`, `AGGREGATE_REDUCTION_*`, now publishable) that the discount is proven within; no control set, no reduction control, a policy the set
was not written for, or figures that cannot be computed yet count as "not proven within a limit" (default-deny). Only a code is stored and returned (`PRICE_OVERRIDE`,
`DISCOUNT_ABOVE_LIMIT`, `DISCOUNT_LIMIT_NOT_CONFIGURED`, `DISCOUNT_LIMIT_UNVERIFIED`); no limit, threshold or cost reaches any reader. A draft that needs approval is blocked from issue
(`APPROVAL_REQUIRED`) until an approval for exactly that draft stands (`APPROVAL_PENDING` and `APPROVAL_REJECTED` say which); at issue the reduction controls also see the discount (reference and discretionary input) instead of being unevaluable.

Issue freezes the provenance per line (`line_reduction_kind`, `line_reduction_amount`, `document_reduction_amount`), and the customer document shows the line `discount` so quantity x unit
price - discount = amount. Override reasons and approval codes never reach the customer projection. Duties are not in the local dev preview; they are switched on only inside the demo
seeder's own transaction.

## Approving a draft's concessions (delivered)

A draft whose discount or final-net override needs approval can be sent for approval, decided and then issued, through the shared Approvals engine (subject `QUOTATION_REVISION`, one per revision).
It is a maker/checker split by duty and by person:

| Step | Route | Duty (all STAGED, each with `sales.quotation.view`) | Rules |
|---|---|---|---|
| Read where it stands | `GET /api/v1/quotations/{revision}/approval` | `prepare` or `approve` | State for the draft **as it is now**, the request, decisions with written reasons, and what the caller may do (`can`). No amount, limit, ceiling or cost. |
| Ask | `POST .../approval` | `sales.quotation.prepare` | Needs `expected_generation` and an Idempotency-Key. Refused when nothing needs approval, when the company has not set approvals up (mode and an applicable policy), when it is already approved, or when it was rejected as it stands. |
| Decide | `POST .../approval/decision` | `sales.quotation.approve` | `APPROVED` or `REJECTED` (a rejection needs a reason). The engine's step capability and any authority ceiling apply, **and** the quotation-family record policy (OWN or ALL; a hidden record is 404). |
| Withdraw | `POST .../approval/withdrawal` | `sales.quotation.prepare` | Only the requester (or an approvals manager); a reason is required. |

Binding and separation: the approval is for the exact draft - the engine's hashed facts carry the draft's input generation and a content fingerprint of every pricing, concession, recipient,
currency, rounding and FX-evidence field, so any edit ends a pending or granted approval (history stays) and a stale request is ignored by readiness and issue. The person who created the draft,
entered a concession on it or requested the approval can never decide it, whatever the policy says, and a delegate cannot stand in. The same record policy is part of the engine's single
eligibility, so the approvals inbox and the generic decision route cannot reach a quotation the family policy hides. Approving never issues. Issue consumes the approval (it locks the request) and the
append-only issue records `approval_request_id` and the approved content fingerprint beside the already-frozen line reductions; the customer document carries no approval word. A failed mandatory
control or any other blocker is independent of the approval.

Not delivered: discount-size thresholds (the engine routes no amount for a concession; owner decision), approval expiry (a policy step can set a deadline; an approval otherwise lasts as long as the
draft is unchanged), delegation, notifications, approval of an issued revision's replacement.

## Quotation list: recipient name and net amount (delivered)

`GET /api/v1/quotations` adds `recipient_name` and `net_amount`, each decided **per row inside the list's transaction and access fence**, never by a screen:

- `recipient_name` follows **Party read**. Party read has no duty and no record scope (the Parties API is open to every active member; the company scope keeps another company's Party
  out), so the rule is exactly that: a current active member and a Party visible under the company scope. `RecipientNames` is the one place to change if Parties ever gain a read duty.
- `net_amount` needs the independent, STAGED `sales.quotation.commercial.view` (family scope OWN or ALL) **for that row's family**, judged by `QuotationAccess`, and is the **frozen net
  total of an ISSUED revision**. A draft shows null: a list never reprices and a draft's settled figure is a preview. The duty never adds a row the view scope would hide.
- A hidden value is `null`, the same as an absent one, so the answer never says which. Neither can be sorted or filtered on (both are refused with 422). No cost, margin or tax fact is added.

Safest default if the model ever cannot judge a row (no duty, staged, suspended member): null, never a refusal that names the record.

## Related

- [Resolution specification](sales-pricing-resolution.md) · [R3 slices](../phases/phase-4-r3-implementation-slices.md)
- [Accepted review](../phases/phase-4-r3-business-review.md) · [Worked example](../phases/phase-4-r3-worked-example.md)
- [0154](../decisions/0154-record-draft-concessions-tax-declarations-and-list-values-as-versioned-per-row-facts.md) (concessions, declarations, list values)
- [0115](../decisions/0115-resolve-sales-pricing-by-explicit-eligibility-and-commercial-intent.md) · [0116](../decisions/0116-govern-sales-discounts-and-final-net-controls.md) · [0117](../decisions/0117-preserve-pricing-evidence-across-capability-transitions.md)
- [0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md) · [Parties](parties.md) · [Items](items.md) · [Assemblies](assemblies.md)
