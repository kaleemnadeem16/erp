[← Phase 4 planning](phase-4-sales-quotations-and-handover.md)

# R1 — Leads, opportunities and customer relationships

- **Status:** R1-A and R1-B1/B2 owner-reviewed proposals recorded; R1-B3 scope/group direction reviewed; forecasting discussion pending. Not accepted or complete.
- **Research/access date:** 2026-10-08.
- **Question:** how do we qualify enquiries and track independent deals without forcing CRM or
  financial-account setup onto every quotation, duplicating Parties or restricting future scale?
- **Existing-system evidence:** [review](phase-4-existing-system-review.md#r1-foundations--verified-first).
- **Accepted new R1 decisions:** none. Recommendations below are proposals, not requirements.

## Primary-source comparison

Three mature products were sufficient to distinguish the main choices. Vendor behaviour is
paraphrased; these are workflow comparisons, not a claim that their storage must be copied.

| Product | Relevant observed behaviour | Implication for this ERP |
|---|---|---|
| **Odoo 19** — [lead conversion](https://www.odoo.com/documentation/19.0/applications/sales/crm/acquire_leads/convert.html), [quotation from opportunity](https://www.odoo.com/documentation/19.0/applications/sales/crm/acquire_leads/send_quotes.html) | Leads are optionally enabled, including by team. Conversion can create/link a customer or merge similar enquiries; salesperson/team assignment is supported. An opportunity can have several quotations and need not already have a customer. | Qualification can be optional; candidate matching and an explicit link step prevent unnecessary identities. Its partner/customer concept must not be equated to our financial CustomerAccount. |
| **Dynamics 365 Sales** — [qualification](https://learn.microsoft.com/en-us/dynamics365/sales/qualify-lead-convert-opportunity-sales), [configuration](https://learn.microsoft.com/en-us/dynamics365/sales/define-lead-qualification-experience) | Qualification can create or reuse account/contact records, with configurable creation choices and optional multiple opportunities from one lead. Similar records prompt review. Disqualified leads can be reactivated. | An enquiry can yield several genuine deals; conversion should retain provenance and allow existing identity reuse. A CRM account is not evidence that receivable setup is mandatory. |
| **ERPNext** — [lead](https://docs.frappe.io/erpnext/lead), [opportunity](https://docs.frappe.io/erpnext/opportunity), [quotation](https://docs.frappe.io/erpnext/quotation) | Leads represent potential customers, including people or organisations. Opportunities can be created against a lead or existing customer; multiple deals are supported. Quotes can be addressed to a lead/customer and created directly or from an opportunity. Ownership/follow-up and lost-reason capture are available. | A direct quotation and a prospect quotation are realistic separate paths. Our organisation-only Party boundary needs explicit reconsideration if person customers are required. |

[Microsoft opportunity closure](https://learn.microsoft.com/en-us/dynamics365/sales/close-opportunity-won-lost-sales)
also records closure information and permits reopening. It requires associated quotes to be closed
before closing the opportunity; this is one possible consistency rule, not our accepted rule.

**Convergence:** distinguish potential buyer, a concrete deal and the commercial offer; reuse
known identities; preserve qualification/closure history; support repeat customer deals.
**Divergence:** optional qualification, when customer/account records appear, conversion
multiplicity and how commercial documents drive CRM state. Sources do not settle our financial
onboarding, identity matching rules or definition of an abandoned deal.

## Owner-reviewed R1-A direction — 2026-10-08

[Proposed ADR 0098](../decisions/0098-hybrid-sales-opportunity-requirement.md) is the canonical
record of the owner's hybrid workflow direction, three company presets, narrow MANAGED exception,
policy transitions, issue evidence and remaining architectural questions. It is **proposed**, not
accepted; no code or migration is authorised. Earlier recommendations below remain initial
research where they differ from that direction.

R1-B now discusses direct quotation linkage within those presets rather than assuming quotations
are always free of an opportunity requirement. R1-C's customer readiness is independent and cannot
be bypassed. R1-D person customers, R1-E owners/visibility/teams, R1-F deal closure and R1-G
identity/CRM extent remain open. Multiple opportunities per lead is direction reviewed in R1-A;
its conversion/duplicate mechanics are not settled.

## Owner-reviewed R1-B1/B2 and initial R1-B3 research — 2026-10-08

[Proposed ADR 0099](../decisions/0099-separate-quotation-issue-and-crm-association.md) records
ordinary draft link/unlink/relink corrections and a separately governed current CRM association
after issue. Original issue linkage remains immutable; post-issue changes need authority, reason
and history and cannot cure an issue-policy exception or change commercial evidence.

[R1-B3](phase-4-r1-b3-offers-and-forecast.md) now compares three mature products and distinguishes
quotation family, revision, alternative/additive scope, CRM association and forecast basis.
The owner has now reviewed multiple accepted independent scopes and explicit exclusive groups
in [proposed ADR 0100](../decisions/0100-independent-quotation-scopes-and-alternative-groups.md).
[Forecast research](phase-4-r1-b3-forecast-basis.md) proposes manual remaining-business estimates
or a controlled selected set of non-competing issued revisions; forecasting is not approved. R1-B remains incomplete;
accepted/job-linked restrictions and physical schema are unresolved. Discuss forecast basis next, then R1-C.

## Alternatives and initial recommendations

### 1. Enquiry and deal are distinct; storage remains open

A lead captures an unqualified enquiry; an opportunity describes a potential sale with a scope,
owner and expected close date. Example: a distributor enquires about equipment and separately
about annual maintenance. One enquiry may lead to two independently won/lost deals.

| Alternative | Benefit | Cost / failure mode |
|---|---|---|
| Separate Lead and Opportunity entities | Independent lifecycle, multiple deals from one enquiry, clear qualification evidence | Conversion and shared enquiry/identity fields need careful ownership |
| One pipeline entity with enquiry/deal type | Fewer records; easy promotion for a one-deal enquiry | Multiple deals require explicit linking/splitting; careless promotion erases qualification history |
| Opportunities only | Lightweight for repeat-business companies | No clean intake/disqualification distinction; marketing enquiries inflate forecasts |

**Propose:** retain separate business concepts and optional lead intake; prefer separate entities
for initial design because of multiple-deal provenance. Do not decide physical tables before
agreement. A small company should be able to start at Opportunity or Quotation.

### 2. Direct quotation path — initial recommendation, now qualified by R1-A

A returning customer asks for a routine service price: forcing a dummy opportunity adds little.
A negotiated equipment purchase benefits from one opportunity tracking alternative quotes.

**Initial recommendation (qualified by proposed ADR 0098):** direct quotation allowed according
to company preset, with linkage rules still under R1-B; both paths use the same pricing,
approval, snapshot and issue rules. Mandatory opportunity improves pipeline coverage but creates
fictitious deals for routine quotes. Quotations linked to one deal may be alternatives or revisions:
R2 must distinguish these and R9 must prevent forecast double-counting.

### 3. Reuse Party; delay financial setup deliberately

Existing Party is shared identity, CustomerAccount is a role with required financial fields.
"Qualified prospect" and "configured customer account" need not happen on the same date.

| Recipient/onboarding choice | Trade-off |
|---|---|
| CustomerAccount required before every quote | Simplest fit to old Sales draft; forces Finance/terms setup and permissions for an uncertain buyer |
| Established Party allowed at quote; customer role required by acceptance/handover | Reuses identity without early Finance setup; needs recipient rules, site/contact surfaces and a controlled onboarding gate |
| Quote directly against raw lead | Least friction before qualification; more copying/reconciliation and uncertain legal identity at issue |

**Propose:** raw enquiries need no account; qualify by linking/creating a Party; allow a Party-backed
prospect quote and require customer-role readiness at a later agreed gate. Whether that gate is
issue, acceptance or job handover remains an owner decision in R1/R6/R7. This extends the old
Sales draft's customer-only reference and requires a new ADR. No relaxation of current financial
field guards is proposed. A salesperson without account authority hands setup to an authorised
person; quotation permission must not imply Parties/Finance maintenance.

### 4. Identity matching and conversion integrity

**Propose:** search existing company-scoped Parties/accounts before creating; display possible
matches by normalised name, registration/tax identifiers and available contact channels. Exact
code and account/party uniqueness remain hard constraints. Other identifiers are warning evidence
until jurisdiction and duplicate policy are agreed. A common purchasing email may represent
several legal entities, so email/phone/name must not cause an automatic merge.

Conversion should record actor/time, original lead and chosen Party/contact, and preserve intake
history. Retries/concurrent conversion must not create duplicate role accounts or repeat the same
deal; legitimate additional opportunities need an explicit separate action. Linking a supplier's
Party may add a customer role without altering the supplier role. Do not overwrite existing
master fields from a lead without authority and explicit reconciliation. Archived identities need
a restore/reuse policy; new codes are not a safe workaround. Cross-company linking is forbidden.

Advanced master merge can be deferred, but detection and controlled reuse cannot be omitted from
conversion. Master merge eventually needs reference repair and issued evidence protection.

### 5. Owners, assignment and stages

**Propose:** one accountable active company-member owner per deal, optional lead intake queue,
audited reassignment, optional next-action/date. Reuse Administration memberships and identity.
A contact at the customer is distinct from the internal owner. Ineligible-owner reassignment
must preserve old history and handle suspension without silently losing work.

Use company-configurable open stages (e.g. Qualified → Proposal → Negotiation) mapped to a stable
terminal outcome. A business that sells maintenance contracts may use different stage names from
an equipment distributor. Stage labels must not encode permissions or quotation statuses.
Probability should support simple defaults initially; predictive scoring is unnecessary. Separate
owner from visibility: own/assigned/team access is not delivered by current warehouse scope.
R9 must settle its policy before any Sales surface is built, including lookup/exports/aggregates.

### 6. Multiple deals and terminal outcomes

**Propose:** many opportunities per Party; many quotation families per opportunity; revision
lineage remains within a family. A lost quote variant need not mean the whole deal is lost.
Forecast one deal once, not the sum of mutually exclusive offers.

Keep open stages separate from WON / LOST / ABANDONED outcomes. "Lost to another bidder" differs
from "customer cancelled the project". Capture actor/date/reason and retain the previous closure
on reopen. Reopen the same lost/abandoned deal for revived scope; use a new opportunity for a new
buying event. Closing the pipeline must not revise an issued quote, undo acceptance or cancel a
job. Won reopening requires R6/R7's commercial-correction rules.

The win trigger is unresolved: accepted quotation, manual closure (for sales concluded elsewhere),
or either with evidence. If automatic win is supported, partial acceptance and other offers need
explicit handling before totals can be trusted.

### 7. Progressive CRM scope

**Propose initially:** optional lead capture, direct opportunity/quotation, owner and reassignment,
company stages, expected amount/currency/date, notes/next action, conversion/linking, closure and
reopen evidence, simple scoped list/pipeline totals. Unknown deal value stays unknown, not zero;
forecast currency conversion and probability rules belong to R4/R9.

**Safely defer:** campaigns, lead scoring, email ingestion/synchronisation, territory routing,
commission calculations, predictive forecasting, shared selling teams, complex activity/calendar
integration, portals and master merges. Defer notification delivery to Q33; a recorded next-action
is not a promise of automatic reminders. Storage/permissions should leave room for these without
requiring them to use a quotation.

## Decisions needed from the owner

| ID | Choice for discussion | Current recommendation / dependency |
|---|---|---|
| R1-A | Hybrid workflow and policy requirements | Owner-reviewed direction in proposed ADR 0098; remaining architecture questions prevent final acceptance |
| R1-B | Direct quotation relationships and multiple offers | B1/B2 owner-reviewed proposed ADR 0099; B3 independent scopes/groups owner-reviewed in proposed ADR 0100; forecast basis pending discussion; R2/R5/R6/R7/R9 dependencies |
| R1-C | Can we quote a prospect Party before financial customer setup; when is setup mandatory? | Prefer Party-backed prospect quoting; agree the gate with R6/R7 |
| R1-D | Organisation customers only, or individual consumers in Phase 4? | Keep established B2B boundary unless real requirements justify extension |
| R1-E | Initial pipeline/ownership exposure and visibility? | Single owner, company stages, optional lead queue; own/team/company access needs R9 decision |
| R1-F | What makes a deal won; can closure be manual; how do lost/abandoned deals reopen? | Evidence-backed closure; agree acceptance link and partial-win handling with R6/R7 |
| R1-G | Duplicate handling and initial CRM extent? | Warn/reuse without automatic merge; minimal follow-up, defer full CRM automation |

R1-A has an owner-reviewed proposed direction; the remaining choices are unresolved. Accepted
answers will become ADRs; this record retains the evidence and links to them. The decision set is tracked as
[Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries); it gates dependent Sales design, not other workstreams.

## Research limits and next step

Initial evidence covers identity, optional qualification, direct quotes, multiple deals and
closure patterns. Configurable stage governance, detailed Sales scope, consent/retention,
forecast accounting, quote recipient/contact evidence and conversion concurrency need deeper
validation during the relevant topic. No legal retention rule is inferred from vendor features.
Next discuss R1-B3 forecast basis for accepted-plus-pending scopes, then R1-C onboarding.
Resolve ADR 0098's flagged edge cases before final acceptance; do not mark all R1 complete.

## Related

- [Phase 4 plan](phase-4-sales-quotations-and-handover.md) · [Existing-system review](phase-4-existing-system-review.md)
- [R1-B3](phase-4-r1-b3-offers-and-forecast.md) · [Proposed ADR 0099](../decisions/0099-separate-quotation-issue-and-crm-association.md)
- [Parties](../domain/parties.md) · [Sales pipeline](../domain/sales-pipeline.md)
- [ADRs](../decisions/README.md) · [Open questions](../OPEN-QUESTIONS.md)
- [Research rules](../architecture/research-and-design.md) · [Authorisation](../architecture/authorization.md)
