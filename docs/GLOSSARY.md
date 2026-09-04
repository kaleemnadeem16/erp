# Glossary

Domain vocabulary. Several of these terms are used in a specific technical sense that differs
from everyday usage — check here before assuming.

---

## Estimating & costing

**Assembly**
A sellable unit of work, priced per unit of a work measure (per sqm, per metre, per hour). Its
cost is built up from components of different kinds. Construction estimating calls this a
*composite rate*. This is the central abstraction of the system.
→ [domain/assemblies.md](domain/assemblies.md)

**Assembly component**
One cost element of an assembly, classified by `kind`: MATERIAL, LABOUR, PLANT, SUBCONTRACT or
OVERHEAD. This four-way split is the standard estimating breakdown.

**Formulation**
A recipe or bill of materials producing **one base unit of an output item** from input items.
"To make 1 litre of Shade #4471: 0.12 kg pigment + 0.40 L binder + …". Versioned and immutable
once used.
→ [domain/formulations.md](domain/formulations.md)

**Application rate**
How much of an item is consumed per **one unit of work**, varying by context. "1 litre covers
12 sqm on smooth plaster." Distinct from a UoM conversion because it depends on business
context, not arithmetic.
→ [domain/application-rates.md](domain/application-rates.md)

**Rate dimension**
A company-defined axis along which application rates vary — *Surface Type*, *Thickness*,
*Crop*, *Soiling Level*. **Deliberately not a core concept**: core code never knows what a
surface type is. This is the main mechanism for supporting different industries.

**Work measure / work UoM**
The unit an assembly is sold by — sqm of wall, metres of pipe, hours of labour. Distinct from
the stock UoM of the materials consumed.

**Cost roll-up**
Computing an assembly's cost per work unit by summing its components down through application
rates and formulations to stock cost.

**Margin vs markup**
Not interchangeable. `cost × (1 + 0.20)` = 120; `cost ÷ (1 − 0.20)` = 125. Stored explicitly as
`margin_type` because conflating them is a real money bug.

**Snapshot**
The fully resolved cost breakdown copied into a document when it is issued. Historical
documents are **never** recomputed from current master data.
→ [architecture/invariants.md](architecture/invariants.md)

---

## Inventory

**AVCO**
Weighted average cost. The chosen valuation method — ingredients are physically drawn from
common stock, so FIFO layer tracking would be fiction.
→ [decisions/0007-avco-valuation.md](decisions/0007-avco-valuation.md)

**Stock layer**
The record of one goods receipt: quantity, unit cost, currency and date. Retained for
traceability even though AVCO doesn't strictly require it.

**Stock move**
Any movement of stock between locations, with a polymorphic reference to its source document
(goods receipt, material issue, adjustment, transfer).

**Wastage**
Material lost in the process, expressed as a percentage uplift on the required quantity.

**Backdating horizon**
The earliest valuation-effective date a company allows a backdated stock event to reach without
being routed through recalculation-request review. Three modes: current + previous open period
(default), period-lock-only, or a fixed/rolling posting floor.
→ [decisions/0040-control-backdated-stock-recalculation-and-corrections.md](decisions/0040-control-backdated-stock-recalculation-and-corrections.md), [domain/stock-recalculation.md](domain/stock-recalculation.md)

**Posting floor**
A backdating horizon mode: one company-configured cutoff date, either fixed or rolling (N days
before trusted server time in the company's accounting timezone). Distinct from a period boundary
— it ignores accounting-period open/closed state entirely.
→ [decisions/0040-control-backdated-stock-recalculation-and-corrections.md](decisions/0040-control-backdated-stock-recalculation-and-corrections.md)

---

## Sales & delivery

**Scope item**
A quotation line. Not a product — a described unit of work: *"Exterior wall, north elevation,
250 sqm, Shade #4471, 2 coats."*

**Revision**
A version of a quotation. Customers negotiate, and superseded revisions must remain readable.

**Job**
A confirmed quotation. Carries the estimate snapshot forward and becomes the anchor for actual
costs.

**Work order**
A production instruction — mix the shade. Consumes stock and produces a finished batch.

**Variance**
Estimated cost versus actual cost, by component kind. The main reason to build job costing.
→ [domain/job-costing.md](domain/job-costing.md)

---

## Finance

**Open-item**
An AR model where each invoice is tracked individually and payments are allocated to specific
invoices. Contrasted with *balance-forward*, which only keeps a running total and makes aging
impossible.
→ [domain/receivables.md](domain/receivables.md)

**Allocation**
Linking a receipt to the specific invoice(s) it pays. Partial allocations are supported;
unallocated amounts are advances held on account.

**Progress billing**
Invoicing a percentage or quantity of a job line as work proceeds, rather than once at
completion.

**Retention / retainage**
A percentage withheld by the customer until a defect liability period ends. Invoiced but not
yet due. Set per contract, defaults to 0%.
→ [decisions/0023-retention-retainage-enabled.md](decisions/0023-retention-retainage-enabled.md)

**Tax code**
A company-defined, effective-dated rate + jurisdiction + goods/services applicability. No tax
regime is hardcoded — a finance-director-level role configures tax codes as data.
→ [domain/tax.md](domain/tax.md)

**Realised FX gain/loss**
The difference arising when an invoice raised at one exchange rate is settled at another. Posts
to the P&L at settlement.
→ [domain/multi-currency.md](domain/multi-currency.md)

**Transaction / base / display currency**
Three distinct concepts. *Transaction* is what the document was raised in. *Base* is the
company's reporting currency, used by the GL. *Display* is a UI preference only and is never
stored on a document.

---

## Architecture

**Company**
The tenant boundary. `company_id` exists on every business table from the first migration even
though the system is currently single-organisation.
→ [architecture/multi-tenancy.md](architecture/multi-tenancy.md)

**Plugin**
A Composer package holding one customer's specific behaviour, layered over stable core. The
alternative to forking the application per customer.
→ [architecture/extensibility.md](architecture/extensibility.md)

**Variation ladder**
The ordered set of mechanisms for handling per-customer difference: configuration → company
master data → custom fields → plugin → fork. Always use the cheapest rung that works.

**Custom field**
A field added at runtime by a company without a migration or deploy. Defined in
`custom_field_definitions`, stored in a `custom_fields` JSON column.

**Action**
A single-purpose business operation class (`CreateQuotation`, `PostJournalEntry`). Business
logic lives here, not in controllers.
→ [architecture/code-structure.md](architecture/code-structure.md)
