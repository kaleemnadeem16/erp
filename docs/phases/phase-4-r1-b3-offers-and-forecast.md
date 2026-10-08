[← Phase 4 planning](phase-4-sales-quotations-and-handover.md)

# R1-B3 — Multiple offers, revisions and opportunity forecast

- **Status:** multiple accepted scopes and explicit alternative groups owner-reviewed; forecasting discussion ongoing; not accepted or complete.
- **Access date:** 2026-10-08. Compared Dynamics 365 Sales, Odoo 19 and ERPNext official guidance.
- **Question:** how can one deal have several offers and separately acceptable scopes without
  counting alternatives, prior revisions or accepted scope twice in pipeline reporting?
- **Existing foundation:** [review](phase-4-existing-system-review.md); Sales remains documented-only.
- **Owner-reviewed directions:** [ADR 0098](../decisions/0098-hybrid-sales-opportunity-requirement.md)
  and [proposed ADR 0099](../decisions/0099-separate-quotation-issue-and-crm-association.md).
- **Owner-reviewed extension:** [proposed ADR 0100](../decisions/0100-independent-quotation-scopes-and-alternative-groups.md).
- **Accepted R1-B3 decisions:** none. No physical table boundaries are chosen.

## Discussion update — 2026-10-08

The owner reviewed multiple accepted quotation revisions for independent scopes under one
opportunity, and explicit exclusive groups of competing families (independent by default).
Revisions retain their family's group relationship and are not extra offers. Proposed ADR 0100
records the rules and concurrency/history implications; this does not decide closure or jobs.

[Forecast-basis research](phase-4-r1-b3-forecast-basis.md) now assesses manual estimates, a single
selected revision and a controlled non-competing set. Its recommendation broadens the initial
single-offer suggestion below to support multiple independent scopes. Earlier examples and
comparisons are retained as research history; earlier "must we support multiple scopes/groups?"
questions have owner-reviewed direction, not final ADR acceptance. Forecast business meaning now has owner-reviewed direction in
[proposed ADR 0101](../decisions/0101-separate-accepted-business-and-remaining-forecast.md).
[Staleness](phase-4-r1-b3-forecast-staleness.md) and [FX](phase-4-r1-b3-forecast-fx.md),
eligibility and refresh remain for discussion; R1-B3 is not complete.

## Business distinctions

| Concept | Business meaning | Example |
|---|---|---|
| Opportunity | Specific buying event/deal being pursued | Equipment supply and associated services |
| Quotation family | One commercial offer through its negotiation history | Standard equipment offer A |
| Revision | A replacement version of that offer, with lineage and its own issue evidence | A revision 2 changes the negotiated price |
| Alternative offer | A competing choice for the same scope; do not assume both will be bought | Standard A vs premium B |
| Independent/additional scope | Can genuinely be accepted alongside other scope | Equipment plus a separate annual service agreement |
| CRM association | Organises an offer against a deal; issue-time and current links differ | Previously direct offer is associated later |
| Forecast basis | Explicit assumption about likely value and timing | Owner estimate or selected current offers |

A separate document does not by itself prove separate scope. A copied quotation is not
necessarily a revision; a larger revision number is not evidence that another offer can be
added to a forecast. Contacts/sites may differ while legal Party identity remains consistent.

## Primary-source comparison

| Product and direct sources | Relevant behaviour / difference | Consequence for our design |
|---|---|---|
| **Dynamics 365 Sales** — [quote lifecycle](https://learn.microsoft.com/en-us/dynamics365/sales/sales-transactions), [opportunity FAQ](https://learn.microsoft.com/en-us/dynamics365/sales/faq-opportunity), [quote/order relationship](https://learn.microsoft.com/en-us/dynamics365/sales/developer/quote-order-invoice-entities) | Active quotes are read-only; revision creates a new draft with incremented revision ID. Opportunity revenue can be user-provided or system calculated. The FAQ explicitly warns that linked multiple quotes can duplicate opportunity aggregates. Converting a quote to an order can keep its opportunity open or close it. | Revision and deal outcome are distinct. Forecasting must aggregate at the intended deal/scope grain, not raw quote joins. Order conversion is not universally a mandatory full-deal win. |
| **Odoo 19** — [quotes from CRM](https://www.odoo.com/documentation/19.0/applications/sales/crm/acquire_leads/send_quotes.html), [optional products](https://www.odoo.com/documentation/19.0/applications/sales/sales/sales_quotations/optional_products.html), [expected revenue](https://www.odoo.com/documentation/19.0/ko/applications/sales/crm/performance/expected_revenue_report.html) | An opportunity can have multiple quotations. Optional products support additional choices within quoting. Expected-revenue reporting selects pipeline opportunities with expected closing dates, rather than summing all quotation documents. | Multiple quote documents and optional extras are different concepts. Reporting can remain deal-centred even when offers multiply. Optional products are not evidence of exclusive alternative-family rules. |
| **ERPNext** — [quotations](https://docs.frappe.io/erpnext/quotation), [opportunities](https://docs.frappe.io/erpnext/opportunity), [official quotation implementation](https://github.com/frappe/erpnext/blob/develop/erpnext/selling/doctype/quotation/quotation.py) | The documentation describes cancel/amend and open/ordered/lost/expired/cancelled outcomes. Current development source also has explicit revision lineage, item alternatives and partially ordered status; marking a quotation lost can update its opportunity to Lost. Opportunities have amount/probability. | Selection and ordered scope need line-level provenance; totals alone cannot represent remaining scope. Revision/amend semantics and loss propagation must be reviewed, not copied; our proposal does not automatically lose the deal when one alternative fails. |

Odoo's English forecast page did not load during this pass; the official Korean-localised
expected-revenue page supplies the reporting evidence. No assertion about a native immutable
Odoo quotation revision chain is made. ERPNext source is a moving development branch and is
supporting evidence, not a version-pinned implementation contract.

**Agreement:** separate deal tracking from offered transactions and preserve formal changes.
**Differences:** revision vs cancel/amend, line alternatives vs multiple offers, and manual vs
calculated deal value. Sources do not define a universal exclusive-offer grouping model or
partial-win rule. The model below is our synthesis for review, not a vendor consensus.

## Practical example

All example values use PKR, net of tax, same scope and effective date:

| Offer | Amount | Proposed interpretation |
|---|---|---|
| A revision 1: standard | `800000.0000` | Historical revision once replaced |
| B revision 1: premium | `1200000.0000` | Alternative to A |
| A revision 2: negotiated standard | `750000.0000` | Current candidate within family A |

The opportunity owner estimated `1000000.0000`. Raw quotation totals would produce
`2750000.0000`, which is not the value of the mutually exclusive deal. With owner-estimate
forecasting, it stays `1000000.0000` until explicitly reviewed. With an explicitly selected
A-r2 basis, it becomes `750000.0000`; choosing B makes it `1200000.0000`. At an illustrative
50% win probability, the original weighted forecast is `500000.0000`, shown as a separate
measure, never substituted for the customer quotation price.

If an additional service scope C of `100000.0000` is separately acceptable and non-overlapping,
a reviewed composite selection of A-r2 plus C can total `850000.0000`. It must not add A-r1 or B.
An expected budget is not a reason to cap or inflate quoted amounts. Partial acceptance belongs
to R6; accepted and remaining forecast must then be shown separately, without counting the same
scope again. Nothing about these numbers is an automatic posting or recognised revenue.

## Initial options and trade-offs — historical proposal

| Option | Benefit | Risk / limitation |
|---|---|---|
| One owner estimate per opportunity, quotes informational | Simple and works before prices are known; avoids document multiplication | Can become stale; scope alternatives remain less structured |
| One designated current quotation revision as forecast basis | Explainable for a single-offer deal | Cannot represent multiple additive scopes without splitting the deal |
| Structured scope/choice groups with a selected offer per scope | One choice per exclusive group, additive independent scopes; supports larger deals | More configuration, overlap checks and acceptance rules |
| Sum latest revision of every quotation family | Avoids counting earlier revisions | Still counts mutually exclusive families and overlapping scopes; not safe |
| Automatic latest/highest/lowest quote | Low input effort | Confuses creation order or price with business intent; fails for alternatives and new drafts |

**Recommendation for discussion:** separate all six meanings above, but start with a maintained
opportunity estimate as default forecast basis. Offer an explicit selection of an eligible issued
quotation revision as a simple optional basis. Linking never selects or changes the basis.
Represent exclusive alternatives vs additive scope explicitly when required; shape that seam now
without forcing every small business to configure scope groups. Do not sum families without
knowing whether they compete or are independently acceptable. Physical tables remain R2 design.

A selected revision is a stable reference, not "whatever the latest quote is". A new draft does
not silently replace the selected issued revision or necessarily supersede its predecessor.
If the selected revision is withdrawn/superseded/rejected/expired, flag the basis for review;
do not silently choose another offer or silently set the deal to zero/lost. Detailed eligibility,
expiry and supersession timing belong to R5. Explicit forecast refresh selects a new basis and
records actor/time/source; reason requirements and currency/weighted calculation belong to R4/R9.

## Outcomes and reporting — proposed boundaries, not final rules

| Offer outcome | Recommended opportunity behaviour for discussion | Owner of final rule |
|---|---|---|
| One alternative rejected/withdrawn | Keep other eligible offers/deal available; show offer outcome, review basis if selected | R1-B3/R1-F, R5 |
| Revision superseded | Preserve old issue/history; exclude old revision from current selectable offers; do not auto-switch forecast | R2/R5/R9 |
| One independently acceptable scope accepted | Show accepted scope/value separately; remaining scope may stay open | R6/R7 |
| Partial acceptance | Track accepted vs unaccepted scope with provenance; do not assume whole deal won or sum full quote again | R6/R7/R9 |
| Entire deal accepted | Explicit closure/baseline rule required; linking alone never wins | R1-F/R6/R7 |
| All offers rejected or expired | Prompt review; this alone is insufficient evidence that the customer abandoned the deal | R1-F/R5 |

Define separate measures: pipeline opportunity count/value; probability-weighted forecast;
current offers/alternatives; accepted committed scope; historical issued quotation activity.
Issued-document volume can count revisions if clearly labelled; it is not potential sales.
Aggregate unique opportunity/scope identities before joining quote history; DISTINCT amounts are
not a fix because unrelated deals can have identical amounts. Direct quotes without a current
opportunity belong in a separate report and must not be added to pipeline automatically.
Company, record and sensitive-price scopes apply to totals, drilldowns and exports. A partial
visibility result must not look like the complete deal; choose narrowed/refused presentation in
R9. Reporting uses explicit as-of association and valuation dates, not today's links to rewrite
past reports. Different currencies require an agreed conversion basis; tax-inclusive totals must
not be silently mixed with net forecasts.

## Architectural review and dependencies

- R1 can settle business identities, multiplicity, direct-quote legitimacy, association vs issue
  evidence, non-additive alternatives and forecast intent.
- R2 owns quote family/revision/line structure, optional/alternative/additive scopes and numbering
  presentation. ADR 0079 owns allocation; reusing a family number needs a compatible revision
  identity/allocation contract, not a second hand-built numbering mechanism.
- R3/R4 own pricing dependency context, monetary/tax/FX/rounding rules. Quote association is not
  permission to change immutable price evidence or customer identity (ADRs 0034/0055/0084).
- R5 owns issue/supersession/withdrawal/expiry, effective policy and approval fingerprints. A new
  association/revision is not an ADR 0098 policy bypass or authority to reuse stale approvals.
- R6/R7 own partial acceptance, exclusive-choice enforcement, jobs/baselines and closed-state
  association limits. Rejecting a quote does not itself cancel a job.
- R9 owns association/forecast duties, field/record scope, audit retention, report grains and
  as-of data. Proposed ADR 0099 provides retry/concurrency boundaries for relationship edits;
  basis changes need the same transactional stale/retry-safe pattern.

## Question reconciliation after owner review

Multiple independent accepted scopes and explicit exclusive groups now have owner-reviewed
direction in proposed ADR 0100. Forecasting choices move to the
[forecast research](phase-4-r1-b3-forecast-basis.md#dependencies-and-next-decision), starting
with manual remaining-value vs a selected combination. Still unresolved: post-issue regrouping,
partial acceptance, accepted-scope corrections, dependencies, partial win/closure, job multiplicity,
association scope per family/revision and selected-basis eligibility/recalculation.

## Related

- [Forecast research](phase-4-r1-b3-forecast-basis.md) · [Proposed ADR 0100](../decisions/0100-independent-quotation-scopes-and-alternative-groups.md)

- [Phase 4 plan](phase-4-sales-quotations-and-handover.md) · [R1](phase-4-r1-leads-and-relationships.md)
- [Existing review](phase-4-existing-system-review.md) · [Proposed ADR 0099](../decisions/0099-separate-quotation-issue-and-crm-association.md)
- [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
- [Sales pipeline](../domain/sales-pipeline.md) · [Numbering](../architecture/document-numbering.md)
