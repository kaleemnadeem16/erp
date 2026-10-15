# 0144 — Version Sales unit-price precision within four-place storage

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** Sales FR4 rounding/settlement policy, quotation drafts and issue, [quotation-financial-calculation.md](../domain/quotation-financial-calculation.md), [precision.md](../domain/precision.md); refines [0120](0120-settle-quotation-rounding-and-financial-evidence.md)

## Context

[0120](0120-settle-quotation-rounding-and-financial-evidence.md) fixes the commercial unit price at four places by default and settles the final line net to the currency
quantum, while Purchasing keeps its four-place arithmetic. Companies differ: one sells whole pieces at four-decimal prices, another quotes in whole cents. Unit-price entry precision is a
legitimate business choice only inside the four-place storage limit.

## Decision

1. **Company-wide, versioned Sales unit-price entry precision**, from two to four places, **default four**. It belongs to the FR4 rounding/settlement policy version — no separate table —
   and is governed by `sales.financial.configure`. There are **no per-price-list overrides** in the first implementation.
2. **Four separate precisions are never merged:** *entry* (what a user may type for a unit price: this setting), *calculation* (exact/rational until a stated stage), *settlement* (the final line net to the currency quantum under the
   policy rounding rule, 0120) and *display* (presentation preference, [0141](0141-layer-precision-as-invariants-versioned-policy-and-presentation.md)). Storage stays decimal(19,4).
3. **Versioning.** A change creates a new effective-dated version with actor, reason and previous/new version. It applies to new work only. Activation uses the same generation lock as other FR4 policy changes.
4. **History.** An issued quotation keeps its original values, the policy version and its snapshot. **Drafts** affected by a change are marked for revalidation and are never silently rewritten;
   a draft price that no longer fits the entry precision must be corrected by the user.
5. **Not Purchasing.** Purchasing prices, base conversions and totals are unchanged ([0120](0120-settle-quotation-rounding-and-financial-evidence.md)). Nothing here extends Sales settlement to Purchasing.
6. The setting is displayed read-only wherever a unit price is shown without edit rights, with the version in force.

## Consequences

- **Positive:** a company can match its price list convention without changing storage or history.
- **Negative:** drafts can become stale after a change and need review; the setting is one more FR4 field to test.
- **Requires:** FR4 policy versions, AU0/AU1 registration of `sales.financial.configure`, demo scenario of two versions with an issued quotation keeping the old one (only when real quotation issue exists).

## Alternatives considered

**Fixed four places** — rejected: companies that quote in whole cents would carry unusable digits.
**Per price list** — rejected for now: more drift between lists and policy versions with no current demand.
**Allowing more than four places** — rejected: outside storage.
**Silently rewriting drafts** — rejected: a user must see and accept a changed price.

## Related

- [0120](0120-settle-quotation-rounding-and-financial-evidence.md) · [0141](0141-layer-precision-as-invariants-versioned-policy-and-presentation.md) · [0138](0138-authorise-sales-and-document-duties-with-explicit-scope-and-grants.md)
- [R4 slices (FR4)](../phases/phase-4-r4-implementation-slices.md) · [precision.md](../domain/precision.md) · [slices](../phases/precision-implementation-slices.md)
