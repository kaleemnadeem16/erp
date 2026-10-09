[← Phase 4 planning](phase-4-sales-quotations-and-handover.md)

# R1-C1 — Quoting a prospect without financial customer setup

- **Status:** bounded Party-backed issue direction accepted in ADR 0103 on 2026-10-09; detailed delivery and C2 gates remain open.
- **Access/review date:** 2026-10-09.
- **Bounded question:** may a reliably identified prospect receive an official quotation before
  a CustomerAccount exists? Exact later financial-readiness gate belongs to R1-C2.
- **Gate:** documentation only; no implementation/migration; only bounded C1 business direction accepted.

## Owner decision — 2026-10-09

[Accepted ADR 0103](../decisions/0103-issue-quotations-to-party-backed-prospects.md) records eligible,
company-scoped Party-backed official issue without CustomerAccount; context-dependent recipient
readiness, immutable identity/contact/address evidence and unchanged financial permissions/validation.
Consistency review found no substantive conflict. Physical schema remains R2. Earlier alternatives
below are retained research; this approval does not accept their detailed checklist, archive or
later Finance gate recommendations. [C2 research](phase-4-r1-c2-financial-readiness.md) is now for discussion.

## What exists today

Verified against the current implementation, not only the historical Sales schema:

| Foundation | Implemented behaviour and limitation | Reuse or extension |
|---|---|---|
| Shared Party identity | `Party` supports neither, either or both independent customer/supplier roles. Party create requires code/name; registration/tax identifiers and website are optional. No prospect lifecycle/type is implemented | Reuse identity; no separate prospect/customer master justified |
| CustomerAccount | API/DB require Party, account code, payment terms and receivable mapping. Credit limit/default currency are optional; non-null tax code is refused until its foundation exists | Preserve financial validation (mapping existence/company is checked; ASSET-type enforcement remains Phase 6); attach the role to the same Party when authorised |
| Financial field authority | Account creation checks account maintenance plus terms/mapping management in Request and Action. Credit and mapping reads are separately protected under ADR 0084 | Sales quotation authority must not grant Finance changes or expose restricted fields |
| Contacts/addresses/sites | `PartyContact`, `PartySite`, `CustomerSite` models/tables and parent-derived scope exist. Customer joins require account/site to belong to the same Party. Supplier-site CRUD exists; no independent Party-contact/site or customer-site maintenance routes | Extend governed identity/contact/site maintenance; do not require a customer join merely to identify a prospect's address |
| Archive/restoration | Archive uses soft deletion independently of the status flag. `ArchiveParty` locks the Party and refuses while a non-deleted customer/supplier role exists. No public restore Action/route found | Reuse archive foundations; restoration and historical access need R1-C4/R5/R9 decisions |
| Isolation/duplicates | Company-scoped Party/account models and composite FKs; unique company/code and one customer role per company/Party, including soft-deleted rows. Names, tax ids and registration numbers do not establish real-world deduplication | Search/link existing identity; duplicate detection/merge/restoration remain R1-C4 |
| Estimating | Versioned formulations/assemblies and generic costing/material preview exist. Optional `customer_id` resolves CustomerAccount, not Party; customer-specific formulation variants are account-keyed | Generic estimating can serve prospects; no fake account/id or substitution into existing customer override fields |
| Quotation/approval | Shared Numbering/Approvals, evidence and scope infrastructure exist. Sales quotation issue, recipient readiness, quote snapshots, acceptance, domain jobs and invoices are not implemented | New Sales integrations required; infrastructure is not proof the workflows already exist |
| Financial activity | Company base currency lock uses module-registered monetary activity probes. Parties provider does not register an account probe; CustomerAccount is configuration, not evidence of trading/posting | Future money-carrying Sales records must register their probe under ADR 0081; prospect status must not evade the lock |

Evidence paths: `backend/app/Modules/Parties/Models/`, `Actions/`, `routes.php`,
`Http/Requests/{Party,CustomerAccount}/`, `Support/{PartyWriteAccess,AccountTermsAccess}.php`,
`backend/app/Modules/Accounting/Support/AccountMappingAccess.php`,
`backend/database/migrations/2026_08_20_120600_create_customer_accounts_table.php` and
`2026_08_21_090000_enforce_company_scoped_foreign_keys.php`;
`backend/app/Modules/Estimating/Http/Requests/{Formulation,MaterialPreview}/` and module providers.
No customer master maintenance screen was found; existing supplier UI is not prospect onboarding.

## What we are discussing and why it matters

A business may ask for a price before it owes us money. Identifying who receives an offer is a
commercial duty; configuring who carries its receivable balance is a financial duty. Forcing both
at quotation issue creates Finance work for enquiries that may never become orders and encourages
placeholder accounts or inappropriate grants. Allowing unreliable recipients, however, can send
an offer to the wrong entity and make later acceptance, invoicing and audit ambiguous.

The preliminary recommendation is **B: Party-backed prospect quotations**, with recipient
readiness checked before issue and financial readiness checked at a later, explicitly decided
business event. Absence of an account alone should not prohibit issue; absence of reliable identity
or required commercial information should. The bounded Party-backed issue direction is now accepted in ADR 0103; detailed gates remain open.

## Practical example

ABC Industries requests a quotation for PKR `500000.0000`. Sales finds or, with identity authority,
creates its Party and records the contact and relevant address. Sales prepares the draft; before
issue, recipient readiness and normal pricing/tax/approval/opportunity-policy checks must pass.
The issued version copies the identity/contact/address actually used. No dummy terms or receivable
account are created simply to let Sales quote.

If ABC buys, an authorised account maintainer adds CustomerAccount to that same Party with proper
financial field authority. Old offer evidence still shows the issue-time details. Exactly whether
this setup must precede acceptance, job confirmation, an advance payment or invoicing is **not
chosen here**. Adding a role does not by itself prove a later transaction is financially ready.

## Primary-source comparison

Three mature approaches, accessed 2026-10-09. Product labels do not mean our CustomerAccount.

| Product and direct sources | Observed approach | Applicability and failure mode |
|---|---|---|
| **ERPNext** — [Quotation](https://docs.frappe.io/erpnext/quotation), [Lead](https://docs.frappe.io/erpnext/lead) | Quotes can target a Lead or Customer; a Customer can be created when customer transactions are needed. Submission/amendment separates formal offers from edits | Supports quoting before customer creation. Separate Lead/Customer identities create reconciliation risk here; reuse Party instead. Documentation does not define our exact Finance gate or immutable recipient snapshot |
| **Dynamics 365 integrated prospect-to-cash** — [prospect lifecycle](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/data-entities/prospects-in-prospect-to-cash-use) | A prospect can receive a sent/activated quotation. SCM scenario converts before confirmation; integrated Sales create-order scenario converts automatically. Customer creation reuses prospect account number, with contacts/address behaviour dependent on GAB integration | Distinguishes prospect from financial account and illustrates an order-stage gate. Do not copy automatic defaults/conversion or assume order equals our acceptance/job. Address synchronisation can fail to preserve intended role without explicit setup |
| **Odoo 19** — [official quotation source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/sales_quotations/create_quotations.rst), [official Contacts source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/essentials/contacts.rst) | Quotation uses customer/contact identity; guidance requires customer and invoice/delivery address fields. Contacts hold shared business details and separate accounting settings. Confirmation turns the quote into a sales order before invoicing | Supports one contact foundation rather than a second financial identity. Address-record requirements are not proof every postal field is mandatory. Manuals do not establish a separate financial-completeness issue gate or our immutable historical evidence requirement |

Common pattern: identify the recipient before downstream commerce, but a financially onboarded
customer is not universally required to send an offer. Differences concern identity model,
address roles, conversion event and automatic account defaults. None settles our precise acceptance,
job or posting gates. Official Odoo rendered pages failed to load; its versioned official source
was read instead. Do not infer immutable history from a vendor's live contact or conversion link.

## Alternatives and recommendation

| Alternative | Benefit | Cost / security and evidence consequence |
|---|---|---|
| A — CustomerAccount before issue | Simplifies downstream account lookups and uses existing financial validation | Premature Finance onboarding; pressure for dummy values or excessive authority. Still does not prove recipient accuracy or preserve history |
| B — Party-backed quote, account optional at issue | Reuses identity, accommodates direct quotes and supplier/customer dual roles; separates commercial and Finance duties | Needs recipient readiness, governed contact/site entry, snapshots and later readiness gate. Generic pricing/tax must not depend on fabricated financial defaults |
| C — issue directly to an unqualified Lead | Fast intake and less initial entry | Unqualified enquiry may lack reliable legal recipient; later conversion can duplicate identity or change historical meaning. Conflicts with optional Lead workflow if made mandatory |

**Owner-approved B, bounded by ADR 0103.** A Lead may be a draft source, but issue should resolve a real Party and the
recipient evidence appropriate to the offer. Do not presume every draft must already have that
complete evidence; draft permissiveness is a separate R2/R5 design detail. A contact person is
not automatically the contracting party. Alternative C need not prohibit a lead-originated draft;
it is rejected as an unqualified identity basis for official issue in this recommendation.

Candidate issue checklist for discussion: identifiable contracting Party; correct contact/delivery
channel where needed; relevant address and tax/jurisdiction evidence where the offer needs it;
authority to use that identity; and all normal commercial controls. Do not mandate a registration
number, email, physical delivery site or full bill-to/ship-to setup for every industry by default.
A service quote handed over in person differs from goods delivered across borders. Exact checklist
and tax requirements remain R1-C3/R4/R5/R8; a Party row/name alone does not certify readiness.

## Event boundaries — proposed distinction, not final gates

| Event | Direction / unresolved boundary |
|---|---|
| Prepare draft | Permit incomplete work subject to normal draft policy; exact minimum fields R2/R5 |
| Issue meaningful offer | Recommend reliable Party-backed recipient, copied evidence and complete applicable commercial controls; CustomerAccount not required solely for financial setup |
| Record acceptance | Preserve customer's authoritative decision against the issued revision; financial gate unresolved in R1-C2/R6, not automatically bypassed by this proposal |
| Confirm job | Operational readiness, sites, payer/contracting identity and financial exposure need R1-C2/R7; not presumed to be the mandatory account event |
| Invoice / financial posting | Posting needs valid financial mappings, currencies, tax and receivable identity; exact CustomerAccount creation/readiness trigger remains R1-C2/Phase 6. Deposits or prepayments may occur earlier |

The current mandatory CustomerAccount fields remain mandatory. Quote-specific commercial payment
terms, if needed, are offer facts governed by Sales pricing/approval; permission to propose an
offer must not imply permission to change the account's protected payment terms. R3/R5/R9 must
settle that authorisation boundary explicitly; no terms bypass is proposed.

## Technical implications and architectural reconciliation

- Reuse ADRs 0030/0034: Party owns shared identity, contacts/sites inherit scope, financial roles
  remain independent. Conceptually reference the Party as quote recipient, with separate optional
  financial-role linkage when applicable. Exact keys/tables are R2, not decided here.
- A current master-data link supports discovery/conversion; copied issue-time name, identifiers,
  contact/address and relevant role/selection evidence preserves what was offered. Later identity
  changes/conversion must not silently rewrite it. Genuine recipient changes after issue need
  R5/R6 correction/revision rules, not ordinary CRM relinking under proposed ADR 0099.
- Enforce company and same-Party ownership for contacts/sites; selecting a supplier's existing
  Party must not grant its supplier financial fields. Server checks apply at Request and Action,
  including non-HTTP writers; capability and record scope are independent. Reuse shared audit.
- Party creation authority is not quotation authority. A salesperson without Party maintenance
  can select an eligible identity or request authorised setup; do not hardcode role names or
  automatically grant broad master-data access. Exact duty packaging belongs to R9.
- Treat soft deletion and ARCHIVED status explicitly in readiness. Recommend refusing issue to
  an unavailable/archived identity while preserving historical access; exact restoration and
  already-issued acceptance consequences remain R1-C4/R5/R6/R9.
- Concurrent archive/identity edit/conversion and issue must coordinate on stable identity and
  evidence versions. Issue atomically validates and copies the evidence; current permissions are
  rechecked, identical retries do not duplicate issue, and failure creates no success evidence.
- Future Sales financial-activity probe remains required independently of customer conversion.
  Generic prospect estimating does not enable account-specific overrides; extending those to
  Party needs separate research rather than silently redefining current `customer_id` fields.

No conflict requires replacing Party or weakening ADR 0084. The historical Sales sketch's mandatory
`customer_id` recipient is **documented-only**, so changing its future design is not a runtime
migration. It still needs a recipient ADR once the owner settles this proposal. Proposed ADR 0098
opportunity controls remain orthogonal; no Lead/Opportunity bypass is inferred.

## Unresolved decisions and smallest next discussion

**R1-C1 resolved:** eligible Party-backed issue without CustomerAccount is accepted in ADR 0103.
Exact later account/financial-readiness gates are now [C2 research](phase-4-r1-c2-financial-readiness.md),
not accepted rules. Whole R1 and Q39 remain open.

Keep separate:

- **R1-C2:** exact financial-readiness event, including acceptance, job, advance payment and posting;
  adding the account and proving financial readiness may be different checks.
- **R1-C3:** organisation/person customers, contracting entity vs contact, exact addresses/sites and
  evidence requirements; no individual-customer or team redesign chosen here.
- **R1-C4:** duplicate detection/linking, conversion, merges, archived identities and restoration.
- **R2/R4/R5/R6/R7/R8/R9:** recipient keys, tax/commercial readiness, issue/correction lifecycle,
  acceptance, job scope, delivery channels, capabilities and historical reporting.

C1/C2 bounded directions are accepted in ADRs 0103/0104; operation-specific gates remain deferred.
[Remaining R1 choices](phase-4-r1-decision-matrix.md) await consolidated owner review. No implementation.

## Related

- [R1](phase-4-r1-leads-and-relationships.md) · [Phase 4](phase-4-sales-quotations-and-handover.md)
- [Existing review](phase-4-existing-system-review.md) · [Parties](../domain/parties.md) · [Sales pipeline](../domain/sales-pipeline.md)
- [ADR 0030](../decisions/0030-unified-party-model.md) · [ADR 0034](../decisions/0034-party-child-tables-inherit-company-scope.md) · [ADR 0084](../decisions/0084-separate-financial-master-data-fields.md)
- [B3 reconciliation](phase-4-r1-b3-reconciliation.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
- [Research rules](../architecture/research-and-design.md)
