# 0163 — Govern quotation discount authority with independent reference evidence

- **Status:** accepted (owner decision pack and implementation authorisation, 2026-11-06; configuration and production activation remain separate)
- **Date:** 2026-11-06
- **Affects:** Sales AU3/AU4, quotation references, employee authority, shared Approvals opt-in contract

## Context

ADRs 0139, 0154 and 0158 distinguish financial controls, a discretionary concession and approval,
but the current quotation adapter routes only concession type/currency. Entered unit prices cannot
prove a discount denominator. The owner authorised a disabled backend foundation in issue #86,
then selected the enterprise research decision pack. Real prices and holders are not approved.

## Decision

Use independently approved, versioned references and company-configured employee authority.
The [domain specification](../domain/quotation-discount-authority.md) is the implementation contract.

1. Prefer an effective approved customer agreement, then an applicable governed project/product/
   company reference. Validate company, customer, source, measure, currency and dates. A manual
   service needs an independently approved family/project baseline. Neither an entered price nor
   a previous quotation becomes a governed price merely by being stored. Changing a reference
   requires a reason and an independent authoriser. Never fabricate a legacy baseline.
2. Bind reference evidence to the quotation family and economic source/measure; inherit it over
   revisions, replacements and splits. New scope requires a governed source. Aggregate equivalent
   sources so new line identities cannot reset an amount limit. The family is the implemented
   commercial-case anchor; it does not currently link all orders/jobs or unrelated quotations.
3. Evaluate specific discretionary input and final reduction separately, pre-tax, with precise
   arithmetic. Apply configured independent percentage and base-currency amount ceilings to lines
   and the quotation. Either breach needs higher authority. Preserve the entered currency and
   line-pinned FX date/source/ratio; missing conversion evidence refuses. PKR is the demo company's
   base currency, not a global currency constant. Document discounts are allocated exactly once.
   Include every potentially selectable optional line conservatively in maximum exposure.
4. Routine clearance needs an active, effective named-employee assignment, its company policy,
   role membership where required, and matching branch/unit scope. Job titles confer nothing;
   assignments and ceilings are never added. Missing or conflicting authority cannot clear a
   recorded approval need. Record routine clearance as authority evidence, not self-approval.
5. Every final-net override and undeclared reduction needs independent approval, even within a
   routine ceiling. Use the shared engine, exact current duties and record scope. A draft creator,
   concession entrant or requester cannot approve their exception, including an owner-level user.
   Choose the lowest qualified configured approval band; ambiguous equal-priority matches refuse.
   Existing stage/count semantics implement separately mandated sequential/parallel controls.
6. Quotation-only strict effective-date and ambiguity selection is opt-in. Other approval
   consumers keep ADR 0070 behaviour. Fingerprint references, FX, configuration, assignments and
   calculations in routing facts so relevant changes invalidate approval. Revalidate under the
   common transaction fences at decision and issue. Approval never grants permission to issue or
   bypasses mandatory floor, margin, tax or contractual checks.
7. **Expiry changes the earlier default:** the owner-selected pack replaces ADR 0158's no-automatic-
   expiry default for companies using this foundation. Configure 14 calendar days initially,
   capped by quotation validity. Count local calendar days in the company zone, store the UTC
   deadline, and refuse at/after it at decision and issue, including already-approved requests.
   Any commercial edit invalidates immediately. No timeout approval, automatic delegation or
   escalation; no implicit substitute eligibility.
8. Start OFF; SHADOW records findings while every existing protection still applies. ENFORCE
   requires an explicit company activation by an authorised holder after owner sign-off. Disabling
   retains all history and restores no permission or missing approval. Issued history is untouched;
   open drafts and pending approvals must prove current evidence before enforcement can clear them.
9. All new duties remain STAGED and excluded from automatic grants. UI is a backend contract
   handoff. Demo data is create-only, Company 1, explicitly fictitious, with no duty activation or
   grants. No migration or seeder runs on the owner's development database for this task.

## Consequences

- **Positive:** one governed denominator, exact dual-currency evidence, configurable simple or
  multi-stage authority, and no hidden weakening of existing safeguards.
- **Negative:** existing unverified drafts cannot acquire routine clearance; conservative optional
  exposure can require review even when the customer will select less.
- **Requires:** immutable database evidence, tenant-safe keys, direct Action and HTTP guards,
  exact-intent receipts, concurrent-writer tests, and [0164](0164-order-discount-authority-locks-before-quotation-and-approval-records.md).

## Alternatives considered

**Use entered/last prices** — permits reference resets. **Hardcode demo bands or roles** — cannot
represent another company's authority. **Reuse mandatory financial controls as employee limits** —
confuses a non-bypassable safeguard with a review threshold. **A second Sales approval engine** —
duplicates stage, eligibility and audit semantics. **Change every consumer's tie selection** —
unrelated behavioural expansion. **Count every historical revision's whole amount again** — double-
counts mutually replacing offers; preserve economic lineage and current exposure instead.

## Research basis

Reuse the owner's 2026-11-06 research decision pack and the preserved R3/R9 comparisons in
[0116](0116-govern-sales-discounts-and-final-net-controls.md),
[0139](0139-bind-sales-authority-limits-approvals-and-execution-to-exact-evidence.md) and
[R9 review](../phases/phase-4-r9-authority-reporting-review.md). Managed prices and discretionary
adjustments are separate; conditional policies and employee ceilings complement each other.
The pack compares SAP, Business Central, Salesforce CPQ, ERPNext, NetSuite, Odoo, Zoho and
QuickBooks; its anti-splitting, independent approval and shadow safeguards are owner decisions,
not claims that each vendor implements them. No vendor-specific implementation is copied.

## Related

- [Domain specification](../domain/quotation-discount-authority.md)
- [Lock order](0164-order-discount-authority-locks-before-quotation-and-approval-records.md)
- [Approvals integration log](../phases/phase-4-approvals-integration-log.md)
- [Decision index](README.md)
