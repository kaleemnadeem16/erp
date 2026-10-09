[← Phase 4 planning](phase-4-sales-quotations-and-handover.md)

# R1 — Remaining identity, ownership and lifecycle evidence

- **Status:** R1 business research complete 2026-10-09; accepted ADRs 0098–0108 govern; detailed delivery gates remain.
- **Question:** what minimum identity, responsibility/access, outcome and CRM boundaries allow
  R1 to close without determining later quotation, job, accounting or reporting mechanics?
- **Synthesis and approval choices:** [consolidated matrix](phase-4-r1-decision-matrix.md).

**Final closure:** the owner approved the bounded foundation contracts; [current R1 closure](phase-4-r1-decision-matrix.md)
records accepted decisions and delivery gates. Earlier alternatives and review agendas below are
historical unless confirmed by those ADRs; they do not reopen R1 or approve detailed implementation.

## Reuse and verified project foundations

The [original R1 comparison](phase-4-r1-leads-and-relationships.md#primary-source-comparison),
[B3 reconciliation](phase-4-r1-b3-reconciliation.md), [C1](phase-4-r1-c1-prospect-quotations.md) and
[C2](phase-4-r1-c2-financial-readiness.md) sufficiently cover optional intake, direct quotes,
multiple offers, forecasts, prospect issue and operation-specific readiness. Do not repeat them.
ADRs 0100–0104 settle those bounded contracts; 0098/0099 are accepted after final U1/U2 reconciliation.

Rechecked current code/documents on this date:

| Area | Exists | Does not exist / implication |
|---|---|---|
| Party identity | `Modules/Parties/Models/Party.php`, create Request/Action require code/name; customer/supplier roles independent, company-scoped; contacts/sites inherit ownership under ADR 0034 | No organisation/person discriminator or person-specific validation; name alone does not prove entity kind. Existing organisation-oriented prose is not a second master requirement |
| Accounts and restoration | Account fields/permissions guarded; one account per company/Party; soft-delete archive, independent status flag; Party archive blocks non-deleted roles | No public restore/merge or real-world deduplication workflow. Ordinary status edit is not restoration; archived candidates need governed discovery and reuse |
| Ownership/users | Administration membership and audited suspension/reactivation (`ChangeMemberStatus`); company units/membership | No Sales owner, assignment, conversion, stage/outcome or suspension-to-Sales reassignment model |
| Access | `Support/Scope/RecordScope.php`, ADR 0089 additive unrestricted/all versus MY_UNITS union; resource binding exists for warehouses; field permissions and company guards | OWN/ASSIGNED Sales record scope, Sales unit bindings and selling-team visibility are not implemented. A filter called “my deals” is not security; unit membership alone cannot grant Sales capability |
| Commercial foundations | Estimating, monetary snapshots, shared Approvals/Numbering/audit, financial-activity probes | No implemented Sales quotations/acceptance/jobs. Historical `sales-pipeline.md`/`job-costing.md` schema/outcome text is not accepted runtime |

## Primary-source comparison — bounded additional evidence

Approximately three mature products, using official manuals and versioned official source when
rendered pages could not be read. Sources below were accessed 2026-10-09. The ERPNext version-15
schema is explicitly version-specific corroboration, not a claim every version is identical.

| Question / products | Observed behaviour | Convergence, divergence and project implication |
|---|---|---|
| Person and organisation identity — [Odoo Contacts source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/essentials/contacts.rst), [ERPNext Customer](https://docs.frappe.io/erpnext/customer), [v15 customer schema](https://raw.githubusercontent.com/frappe/erpnext/version-15/erpnext/selling/doctype/customer/customer.json) | Odoo shared contacts support Person/Company and related contacts/addresses; ERPNext distinguishes customer types, with Company/Individual/Partnership in that schema | Person customers are a mature generic need. Types do not justify separate customer/supplier identities; contact person and contracting individual must remain distinct. Exact statutory/person fields are not established |
| Conversion and matching — [Odoo conversion source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/crm/acquire_leads/convert.rst), [Dataverse duplicates](https://learn.microsoft.com/en-us/power-platform/admin/detect-duplicate-records), original Dynamics qualification research | Odoo offers create/link and similar-enquiry merging; Dataverse duplicate detection identifies candidates and allows human resolution/ignore | Reuse before create and informed candidate review converge. Vendor merging/default overwrite behaviour is not safe to import into immutable commercial history. Normalised name/email is not proof of identity; warning vs hard constraint is a project choice |
| Ownership and scope — [Dynamics owner/unit changes](https://learn.microsoft.com/en-us/power-platform/admin/update-record-owner), [Odoo teams source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/crm/pipeline/manage_sales_teams.rst), ADR 0089 research | Dynamics combines user/team ownership with security privileges and reassignment, and even permits disabled owners under documented conditions; Odoo provides team rosters/assignment rules | Responsibility, membership and rights differ. Choose active new owners here; retain historical inactive responsibility for controlled reassignment. Do not copy disabled-owner assignment or cascading rights. Team assignment documentation is not proof of record-security enforcement |
| Closure/reopen — [Dynamics closure](https://learn.microsoft.com/en-us/dynamics365/sales/close-opportunity-won-lost-sales), [Odoo lost/restore source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/crm/pipeline/lost_opportunities.rst), [ERPNext Opportunity](https://docs.frappe.io/erpnext/opportunity) | Dynamics has manual won/lost closure details/reopen and requires related quotes closed; Odoo lost reasons and restoration; ERPNext lost reasons/competitors and follow-up | Explainable closure and renewed pursuit converge. No universal abandoned/partial-win definition. Dynamics automatic category change/all-quotes-closed requirement would be unsafe to copy over independently accepted scopes and separately valid issued offers |
| Minimum CRM — [Odoo activity source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/crm/optimize/utilize_activities.rst), [ERPNext Opportunity](https://docs.frappe.io/erpnext/opportunity), existing Dynamics qualification | Follow-up tasks, assignment and loss reasons exist; Odoo also offers activity plans/chains, ERPNext automated follow-up/closure | Notes, next action and responsibility are useful without automation. Advanced sequences, reminders and auto-close are capabilities to defer, not implied by a next-action date |

These comparisons distinguish realistic alternatives; there is sufficient evidence to present a
bounded decision set. Privacy/retention, legal identity/tax requirements and receipt/posting rules
need their owning packages and current authoritative checks at implementation; no jurisdictional
rule is inferred here.

## Cash/prepaid/non-credit handover — inference, not an accepted exception

Reused C2 evidence shows customer conversion/order checks, credit holds and advances occurring at
different events. Account existence and credit clearance are separate; prepaid/cash work may have
no new unsecured credit exposure but still needs identity, payment/tax evidence and operational
approval. Therefore capability-specific treatment is a safer foundation than a universal account
at every handover rule. It does **not** establish a specific account-free job workflow today.
R6/R7 should assess exposure and readiness by operation; Phase 6 owns financial identities/money
processing. No general handover bypass or exemption is recommended for acceptance now.

## Failure modes and applicability

- Duplicate suppression by matching email can merge separate legal entities; forced new identity
  on archived reuse can duplicate history and defeat account uniqueness.
- Treating owner as access grants can disclose quotes/financial fields on assignment or suspension.
- Losing the deal on one alternative or winning it on one independent acceptance hides remaining
  scope; manual won amounts can fabricate accepted business. Stage changes cannot create commitments.
- Auto-restoring account roles, quote eligibility, forecast trust or permissions on reopening is unsafe.
- Full CRM/team engines before initial customer quoting burden smaller companies; industry-specific
  stages, approval and privacy needs should not become permanent model constraints.

Recommended safeguards/options are consolidated in the matrix; accepted ADRs, rather than this retained comparison, govern the approved contracts and no R2 or implementation work is started.

## Related

- [Decision matrix and owner choices](phase-4-r1-decision-matrix.md) · [R1](phase-4-r1-leads-and-relationships.md)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Parties](../domain/parties.md)
- [ADR 0089](../decisions/0089-organisation-units-and-record-scope.md) · [ADR 0104](../decisions/0104-separate-customer-acceptance-and-readiness.md)
- [Research standards](../architecture/research-and-design.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
