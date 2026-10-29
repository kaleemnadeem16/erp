[← Tax](tax.md)

# Quotation Tax determination and BASIC calculation (FR3 T1/T2)

This disabled internal foundation resolves an explicitly authorised server-selected quotation
context and calculates one exclusive PERCENT/NET output component per selected line. It does not
implement quotation issue, create a legal tax determination for a jurisdiction, activate a user
capability, expose a route or establish a real company's tax configuration. The existing
`DetermineQuotationTax` remains the classification-only boundary.

## Authority and configuration

The Tax-owned declaration versions, coverage rules and activation evidence are company-scoped,
append-only and generation-fenced. Configuration Actions require the existing staged
`tax.configuration.manage` duty and an active current company member with unrestricted scope.
No live permission or holder is added. A declaration pins jurisdiction, half-open effective
period, BASIC calculation version, LINE_COMPONENT settlement and explicit rounding mode. Coverage
pins the source nature/classification, activated group, component and rule. Activation refuses
missing coverage, a non-BASIC/unsupported graph, multiple components, dependencies, mismatched
classification/jurisdiction or a period outside the group. Exemptions cannot be declared as a
blanket company override: a Party-specific result requires the existing protected Party profile.

A successor is adjacent to its predecessor; overlapping company/jurisdiction periods refuse.
Once activated, children cannot be added. Evidence refuses update, delete and truncate; populated
rollback refuses. The Tax generation serialises configuration writers and is part of determination
freshness. A private two-session test checks contention and committed activation freeze.

## Consumer boundary

`DetermineQuotationTaxContext` requires an active, same-company actor and a server-owned
`QuotationTaxContextAuthorizer` that proves the exact source/Party/line context. The shipped
`UnavailableQuotationTaxConsumer` refuses all requests; no live Sales consumer or client-provided
profile selector is supplied. A future consumer must establish record and Party/source eligibility,
protect confidential fields, and bind actual source identities and their freshness to the context.
The context includes company, Party, currency, line, jurisdiction, tax-estimate date and typed
classification with provenance. It is not itself an authorisation or issue token.

The resolver selects one activated covering declaration and pinned group/rule/rate. It checks
currency, exact classification, half-open dates and applicable Party profile/expiry. Missing,
ambiguous, unactivated, expired or contradictory facts remain UNKNOWN; an unsupported method is
not converted into zero. TAXABLE, ZERO_RATED, EXEMPT and NOT_APPLICABLE remain distinct. The
selected determination's internal evidence pins version identifiers and the Tax generation;
its consumer projection excludes profile evidence, grounds, rate and protected text.
`RequireCurrentQuotationTaxEvidence` re-resolves and compares the fingerprint: it is a read-only
freshness check, not a transaction lock. A future issue writer must revalidate under the agreed
source-first and Tax locks before freezing its own immutable evidence.

## Calculation and limitations

`CalculateQuotationTax` consumes the determination, a canonical currency-settled line net and
NET_FIXED intent. A versioned LINE_COMPONENT rounding policy settles exact rational percentage
Tax with raw fraction, settled amount and residual retained as internal evidence. Known zero and
unknown remain distinct; a known-zero Tax can coexist with an unknown net/gross. A document sum
adds settled lines, rejects duplicate line identities and incompatible company/currency/date/
generation contexts, and retains partial or unknown totals instead of filling gaps with zero.
Withholding and payment remain UNKNOWN; neither is inferred from an absent input. Consumer output
is not permission to issue and keeps `issue_ready` false.

Gross-fixed/inclusive, compound, fixed, per-unit and aggregate Tax, multi-component graph execution,
withholding, legal accounting and live issue remain unsupported. Sales owns later quotation
snapshots and atomic issue integration; Tax owns the evidence and the internal determination seam.
No raw profile grounds, identifiers or certificates belong in a seller-facing error, log or output.

## Related

- [Tax domain](tax.md) · [Financial calculation](quotation-financial-calculation.md)
- [R4 implementation slices](../phases/phase-4-r4-implementation-slices.md)
- [Tax module](../../backend/app/Modules/Tax/README.md)
- [Planner design #66](https://github.com/kaleemnadeem16/erp/issues/66)
