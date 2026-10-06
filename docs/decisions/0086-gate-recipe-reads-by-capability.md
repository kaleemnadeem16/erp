[← Decisions](README.md)

# 0086 — Gate recipe reads behind a capability administrators grant through roles

- **Status:** accepted
- **Date:** 2026-10-06
- **Affects:** Formulation, application-rate and assembly reads; Material Preview callers; Access Administration roles; demo data

## Context

The access audit (finding A9) found that formulation list/show/resolve and application-rate list/show/resolve answered every signed-in user, and that assembly versions returned their components (item, quantity per unit of work, wastage) to everyone. Cost, rate and margin figures were already projected by capability, but the recipe itself was not: which items go into a product, in what quantity and with what wastage. For a manufacturer that is often the most sensitive information in the system, and a Receiving Clerk or Buyer has no duty that needs it.

The owner's direction is that access must stay **dynamic**: a company decides who reads what by building roles and grants in Access Administration, not through job titles hard-coded in the application.

## Decision

- Register one capability, `estimating.recipe.view`, owned by Estimating. It confers read access to recipes and nothing else: no write, no cost, no rate, no margin.
- Reading a recipe requires **any one of**: `estimating.recipe.view`, or a duty that cannot work without recipes — `estimating.formulation.manage`, `estimating.application-rate.manage`, `estimating.assembly.manage`, `estimating.material_preview.run` (whose result is the expanded recipe for a quantity of work) or `estimating.internal_cost.view` (a cost breakdown lists every component and quantity, so withholding the recipe from a cost reader protects nothing). One class, `RecipeReadAccess`, holds the rule so the surfaces cannot drift apart.
- It applies to formulation list, show and resolve; application-rate list, show and resolve; and the `components` of an assembly version wherever it is included. The assembly header, group, work unit and version metadata stay shared lookups, so pickers and screens keep working. A caller without recipe access receives the assembly without its component list, never an empty one that could be mistaken for "no components".
- The grant is a company decision. No role is created by the application and none is assigned automatically: an administrator adds `estimating.recipe.view` to a role in Access Administration, or grants it directly. Production planners, buyers who need to see compositions, or anyone else are the company's call and can change at any time without a release.
- SPA screen access mirrors the backend: the Formulations, Application Rates and Assemblies screens admit `estimating.recipe.view`; New and edit controls still require their manage capabilities.
- Demo data shows the mechanism the way an administrator would use it: a company role "Production planner" holding only the recipe capability, assigned to one persona, built through the same Actions the screens call. The Buyer and Receiving Clerk personas keep no recipe access.

## Consequences

- **Positive:** the composition of a product is no longer readable by every signed-in user; the rule is one capability, grantable and revocable per role or person, and enforced at the request boundary before any query or include runs.
- **Positive:** nothing is lost for people who already work with recipes. Maintainers, estimators running the preview and cost readers are covered by their existing capabilities, so no grant migration is required for them.
- **Negative:** a user who only had a Buyer-style role and was reading recipes by accident now needs an explicit grant. This is the intended effect; the access-inventory command shows who is affected before release.
- **Negative:** an internal-cost reader sees recipes through the breakdown by necessity. Separating them would need a breakdown that hides quantities, which defeats its purpose; this is recorded rather than hidden.
- **Requires:** `permission:register` and the administrator sync on release, as for any new capability. Record scope stays company-wide pending Q26.

## Alternatives considered

**Keep recipes open and protect only costs** — rejected: the recipe, not its price, is the sensitive asset, and a direct API read defeated any hidden screen.

**Name roles in code (for example "Estimator", "Formulator")** — rejected: it conflicts with the dynamic, company-configured access model (ADR 0062, ADR 0063) and would force a release to change who may read.

**One capability per resource (formulation.view, application-rate.view, assembly.view)** — rejected as over-splitting: all three expose the same kind of fact and are needed together by the same people. They can be split later without redesign if a company needs it.

**Project only the recipe lines and keep list/show open** — rejected for formulations and application rates, whose headers carry little value without the recipe and which no unrelated duty needs; adopted for assemblies, whose headers are widely used lookups.

## Research basis

No new market comparison was needed. [ADR 0073](0073-separate-inventory-valuation-visibility-from-stock-operations.md) and [ADR 0074](0074-authorise-recalculation-economic-reads-by-workflow-duty.md) already decide the shape applied here: read access follows the duties that need the data, separate from the capability to change it, with the rule held in one class and enforced at the request boundary. [ADR 0083](0083-layered-stock-read-capabilities-and-blind-counting.md) applies it to stock quantities. This decision applies the same shape to recipe composition; it introduces no new access method.

## Related

- [Formulations](../domain/formulations.md#access), [application rates](../domain/application-rates.md#access-to-application-rates), [assemblies](../domain/assemblies.md#assembly-access)
- [Access and sensitive-data audit](../architecture/access-security-audit.md) (A9)
- [Authorisation](../architecture/authorization.md#build-rules-every-new-feature-follows)
- [Access administration](../domain/access-administration.md)
