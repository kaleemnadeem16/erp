[← Sales pricing](sales-pricing.md)

# SIMPLE/BASIC financial calculation prerequisite (M-PRFR1)

**2026-10-27 · internal calculation only.** Owner-authorised milestone on [queue #40](https://github.com/kaleemnadeem16/erp/issues/40#issuecomment-6441193975),
covering a bounded B2/B3 calculation subset for a future B6 consumer. Governed by accepted
[0115](../decisions/0115-resolve-sales-pricing-by-explicit-eligibility-and-commercial-intent.md),
[0116](../decisions/0116-govern-sales-discounts-and-final-net-controls.md),
[0118](../decisions/0118-pin-quotation-pricing-dates-and-company-base-fx.md),
[0119](../decisions/0119-determine-versioned-quotation-tax-components.md) and
[0120](../decisions/0120-settle-quotation-rounding-and-financial-evidence.md).
This is neither full B2/B3/B6 delivery nor operational issue readiness.

## Reuse and contract

`CalculateSimplePrice` uses existing RoundingPolicy entry precision and effective period,
Arithmetic source-quantity ceiling, exact Brick rationals, SettlementRule and SettlementEvidence.
Its SimplePriceInput names company/currency, stable logical line, selected source/term reference,
pricing date, optional unit price, positive quantity and exactly one adjustment. The caller must
already prove source-specific quantity representability through PR0/Quantity contracts: the
arithmetic ceiling does not make a nine-place manual or ledger quantity valid.

The adjustment is NONE, PERCENT, PER_PRICED_UNIT or PER_LINE. Percent is a non-negative plain
decimal through 100 at four places via the existing DiscretionaryInput control contract; amount
adjustments reuse its declared Line/PerUnit basis and decimal(19,4). Tax rates retain their separate
nine-place precision. Base is exact unit
price × quantity; deduct the one benefit on its declared basis, then settle the net once. No
clamping, stacking, implicit best-price selection, benefit already embedded in a rate, final
exception or replacement confirmation is modelled. Unknown price returns UNKNOWN; explicit zero
and a full discount return known zero. Existing PR0 candidate/source resolution is unchanged.

The owning workflow must prove that its selected rate does not already include this benefit and
that the actor is permitted to enter this exact adjustment within effective limits. Constructing
an input grants no permission and does not prove such commercial eligibility. Do not directly
bind these input objects to an HTTP body or reuse them as approval/issue tokens.

Tax owns `BasicTaxInput` and `CalculateBasicTax`. They are pure, use no Sales money classes, and
accept already-determined server facts; they do not read profile evidence, select a jurisdiction,
activate a group or supply a statutory determination. Only one excluded output PERCENT/NET
component per selected line is supported. The rate contract matches existing Tax percentage
storage (0–100, nine places). TAXABLE requires a positive rate; ZERO_RATED requires explicit zero;
EXEMPT and NOT_APPLICABLE return known zero. Every known disposition requires opaque component
and determination references. UNKNOWN yields no raw amount. Required references preserve
provenance but arbitrary strings are not validated owner/profile evidence.

Method/base/inclusion values outside this subset refuse TAX_CALCULATION_UNSUPPORTED, never fall
back to percentage net. Negative scope refuses. There is no inclusive/gross-fixed inversion,
compound graph, fixed/document/per-unit component, aggregate tax rounding or withholding method.
The old DetermineQuotationTax remains classification-only and cannot produce these facts by
pretending classification support determines a tax disposition.

## Document composition and evidence

`CalculateQuotationFinancials` accepts 1–1000 explicitly selected QuotationFinancialLine values
and the pinned RoundingPolicy/SettlementRule. Each line is counted once by stable logical key;
company, currency, date and policy must agree. The calling R5 workflow owns selection of base,
optional or choose-one scope; passing every alternative or explanatory child is invalid use.

The composer settles each line's net, computes tax on that settled discounted net, settles its
single component and sums net + collected tax for gross. Document totals sum settled lines,
without rounding a raw header. Thus three lines of 0.05 with 10% line tax produce 0.03 total tax;
the unsupported aggregate policy would have produced 0.02. Three lines of quantity3×0.3350
produce net3.03, never a re-rounded raw3.02. The raw fractions, settlement residuals, component
references and policy versions remain separate internal evidence.

The result uses existing FinancialResult certainty: COMPLETE carries an amount, PARTIAL only
the known contribution, UNKNOWN no amount. A known net with unknown tax retains its known net
and unknown gross; one unknown line makes totals partial without converting the missing part to
zero. Withholding and estimated payment remain explicitly UNKNOWN, even when gross is complete.
No withholding is assumed absent merely because the input contains no withholding rule.

`toInternalArray()` and `fingerprint()` are server-only: protected tax references and rate/source
facts must never pass through a generic Resource, error, log or debug projection. Evidence is
sorted by stable line key, independent of display order. The fingerprint binds the full inputs,
policy and settlement quantum; it is a deterministic calculation identity, not durable current
source freshness or authority. The result has private properties and no automatic JSON payload.
`consumerSafe()` exposes selected line monetary results/totals and generic blockers without tax
references/rates or internal fingerprints. Its caller still needs record and commercial-field
permission; the method name supplies no authority.

## Issue integration remains gated

Both result projections always say issue_ready=false. Complete arithmetic is necessary but
insufficient. A future guarded PR5/FR5/QL3 integration must:

- Resolve current legitimate Party/source scope and source-specific quantities; resolve genuine
  Tax classification/profile/group/rate/disposition evidence on the tax-estimate date. The pure
  inputs have no expiry/effective-date validation for that evidence.
- Prove pricing eligibility, exact adjustment authority/limits and confirmation where replacing
  an automatic benefit; use current applicable FX and rounding policy on their declared dates.
- Evaluate current final-net controls with comparable certainty, legitimate cost-blind evidence,
  mandatory unknown refusal and exact approval subjects. Empty controls are not an approval.
- Apply #45/QA1–QA3 record policy on Actions and replay, QA4 reviewed holders, source/security
  locks/generations, exact claim scope and all other independent lifecycle guards.
- Adopt/freeze candidate and issue evidence inside the real QL3 transaction with numbering,
  required customer snapshot/manifest binding and rollback/retry proof. Do not create parallel
  financial snapshots or a pricing-owned issue endpoint.

No existing quotation Action, Request, Resource, route, manifest, grant, persisted price/profile
or database schema changes in this milestone. No current preview starts reporting this result;
its conservative unknown tax/issue blockers remain unchanged. FX, costs, activation, UI, Finance,
PDF rendering and real baseline/holder deployment remain with their separate owners/contracts.

## Verification

Focused pure tests exercise three discount bases, zero/missing values, precision/overflow,
unsupported tax inputs, raw versus settled values, mixed rates/dispositions, partial totals,
company/currency/policy mismatch, duplicate logical keys, consumer projection and fingerprints.
Run owning Sales/Tax regressions, existing FX/rounding/allocation/controls tests, applicable
architecture ratchets, Pint, PHPStan and codegen --check before handoff. Exact-head CI and owner
APPROVE are separate evidence; no migration-cycle or browser journey is implied by pure code.

## Related

- [Financial model and pipeline](quotation-financial-calculation.md)
- [Pricing resolution](sales-pricing-resolution.md) · [Tax](tax.md)
- [Consolidated B2/B3/B6 handoff](../phases/phase-4-implementation-handoff.md)
- [Sales module](../../backend/app/Modules/Sales/README.md) · [Tax module](../../backend/app/Modules/Tax/README.md)
