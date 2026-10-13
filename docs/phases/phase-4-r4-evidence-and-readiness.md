[← R4 decision matrix](phase-4-r4-financial-review.md)

# R4 — Primary evidence, architecture conflicts and R3 readiness

**2026-10-12 · RESEARCH / OWNER APPROVAL REQUIRED.** This supports the single
[R4 F1–F8 review](phase-4-r4-financial-review.md). No R3 implementation permission or new ADR.
Bounded comparison: Odoo, ERPNext and Microsoft Dynamics (Finance/Business Central), using primary
sources for genuinely unsettled calculations. Reuse 0025/0056 tax architecture and prior R3 evidence.
Multiple-tax/withholding/exemption semantics are researched here now; their execution can be staged.

## Primary comparison — accessed 2026-10-12

| Primary record | Observed behaviour | Convergence/divergence and translation |
|---|---|---|
| [Odoo19 tax computation, official source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/finance/accounting/taxes/tax_computation.rst) | Group/fixed/percentage and included/excluded treatment; tax-base effects and calculation sequence affect subsequent taxes. Fixed tax uses quantity. | Tax composition and inclusion are configuration, not sum of rates. Our proposed explicit component references distinguish tax-only/net-plus bases and per-line/document versus per-unit fixed scope; do not copy positional dependency assumptions. |
| [ERPNext Types in Tax Template](https://docs.frappe.io/erpnext/types-in-tax-template) | Actual, net-total percentage, previous-row amount, previous-row total and item-quantity types. Previous tax amount and tax-inclusive subtotal yield different results; inclusive flag is separate from calculation type. | Confirms typed bases and quantity need distinct meaning. Its template combines taxes and charges; our statutory components, commercial charges and withholding remain separate even when presented together. No arbitrary row index as evidence identity. |
| [ERPNext Item Tax Template](https://docs.frappe.io/erpnext/item-tax-template) | Item rates/exemptions can vary inside a transaction-wide structure; matching transaction tax rows matter. | Need per-line disposition/components and explicit no/missing outcomes. Do not assume one header code covers every line or use a GL account as universal tax-component identity. |
| [ERPNext Tax Inclusive Accounting](https://docs.frappe.io/erpnext/tax-inclusive-accounting) | Inclusive input back-calculates net rather than adds tax again; correct input interpretation and mixed Item rates matter. | Supports normalisation separate from canonical net and explicit inclusion sets. Gross-fixed versus net-fixed intent on exemption and affine graph inversion are our proposed contracts, not universal vendor policy. |
| [ERPNext Tax Withholding Category](https://docs.frappe.io/erpnext/tax-withholding-category) | Separate withholding category, effective rates, net/gross bases, thresholds and tax accounts; country configuration varies. | Keep separate payment-impact evidence under 0025/0056. Do not copy invoice deduction or cumulative transaction behaviour into quote/output tax; exact settlement/certificate histories stay Finance-owned. |
| [Dynamics Finance tax rounding](https://learn.microsoft.com/en-us/dynamics365/finance/general-ledger/sales-tax-calculation-rounding) | Line/document bases and per-code/combination rounding can produce different totals, with allocation of rounded totals back to lines. | Neither line nor document rounding is universal. Need named component/group stage, compatible grouping and conserved allocations, preserving zero/exempt/jurisdiction distinctions. We propose defaults, not statutory settings. |
| [Business Central currencies](https://learn.microsoft.com/en-us/dynamics365/business-central/finance-set-up-currencies) | Currency settings distinguish unit-amount, amount and invoice rounding; residual and exchange adjustment concepts exist. | Storage precision and currency quantum are separate. Our decimal4 invariant persists; document residuals cannot be hidden inside raw tax or commercial prices. No Finance adjustment job is introduced. |
| [ERPNext Currency Exchange](https://docs.frappe.io/erpnext/currency-exchange) | Latest matching rate on/before requested date, stale-day settings and optional provider fallback; mapped documents may carry stored context. | Reuse explicit dated evidence/age; retain0031 manual-only, no provider fallback. We propose an authorised fixed route and bounded carry-forward, not vendor lookup heuristics. |
| [Odoo19 fiscal positions, official source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/finance/accounting/taxes/fiscal_positions.rst) | Tax/account mapping depends on partner/localisation context and can be selected on documents. | Exemption/jurisdiction facts need governed profile/mapping evidence; no generic “customer is tax free” inferred from commercial pricing group. |

Common pattern: separate currency/rate/rounding and tax determination from commercial price; retain
component bases and documentary context. Differences: quantity/fixed naming, tax/charge mixtures,
row dependencies, grouping/rounding, provider fallback and withholding workflow. Project proposals
translate those patterns into existing company/Party/package/authority invariants. No researched
source establishes a country's law, appropriate percentage, stale-day count or mandatory approval.
Evidence is sufficient for this initial review; current statutory guidance is only needed when a
specific jurisdiction module/rate is selected, not to justify hardcoded rules now.

## Repository reconciliation — source inspection, no application tests

| Inspected authority/surface | Actual state and constraint | R4 consequence |
|---|---|---|
| 0012/0081; Company/CurrentCompany/Numbering CompanyClock | Central locked base currency and one IANA company time zone, dated manual rates, dual amounts and monetary4. Currency references global; rates company-scoped. | Reuse identity/configuration; no second financial time zone or per-Party base currency. Business local date and UTC observation distinct. |
| ExchangeRate model, ManageExchangeRate Action, exchange-rate Requests/Controller | Rate value casts9, company pair/date/type identity, manual source stamped; authorised edit/delete available. No reviewed Sales FX resolver. | Choose Sales SPOT requested date/route/age explicitly; capture actual values/source/pair/date/hash/observation independent of mutable master identity. Do not treat rate list as resolver or auto-fetch. |
| MoneyCast.php and composer.lock (`brick/money`0.11.2) | `Money::of(value,currency, UNNECESSARY)` without custom4 context; standard currency context uses minor-unit scale. A stored four-place amount such as0.3350 in a two-place currency cannot be assumed to load. | PR0 must define storage4/custom context plus rational/decimal intermediate arithmetic and named currency rounding before reuse. Existing cast is a technical integration risk, not permission to globally change it. |
| Purchasing BuildPurchaseOrderLineAttributes | Existing transaction amounts calculated with BigDecimal/storage4; base amounts converted individually; stock quantity scaled4 HALF_UP. PO Requests take supplied FX facts. | Existing PO behaviour is not the future Sales rate/rounding/quantity contract. A Sales adapter must not copy silent stock scaling or rely on HTTP supplied rates as approved selection. Existing Purchasing code not changed here. |
| Global UomConversionService / ItemUomConversionService;0050/0080 | Factors9, global division rounds to9; item alternate/package identity and hard increments exist; technical/commercial conversions separate. | A returned nine-place factor is supported conversion evidence, not proof of exact rational division. Define Sales representability/tolerance and refuse scope-changing rounding. No new packaging authority. |
| Tax domain/0025/0056; Party Requests and customer account migration | Multiple tax design already exists; nullable single tax-code forward reference prohibited for non-null input; no reviewed tax engine/classification/component implementation. | Do not “turn on” the placeholder. Multi-component classification/plan/evidence is required foundation. Taxable status belongs to Party/source/context, no debtor account fabrication. |
| Receivables conceptual schema, GL domain/0011 | Historical invoice-line single code/customer sketch; future Finance immutable posting/open-item/recovery/withholding design. | Finance needs component-child evidence/current Party-account model when designed. Do not implement/update that future delivery here or pretend quotations post GL. |
| R3 model/slices/0115–0117;0109/0110 | Explicit dates/FX/precision/tax gates; canonical net, complete/partial/unknown, final controls and immutable sources; typed source adapters. | R4 adds precise contracts at reserved seams, not reopen R3. Mode/permissions/state/evidence do not become implemented by research. Current material evidence cannot answer arbitrary future/historical costs. |
| 0101/0102 | Quote issue FX, forecast reporting valuation and accepted commitment are separate; explicit SPOT forecast refresh/publication, provisional trust and remaining scope gated. | No live FX view or quote price becomes trusted forecast. R5/R6/R9 partial-win/accepted-versus-remaining integration remains required. |

Money context primary technical basis: [Brick Money0.11.2 README](https://raw.githubusercontent.com/brick/money/0.11.2/README.md)
distinguishes default minor-unit context, custom scale and rational money with deferred rounding.
This confirms a shape mismatch if the reviewed cast is reused; no suite or production failure is
claimed. The remedy must be scoped/verified by an authorised coding slice, not research code edits.

## Historical initial-review conflicts — resolved architecturally by 0118–0120

1. **Four-place storage versus default Money context.** Implementation mismatch, not conflict with
   0012. Recommend explicit4 monetary context and rational/decimal calculations; don't weaken storage.
2. **“Round only at presentation” in multi-currency domain prose versus 0055/0056/R3 operational
   boundaries.** Actual line/tax/amount rounding cannot be presentation-only. F6 proposes named
   operative stages and immutable policy; approval requires domain reconciliation, no R3 ADR rewrite.
3. **Latest-prior without age versus safe quote FX.** Current general domain permits latest-prior,
   0031 notes staleness weakness; F2c proposes explicit bounded quotation policy. This is a new
   workflow refinement, not already accepted exact-date/staleness behaviour. Preserve other modules.
4. **Single tax code versus multiple components.** Historical schema shorthand cannot model core
   simultaneous taxes. 0056 already requires richer component snapshots; future template/children
   are needed. No existing real tax data is presumed or fabricated.
5. **Tax-inclusive input versus R3 canonical net/final controls.** No contradiction if inclusion
   normalises separately before net control; serious error if stored net is silently reinterpreted
   or gross/withholding used as margin. F4c proposes explicit input intent and component set.
6. **Service/manual default versus 0056's Item goods-default shorthand.** Extend default by declared
   nature explicitly if approved; do not tax service as goods merely because source is Item.
7. **Dual amounts versus sum of independently rounded components.** One pinned FX does not make
   independently rounded line conversions equal rounded document conversion. Keep0012-compliant
   converted amounts plus separately recorded residual/allocated contribution. Never conceal a
   modification of `base_amount`; Finance reconciliation is a later integration gate.

No demonstrated R3 principle needs reversal. Owner approval resolves these reserved architectural
choices in 0118–0120; the conditional recommendations above describe the initial review at5d43bea.
Implementation must still investigate the MoneyCast context and verify supported financial adapters;
a resolver shell is not a trusted price/tax engine. No Purchasing/accounting behaviour changed.

## PR0–PR6 readiness — accepted architecture versus undelivered adapters

**F1–F8 ACCEPTED; contracts SPECIFIED; R3/R4 code neither authorised nor IMPLEMENTED/VERIFIED.**
[Financial contract](../domain/quotation-financial-calculation.md) and
[R4 slice sequence](phase-4-r4-implementation-slices.md) are the current adapter handoff.

| R3 slice | Newly resolved R4 architectural dependency | Remaining gates and delivery evidence |
|---|---|---|
| PR0 kernel/model | Local date-only half-open intervals; storage4/unit4/higher precision; typed financial result and FX legs; quantity representability; component/inclusion evidence. | FR0 monetary-context investigation and adapter verification; R5 keys now specified in0122–0124/lifecycle; QL delivery and R9 gate enabled schema/surfaces. No global MoneyCast fix implied. |
| PR1 maintained/SIMPLE | One quote currency and explicit conversion allowance; exact-date/bounded prior pinned base bridge; source NET_FIXED/GROSS_FIXED intent and operative final-net settlement. | FR0/FR1/FR3/FR4 supported calculation; R9 configuration/grants; R5 operational entry/confirmation/persistence. |
| PR2 groups/categories | Effective membership/rule periods and financial currency/basis/date representation accepted; commercial audience and statutory Party tax profile distinct. | FR1/FR2 adapters; R9 membership/term confidentiality/read scope and duties. Category move does not fabricate original tax classification. |
| PR3 tiers/agreements/plans | Half-open agreement dates, explicit rate allowance, unit/package/stock representability, included components and tax-unit bases defined. | FR1–FR4; R5 current issue eligibility/adoption; R6 accepted quantity/tier/option/fixed-allocation consent. Cross-line/marginal tiers stay deferred. |
| PR4 controls | Settled tax-exclusive net, pinned comparable base/quantity, complete cost, tax/WHT excluded; mandatory UNKNOWN not PASS; raw/computed/allocated distinctions. | FR4 verified controls; R9 exact limits/duties; R5 sensitive exception subjects if later approved. No general risk bypass. |
| PR5 operational quote | Immutable commercial/tax/FX/rounding/allocations and separate payment estimate evidence specified. | FR5 + specified R5 QL0–QL6 aggregate/lifecycle/locks/approval/issue/unknown-estimate adapters; R9 guards/grants; R6 partial/alternative operations; R8 documents. |
| PR6 ADVANCED/transitions | Typed currency/quantity/net operands; supported tax-method graph and independent tax modes; history survives mode transition. | Supported FR6 methods, R5 reconciliation and R9 mode/formula duties. Architecture does not enable every advanced tax or formula. |

R5 lifecycle is now accepted0122–0124 with QL contracts; exact R6/R7 partial/consent/commitment operations remain gated. R9 applies to every public configuration/
preview/operational surface, not only final review. Forecast trust and partial-win/remaining scope
under 0101/0102 remain separate R5/R6/R9 gates; quotation issue FX is not published forecast FX.
Company max FX age, actual jurisdiction rates/exemptions and compatible rounding are governed setup,
not unresolved universal choices. R4 and R5 have no unresolved architectural owner decision within their accepted bounds; R6/R7/R9 remain dependent choices.

No application checks ran; source inspection is not runtime verification. No implementation question
was forwarded: Q44 is closed planning research; Q45 is closed by0122–0124; Q46 tracks new R6 choices. Owner-forwarded coding
questions retain their original origin/context and coding-owned verification. CRM/C2 delivery untouched.

## Related

- [R4 matrix](phase-4-r4-financial-review.md) · [Tax model and examples](phase-4-r4-tax-composition.md)
- [R3 slices](phase-4-r3-implementation-slices.md) · [Resolution model](../domain/sales-pricing-resolution.md)
- [Research method](../architecture/research-and-design.md) · [Question workflow](../architecture/implementation-question-reconciliation.md)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Phase index](README.md)
