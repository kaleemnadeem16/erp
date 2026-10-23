# Tax

Owns the FR2a company-scoped statutory configuration foundation, separate from commercial pricing.
Governed by [Tax](../../../../docs/domain/tax.md), ADRs 0056/0119 and the accepted AU0/AU1 rollout.

## Owns

- Stable component identities; append-only component, rate, rule and group versions; group membership and dependency versions.
- Typed Item goods/service, Assembly and explicit manual classification resolution.
- Generation-fenced successor creation, complete graph definition and validated activation; atomic idempotency receipts.

## Does not own

Party profiles/exemptions (FR2b), source-master classification persistence/adapters, quotation snapshots or Sales integration,
composition/inclusive normalisation/withholding (FR3), FR4 settlement, Finance postings, jurisdiction law or public setup.

## Key actions

| Action | Purpose |
|---|---|
| `CreateTaxComponent` / `CreateTaxVersionSuccessor` | Create stable identity and first/adjacent successor versions; require expected generation and operation key. |
| `DefineTaxGroupGraph` | Append one complete graph of pinned rule versions and named predecessors. |
| `ActivateTaxGroup` | Under the generation fence validate company, periods, methods, mode, scope, currency and acyclicity, then append activation. |
| `DetermineQuotationTax` | Pure classification-only support result; statutory disposition stays UNKNOWN and no amount is returned. |

## Authorisation and rollout

`tax.configuration.manage` is **STAGED**, `tax.profile.manage` remains **SPECIFIED** for FR2b. The provider registers this manifest
with the registry but its active vocabulary is empty: no permission row, grant, route, controller, job, command or UI is added.
All four configuration Actions check the manifest, actor company, capability and company-wide scope before replay.
Handmade permission rows cannot enable them. Hypothetical ACTIVE manifests exist only in tests. Activation needs separately
reviewed holder recipes, supported public projections and Request guards. No automatic ordinary administrator or seller grants.

## Integrity and limitations

Every version is immutable from creation (stronger than immutable-on-use). Intervals are half-open and cannot overlap within
company/code; an exact successor advances the number and begins at its predecessor's bounded end. An open-ended version cannot
be shortened or replaced by this slice; a governed close/supersede transition needs a later contract. Component version codes match
their stable identity. Rate/rule series may use independent named codes. Dependency versions inherit the parent group's interval;
a successor group receives new members/edges, never mutates the old graph.

All database inserts share the per-company `tax_generations` row lock and advance generation; Action receipts replay before stale
checks, after current authorisation. Activation repeats validation in a database trigger; activated graphs reject additional edges
or members. UPDATE, DELETE and TRUNCATE of evidence refuse. Rollback refuses any populated Tax evidence table. Fixed rates carry
currency/amount4 and register a company financial-activity probe. No base-converted amount is claimed by a configuration rate.

Graph validation supports structurally valid percent/fixed-line/fixed-document/per-unit definitions, not their calculation.
NET cannot have predecessors; TAX_AMOUNT/NET_PLUS_COMPONENTS require them. Document-to-line dependencies and mismatched assessment
classification/jurisdiction/nature or currency refuse. Named dependency reachability uses bounded finite-set UNION, not display order.
No configured group is automatically used. A known source classification returns SUPPORTED **only for classification**;
missing facts return UNKNOWN, ambiguous BOTH nature returns UNSUPPORTED. Party exemptions, effective group selection and all
monetary results remain unknown/unimplemented. Callers must not interpret classification support as executable tax support.

## Events

None emitted or consumed. No integration adapter is delivered.

## Verification applicability

Database/Action tests cover isolation, denied/staged/limited actors, raw constraints, immutability, replay/conflict/staleness,
graph refusals and concurrent connection fencing. Pure tests cover classification defaults and truthful support. Populated rollback
refusal is tested directly; the wrapper checks empty-schema forward/rollback/reapply. HTTP, grants, browser/mobile/accessibility,
exports, API Resources and a live demo seeder are inapplicable: this foundation intentionally has no entry point or enabled duty.
Illustrative fixtures are tests, never installed company tax law. Full-suite and integration/browser verification belong to CI/coordinator.

## Related

- [Financial contract](../../../../docs/domain/quotation-financial-calculation.md)
- [R4 slices](../../../../docs/phases/phase-4-r4-implementation-slices.md)
- [Rollout manifest](../../../../docs/phases/phase-4-au0-au1-rollout-manifest.md)
