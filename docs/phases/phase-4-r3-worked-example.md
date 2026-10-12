[← Revised R3 decision matrix](phase-4-r3-business-review.md)

# R3 — Integrated distributor pricing example and interaction clarifications

**2026-10-12 · ACCEPTED ARCHITECTURE EXAMPLE / ILLUSTRATIVE PRICES AND COMPANY POLICIES.**
P1–P8 are owner-approved after `c9c7875`, governed by 0115–0117 in the
[approved matrix](phase-4-r3-business-review.md). The example demonstrates those semantics;
its rates/groups/percentages/limits are not universal accepted settings. No pricing code is authorised.

## One catalogue and explicit rule configuration

A generic wholesaler/distributor sells several product categories. It uses MANAGED pricing, one
PKR maintained list and an eligible pricing date inside each rule's validity. All cases below are
at its Main selling location through the Counter channel. These optional flat contexts are enabled
for this example, not required for every company. No FX/tax assumption or R4 rule is selected;
figures are commercial net before tax, exact decimal amounts with no disputed rounding boundary.

| Product / existing category | Offered and pricing basis | Derived stock basis | Ordinary base / complete cost / mandatory price floor |
|---|---|---|---|
| A · Standard component / Components → Standard | 1 carton, rate per carton | 10 units per named carton | `1000.0000` / `400.0000` / `600.0000` per carton |
| B · Accessory / Accessories | 1 carton, rate per carton | 5 units per named carton | `500.0000` / `200.0000` / `300.0000` per carton |
| P · Premium component / Components → Premium | 1 carton, rate per carton | 10 units per named carton | `1200.0000` / `600.0000` / `1000.0000` per carton |

Each selected base explicitly permits the **Catalogue plan**, except the agreement specified below.
This plan has one adjustment slot: select one eligible discount, never add all matches. Ordinary
rate/tier benefits and customer-discount benefits are distinct here; no price includes the same
customer discount already. A mandatory 20% final-net margin also applies using complete matching
cost. All six primary cases pass both the named margin and floor controls.

| Configured rule ID / effect | Audience, applicability and allowance |
|---|---|
| BASE · Fixed maintained base | General company list, exact Item/named carton; rates above. |
| VOL-A · All-units base break | General A/carton rate is `900.0000` for offered quantity ≥ 10 cartons on one line. Tier measure = offered count of this package; price basis = per carton. Explicitly allows Catalogue plan. |
| DIST-C · 20% adjustment | Distributor group, Components SUBTREE, company-wide context, Catalogue slot; group-rule priority 100. |
| DIST-B · 10% adjustment | Distributor group, Accessories SUBTREE, company-wide context, same slot. |
| PARTNER-C · 30% adjustment | Preferred-partner group, Components SUBTREE, same slot; group-rule priority 50. Deliberately lower priority than DIST-C, despite a larger discount. |
| PERSONAL-C · 25% adjustment | Individual eligible Party, Components SUBTREE, same slot. |
| PERSONAL-A · 35% adjustment | Same eligible Party, exact A, same slot. |
| SEASON · 5% adjustment | General audience, all Items, same slot; valid seasonal dates, Counter channel AND Main location required. |
| EX-P · Exclusion | Exact P excluded from the whole Catalogue plan, including Party, group, general promotion and any discretionary adjustment in that plan. No same-family positive rule defeats it. |
| CONTRACT-A · Fixed agreement base | Explicitly selected eligible Party agreement: A/carton `850.0000`, no additional discounts. Coverage expressly permits B/P to follow ordinary sources. No quantity commitment is created by this example. |

Distributor and preferred-partner are pricing-group labels maintained once on Party. Their labels
can also describe that Party commercially; a separate “distributor” classification assignment is
not mandatory. Other descriptive labels can exist without pricing authority. Accountless eligible
prospects may receive the same governed memberships and individual terms; CustomerAccount is a
financial role, not a condition for these quotations. No group membership is inferred from free text.

## Six customer outcomes from the same catalogue

Each quotation includes one carton each of A, B and P, except the bulk case has ten cartons of A.
No salesperson adds a discretionary discount or final override in this primary table.

| Buyer case | A: winning basis/adjustment → line net | B: winning adjustment → line net | P: exclusion → line net | Quotation net / any extra discount? |
|---|---|---|---|---|
| Normal retail customer, no pricing groups | BASE `1000.0000`, SEASON 5% → `950.0000` | SEASON 5% → `475.0000` | EX-P removes Catalogue candidates → `1200.0000` | **`2625.0000`**; no extra discount. |
| Distributor-group customer, also preferred partner | BASE, DIST-C 20% → `800.0000`; PARTNER-C 30% loses on declared priority | DIST-B 10% → `450.0000` | EX-P → `1200.0000` | **`2450.0000`**; SEASON is replaced, not added. |
| Distributor with individual category discount | BASE, PERSONAL-C 25% → `750.0000` | DIST-B 10% → `450.0000` | EX-P → `1200.0000` | **`2400.0000`**; Party/category wins over group/category and general promotion. |
| Same customer with specific A exception | BASE, PERSONAL-A 35% → `650.0000` | DIST-B 10% → `450.0000` | EX-P → `1200.0000` | **`2300.0000`**; exact Item wins within Party audience; category/group benefits do not stack. |
| Distributor buys 10 cartons A, no individual override | VOL-A `900.0000`, permitted DIST-C 20% → `720.0000`/carton × 10 = `7200.0000` | DIST-B 10% → `450.0000` | EX-P → `1200.0000` | **`8850.0000`**; tier allows the one catalogue adjustment, not another volume discount. |
| Customer with category/Item exceptions selects CONTRACT-A | Agreement `850.0000` → `850.0000`; PERSONAL-A/PERSONAL-C excluded by its no-extra terms | DIST-B 10% → `450.0000`, expressly permitted uncovered line | EX-P → `1200.0000` | **`2500.0000`**; agreement controls A even though ordinary PERSONAL-A would yield `650.0000`. |

The bulk result is **not** an assertion that all tier prices allow discounts. This configured tier
explicitly allows them. If VOL-A were instead an **exclusive final rate** of `900.0000`, A would
be `9000.0000` and the same bulk quotation `10650.0000`. Choose that allowance deliberately; never
infer it from “fixed price” or attach DIST-C if it is already included in the tier's economic benefit.
If the 35% individual Item customer buys ten cartons under the discount-permitting tier, the
calculated `585.0000` per carton fails A's mandatory `600.0000` floor. Issue refuses; the system
does not quietly charge 600, remove a discount or use a cheaper/alternative rule to hide the conflict.

## Exact resolution of all overlapping rules

For the customer holding PERSONAL-C, PERSONAL-A, Distributor and preferred-partner memberships:

1. Without selected agreement, A uses BASE, then PERSONAL-A 35%; Party exact Item beats Party
   category 25%, both beat group category rules, which beat general SEASON. A net is `650.0000`.
2. If PERSONAL-A is absent, PERSONAL-C 25% wins: `750.0000`. If both personal rules are absent,
   DIST-C beats PARTNER-C by priority, not by cheapest price: `800.0000`. Without memberships,
   eligible SEASON produces `950.0000`. No positive category rule means no category discount.
3. P's EX-P exclusion filters the named plan before ranking; even a configured Party/P discount
   in that plan would be refused as a candidate. P remains `1200.0000`. An exclusion is not a
   universal prohibition on separate contract pricing or an authorised unrelated benefit plan.
4. Selected CONTRACT-A replaces A's ordinary base/adjustment path with `850.0000` and prohibits
   every extra discount. Unselected eligible contracts have no effect. Invalid/expired selected
   terms block resolution until an authorised explicit change; no automatic fallback to `650.0000`.
5. A separate selected contract explicitly covering P could set its own price despite EX-P,
   because EX-P excludes Catalogue benefits, not contract terms. Global floor/margin controls still
   apply. A genuinely universal discount ban must be a separately named mandatory control, not an
   undocumented interpretation of EX-P. Ordinary discount/manager approval cannot depart from it.

Audience precedence applies independently inside base-source and adjustment selection: Party
category can beat group exact Item. Explicit priority resolves otherwise equally specific group
candidates; it cannot promote a general campaign above personal terms. Known materially conflicting
same-rank rules are rejected at activation; runtime unresolved conflict refuses, not lowest-price
or arbitrary-ID selection. 0115 records this conflict-refusal and audience-scope refinement.

## Replacement, stacking and SIMPLE discount entry

Default slot means **replacement**, not “always an extra discount”. On A with DIST-C, a seller's
allowed 10% discretionary entry replaces the 20% automatic adjustment only after showing and
confirming the change: `1000.0000` → `900.0000`. It does not turn calculated `800.0000` into
`720.0000`. This replacement is unavailable where selected contractual terms disallow it.

If a separately configured plan permits a discretionary slot after DIST-C, 10% of running net
`800.0000` is `80.0000`, leaving `720.0000`. That is two **distinct** permitted benefits. A named
20%-then-5% plan gives `760.0000`, not additive `750.0000`. Using the same campaign/benefit as
both a base reduction and an adjustment, or in two slots, is rejected even when stacking is enabled.
A reasoned final-net override to `780.0000` remains `780.0000`; no discount runs again after it.
Every path rechecks terms, reference-based authority limits and final-net controls.

In SIMPLE there is no hidden group/promotion engine. A maintained `1000.0000` and ordinary optional
10% discount produces `900.0000`; a permitted manual base behaves similarly. Percent, fixed per-priced-
unit and fixed line amount are labelled alternatives, one entry per line. Three cartons at `1000.0000`
less `100.0000` per carton gives `2700.0000`; less `100.0000` for the line gives `2900.0000`.
In-limit routine discount records actor/time without treating it as exceptional negotiation;
above-limit requests need configured independent approval or refuse. Exact duties/limits await
contracts. An entered manual base cannot inflate the governed authority reference to evade a limit.

## Category changes and draft evidence

Configured SUBTREE rules follow current eligible ancestry at explicit resolution. A new Item in
Components inherits DIST-C/PERSONAL-C eligibility because those rules expressly cover the subtree,
not simply because it has a category. A child rule wins within the same audience before an ancestor;
a Party ancestor rule still outranks a group child rule under accepted audience-first ordering.
EXACT_CATEGORY rules never inherit to children. EX-P stays on P after a category move; a category
exclusion applies only while the Item is in its declared exact/subtree scope. Deny wins in its family.

Move A from Components to Accessories: DIST-C/PERSONAL-C no longer apply; DIST-B may apply.
PERSONAL-A remains an exact Item candidate unless it declares an additional category condition.
Moving outside all matched categories removes those category benefits; deleting membership has
similar eligibility impact. An affected saved draft keeps its saved result, is marked stale, and
must explicitly reprice/revalidate before issue. No-change confirmation is valid only when current
facts still support the terms or a separate authorised exception, not as a stale-rule bypass.
Issued quotations retain captured ancestry/rules/factors/results and never reprice from this move.
R5 will settle lifecycle/locks/approval invalidation; R6 decides acceptance changes and remaining scope.

## Quantity, context and final control checks

VOL-A measures **offered named-carton count**, independently of its **per-carton rate** and derived
**stock units**. Ten cartons = 100 stock units and qualifies; 100 loose units do not qualify for this
carton-only rule. A permitted price basis per 100 units would calculate priced quantity separately,
without changing the package-count tier. Package increments and stock divisibility are hard quantity
constraints; tier minimum only controls rate eligibility, not a Sales MOQ. No silent rounding up.

LINE_LOCAL all-units pricing gives all ten cartons the eligible 900 base, not nine at 1000 plus
one at 900. Two independent six-carton lines each use the lower eligible base; no automatic pooling.
Independent alternatives, unselected add-ons and distinct quotation families are never silently
combined. Selected-compatible-set aggregation, marginal slabs, category/cumulative volume and
partial acceptance retaining or losing a tier remain R6/R7-dependent later work. Price/benefit
allocation and logical line identity must be architecturally retained now for that work.

SEASON requires explicit Counter/Main context. A normal retail customer quoted Online gets no SEASON:
A `1000.0000`, B `500.0000`, P `1200.0000`, total `2700.0000`. Distributor rules here are company-wide
and still eligible online; context does not itself override customer hierarchy. When dimensions are
not enabled they have no effect and no mandatory hierarchy exists. When a configured rule requires
context, do not silently invent it; missing required selected context refuses until corrected.
Validate legitimate context assignment separately from company/record read/write scope at Request
and Action. Channel/location selection never grants access, and caller-supplied branch IDs cannot
establish eligibility without validation. R9 owns exact context duties; R4 owns dates/currency/FX.

Final A `650.0000` against cost `400.0000` passes 20% margin and 600 floor. A manual net `550.0000`
would pass 20% margin but fail the independent mandatory 600 floor: no automatic increase or issue.
Advisory control failures warn; mandatory below-floor/low-margin/unevaluable controls refuse issue.
If cost becomes PARTIAL/UNKNOWN, maintained base is still a valid price source, but this example's
mandatory margin becomes unevaluable and blocks issue. With no mandatory cost test, such selling
can proceed with uncertainty visible. Cost-derived arithmetic still requires complete applicable
cost; neither a known subtotal nor approval supplies missing cost.

A future separately authorised, reasoned risk decision may permit a named exceptionable mandatory
control, tied to exact quote/revision/net/cost evidence. This review recommends the seam, **no initial
bypass**. It cannot alter agreement constraints, company scope or unknown-cost calculation. Discount
entry, discount-limit approval and commercial-risk approval remain separate; verification belongs
to coding. Cost-blind sellers receive legitimate status/refusal without protected operands.

## Modes and delivery boundaries

SIMPLE supports straightforward bases and proposed bounded discretionary discounts without groups.
MANAGED activates these configured audiences/categories/tiers/contracts/promotions/plans; ADVANCED
adds typed governed complex conditions/formulas, not arbitrary scripts. Activation validates complete
configuration and authority. Downgrade first previews impacted rules, agreements, drafts and approvals;
resolve/reprice or withdraw dependent drafts under R5, and retire incompatible future configuration.
Do not silently hide active effects or remove company controls/contract obligations. If a dependency
cannot safely be resolved, downgrade refuses. Issued price evidence and authorised history remain
readable regardless of current mode. Optional UX slices can follow without replacing these seams.

P1–P8 semantics are accepted in 0115–0117; exact company rates/allowances/limits and R4–R9 gates
remain. [Resolution specification](../domain/sales-pricing-resolution.md) and
[delivery slices](phase-4-r3-implementation-slices.md) are now prepared. The designated agent owns
future implementation and verification after explicit authorisation; planning implements no code.

## Related

- [Decision matrix](phase-4-r3-business-review.md) · [Expanded evidence](phase-4-r3-expanded-research.md)
- [Phase 4 plan](phase-4-sales-quotations-and-handover.md) · [Phase index](README.md)
- [Implementation-question workflow](../architecture/implementation-question-reconciliation.md)
