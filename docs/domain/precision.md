[← Domain index](README.md)

# Precision, rounding and number display

**ACCEPTED architecture** ([0141](../decisions/0141-layer-precision-as-invariants-versioned-policy-and-presentation.md),
[0142](../decisions/0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md),
[0143](../decisions/0143-maintain-currency-minor-units-as-governed-reference-data.md),
[0144](../decisions/0144-version-sales-unit-price-precision-within-four-place-storage.md), 2026-10-15).
**IMPLEMENTED today** is stated per row. **No part of the PROPOSED behaviour is delivered**; slices are in
[precision-implementation-slices.md](../phases/precision-implementation-slices.md).

## Five precisions, never one setting

| Precision | Meaning | Where decided |
|---|---|---|
| Storage | How many places a column holds | Fixed invariant (below) |
| Entry | What a person or API may submit | Fixed, or a versioned module policy (Sales unit price, Item increments) |
| Calculation | Intermediate arithmetic | Fixed: exact decimal/rational; no rounding before a named stage |
| Settlement | The amount a document carries after rounding | Currency quantum + versioned rounding policy (Sales FR4); Purchasing unchanged |
| Display | Digits shown on screen | Presentation preference only |

## Fixed invariants (read-only in Settings)

| Value | Scale | Status |
|---|---|---|
| Money (`decimal(19,4)`) | 4 | IMPLEMENTED (0012) |
| Stock-ledger and transaction quantities | 4 | IMPLEMENTED |
| Assembly/formulation quantities, resource and overhead rates | 6 | IMPLEMENTED |
| FX rates, UoM and packaging conversion factors, priced quantity | 9 | IMPLEMENTED (`exchange_rates` 19,9; recalculation tables 24,9 — a width inconsistency to normalise only by a separate decision) |
| Percentages and margins | 4 | IMPLEMENTED |
| API transport | Decimal **strings**, never floats | IMPLEMENTED |
| Rounding | Only at a named stage, mode explicit; unrepresentable → refuse | Sales kernel IMPLEMENTED; elsewhere see slices |

## Quantity contexts (0142)

| Context | Scale | Used for |
|---|---|---|
| Stock ledger | 4 | Posted stock, receipts, counts, package evidence |
| Commercial line | The destination field's scale (4 wherever a transaction column is 4) | Purchase and receipt quantities; future quotation lines once R2/R5 fix them |
| Assembly technical | 6 | Recipe and Assembly measurements; exact until a stage requires a stock quantity |
| Conversion evidence | 9 | Factors; a factor's precision is **not** the destination's |

Open: the offered Assembly quotation quantity scale (not assumed to be six; reconcile with R2/R5 first).

### Increment and conversion rules (PROPOSED; enforcement waits on the audit)
- A stock-unit increment for a four-place item: positive, ≤ 4 places, multiple of 0.0001. A per-unit or per-package increment: ≤ 4 places in its own unit, and its stock-unit equivalent representable. Package content ≤ 4 places.
- A converted quantity must be **exactly** representable at its destination or is refused (`QUANTITY_NOT_REPRESENTABLE`). Example: 7 cartons × 6.666666667 = 46.666666669 cannot be stored at four places, so it is refused rather than stored as 46.6667.
- Purchasing today (IMPLEMENTED, unchanged until enforcement): rounds the stock quantity half-up to four places silently when the item has no increment; the package kernel raises on `toScale(4)`.

## Currency (0143)
Minor units (0–4 places) are global ISO reference data, synchronised at deployment and never edited by a company. Currently only `FoundationDemoSeeder` creates currencies (PKR, USD, AED) and only the Sales kernel reads `decimal_places`.
A currency in use is detected from foreign keys; its minor unit is not changed by synchronisation.

## Sales unit-price precision (0144)
Company-wide versioned entry precision 2–4, default 4, inside the FR4 policy version; no per-price-list override; new work only; issued quotations keep their snapshot; affected drafts are revalidated, never rewritten.

## Worked examples

| Case | Result |
|---|---|
| 3 × 0.3350, currency 2 places, half-up | computed 1.0050, settled 1.01; three such lines sum to 3.03 (IMPLEMENTED, Sales kernel) |
| 1.275 L × 125.4567 | exact 159.9572925; stored on a PO line as 159.9573 (Purchasing, unchanged); a Sales line settles to the currency quantum, 159.96 at two places |
| Assembly 0.000125 kg/sqm × 3 sqm | exact 0.000375 kg in Estimating; a stock issue needs a representable quantity or an explicit rounding stage, never a silent one |
| Sales price precision 4 → 2 after quotes issued | new version for new work; issued quotes unchanged; open drafts revalidated |
| Reduce item increment finer than 0.0001 | refused; coarsening is prospective with a reason; existing balances are listed, not changed |
| Currency used in postings | its minor unit is locked |

## Change, lock and version matrix

| Change | Outcome |
|---|---|
| Display preference | Unrestricted; personal needs no duty; company default needs `company.profile.manage` |
| Sales unit-price precision / settlement rule | New effective-dated version; reason and actor; new work only |
| Item/unit/package increment | Prospective; reason; ≤ 4 places; coarsening allowed, finer refused |
| Currency minor unit | Not a company change; synchronisation never alters a used currency |
| Storage scales | ADR and migration only |
| Print precision | Pinned per template version at issue |

## Existing data and open transactions
Ledger rows, posted receipts, issued orders and issued quotations are never re-quantized. Open POs keep the quantity rule pinned when issued (the over-receipt floor currently reads the live Item increment — to be pinned). Receipt drafts are revalidated; where an issued open order genuinely cannot satisfy the rule, only a governed completion path (exact computed value, posted quantity, difference, reason, authority) may be used, and only if the audit shows affected records.

## Display and input rules (PROPOSED)
- One string-safe formatter and one decimal input in the shared UI library; no `Number()` on authoritative decimals.
- The formatter never hides a material difference: when a preference shows fewer digits than stored, the display is marked and the exact value is available.
- The input uses server-supplied scale and increment, blocks (never rounds) extra digits and emits the exact string.
- Today (IMPLEMENTED): per-screen `Number(x).toLocaleString("en-US", {min 2, max 4})`, `toFixed(4)`, fixed 2 places in the inventory overview, `type="number"` inputs, and the string-safe `printDecimal` only in the Purchase Order print model. `999999999999999.9999` displays as `1,000,000,000,000,000.00`.

## Related
- [units-of-measure.md](units-of-measure.md) · [multi-currency.md](multi-currency.md) · [quotation-financial-calculation.md](quotation-financial-calculation.md)
- [0141](../decisions/0141-layer-precision-as-invariants-versioned-policy-and-presentation.md) · [0142](../decisions/0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md) · [0143](../decisions/0143-maintain-currency-minor-units-as-governed-reference-data.md) · [0144](../decisions/0144-version-sales-unit-price-precision-within-four-place-storage.md)
- [Implementation slices](../phases/precision-implementation-slices.md) · [Production audit plan](../phases/precision-production-audit-plan.md)
