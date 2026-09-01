# Open Questions

Decisions not yet made. Each says what it blocks, so work can be sequenced around it.

**When a question is answered:** write an [ADR](decisions/), then delete the entry here.
Questions do not linger once resolved.

---

## Blocking

### Q14 — Backdated stock receipts

**Blocks:** Phase 2 valuation engine completion

Controlled source history preserves its source dates under
[ADR 0036](decisions/0036-controlled-inventory-cutover.md); it is not live backdated posting.
For **post-cutover operations**, decide whether a receipt may be valuation-effective before a
later costed movement by blocking it, forward-recalculating open history, or requiring an
authorised recalculation; the dependency-aware conflict scope; and the permitted chronological
horizon. Those are resolved below. Request/approval expiry and the final correction workflow
remain. Closed periods, immutable posted financial history, true physical/source-date retention
and auditable current-period correction are hard requirements rather than optional policy.

**Agreed so far — Q14.1 (2026-09-01):** live operation defaults to
`BLOCK_CONFLICTING_VALUATION_BACKDATE`; a company may enable
`AUTHORISED_FORWARD_RECALCULATION`, but ordinary automatic replay is not supported. Physical/source,
ERP-entry, valuation-effective and accounting dates remain distinct and truthful. A conflicting
receipt creates an impact-previewed request covering inventory valuation/layers, downstream
jobs/margins/feeds, negative shortages and open/closed-period effects; only authorised approval
permits deterministic transactional replay. Original movements/valuation entries remain and
linked adjustments are appended. Closed history is never rewritten; its financial difference
uses a later explicit correction workflow. Policy changes are elevated, reasoned and audited.
Independent review is the default, with explicitly reported self-review available only through a
separate audited policy for small companies. Execution revalidates current policy/authority,
scope, movements, valuation, periods, shortages and hard integrity constraints; stale approval
cannot execute. Migration authority remains separate under ADR 0036.

**Agreed so far — Q14.2 (2026-09-01):** replay scope follows the valuation method and actual
economic dependency. AVCO starts with affected configured pools and follows actual cross-pool
dependencies under [ADR 0039](decisions/0039-configurable-avco-valuation-pools.md); FIFO follows
affected layers and their downstream transfer/consumption graph; SPECIFIC follows the exact serial
chain; negative shortages include their compatible settlement/adjustment chain. Unrelated pools
and inventory are excluded, while transfer lineage is included when affected. Preview may
conservatively overestimate, but adjustments occur only where the economic result changes. Replay
proceeds to deterministic, method-specific convergence. Dependency detection may cross a
closed-period boundary but never mutates closed financial history, and execution revalidates its
scope and locks the affected valuation state.

**Agreed so far — Q14.3 (2026-09-01):** each company selects a live backdating horizon mode,
defaulting to `CURRENT_AND_PREVIOUS_PERIOD`; alternatives are `PERIOD_LOCK_ONLY` or
`POSTING_FLOOR` with exactly one `FIXED_DATE` or `ROLLING_FORMULA`. A rolling floor uses trusted
server time in the company accounting timezone, never a user-controlled clock, and the request
snapshots its mode/formula, trusted timestamp, timezone and calculated floor. Eligibility anchors
at valid request creation, so normal clock progression alone does not stale it; execution still
revalidates policy parameters, periods, cutover/valuation boundaries, authority, dependencies and
integrity, and material changes may stale it. The most restrictive boundary wins. There is no
per-request extension. Outside-horizon events retain physical/entry truth, use the earliest
permitted valuation-effective point and route economic differences through Q14.5. Horizon changes
are elevated, reasoned and audited; chronological eligibility remains separate from replay impact
and approval.

**Resume here — Q14.4:** finish transaction-level recalculation request/approval governance,
including request expiry and treatment of impact changes that are not already covered by stale
scope revalidation.

**Next unanswered decision — Q14.4.1 (request/approval expiry):** choose whether recalculation
requests and approvals have no expiry (A), fixed system expiry (B), company-configurable expiry
within finite system bounds (C, recommended), or no pending-request expiry but a bounded approved
execution lifetime (D). The proposed C defaults are 72 elapsed hours awaiting approval and 24
elapsed hours from approval to execution, both measured from trusted server time. Expired requests
remain immutable/auditable and never change stock; proceeding requires a new request evaluated
under current horizon, policy, period, dependency and impact state. Audited policy changes do not
silently extend existing requests. **No Q14.4.1 choice has been made yet.** After Q14.4.1, decide
which impact changes require fresh approval and whether higher-risk replay needs another approval
tier (Q14.4.2), then finalise the Q14.5 correction workflow.

Q1, Q2 and Q3 were resolved on 2026-08-10 — see
[ADR 0023](decisions/0023-retention-retainage-enabled.md),
[ADR 0024](decisions/0024-onboarding-base-currency-and-fiscal-year.md) and
[ADR 0025](decisions/0025-configurable-tax-engine.md).

---

## Non-blocking

Q6 (data import mechanism) and Q7 (permissions mechanism) resolved 2026-08-10 — see
[ADR 0027](decisions/0027-generic-column-mapping-import.md) and
[ADR 0028](decisions/0028-dynamic-namespaced-permissions.md). Q7's actual role/permission
*list* is still deferred to Phase 4, per those docs. Q9 (exchange rate source) resolved
2026-08-19 — see [ADR 0031](decisions/0031-manual-exchange-rate-entry.md). Q11 (stock valuation
method) resolved 2026-08-20 — see [ADR 0032](decisions/0032-per-item-valuation-method.md). Q12
(multi-supplier catalog) resolved 2026-08-20 — see
[ADR 0033](decisions/0033-item-suppliers-relationship.md).

### Q8 — Second customer profile
**Blocks:** Nothing yet — but shapes Phase 3 and the plugin design

The extensibility strategy in [extensibility.md](architecture/extensibility.md) warns against
over-abstracting before a real second customer exists. Knowing who that is — and how their
process actually differs — would let us validate the rate-dimension model against a real case
instead of a hypothetical one.

### Q10 — Sharing master data across a customer's own legal entities
**Blocks:** Nothing in Phase 1. Relevant once a customer wants more than one legal
entity/branch under one login.

`company_id` is the full tenant-isolation boundary
([GLOSSARY.md](GLOSSARY.md#company), [ADR 0004](decisions/0004-multi-tenancy-ready-schema.md)),
not a legal-entity subdivision within one customer. Today, "multi-entity support" means another
row in `companies`, isolated from its sibling exactly as strictly as from an unrelated tenant —
the only planned bridge is user membership across `company_id`s
([multi-tenancy.md](architecture/multi-tenancy.md)'s planned tenant-switching UI), not shared
data. There is no construct for sharing a specific record — e.g. a trading partner in
[parties.md](domain/parties.md) — between two legal entities of the same real customer without
also exposing it to unrelated tenants.

Surfaced while deciding [ADR 0030](decisions/0030-unified-party-model.md) (a global, unscoped
`parties` table was considered and rejected as the wrong fix for this). Options when it becomes
real: a parent "account" grouping above `company_id`; per-record cross-company sharing grants; or
accept duplication and reconcile via an external key. Not decided — no second legal entity
exists yet to design against.

---

## Answered

Answered questions live in [decisions/](decisions/) as ADRs. See the
[decision index](decisions/README.md) for the full list.
