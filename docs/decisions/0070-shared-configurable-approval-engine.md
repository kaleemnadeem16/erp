[← Decisions](README.md)

# 0070 — One shared, configurable approval engine

- **Status:** accepted
- **Date:** 2026-09-30
- **Affects:** a new `Approvals` module; Purchasing (purchase-order submit/approve/issue); Estimating (the
  `APPROVAL_REQUIRED` variance effect); [approval-workflows.md](../architecture/approval-workflows.md);
  resolves [Q27](../OPEN-QUESTIONS.md#q27--shared-approval-workflow-contract) and the mechanism half of
  [Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy)

## Context

Approvals exist today only as module-specific code with fixed rules: high-risk stock recalculation needs
exactly two approvers, negative-stock and cutover approvals need one independent actor, and a purchase order goes
straight from draft to issued. A company cannot say "orders over 100,000 need a manager and, over 500,000, finance
as well", and the `APPROVAL_REQUIRED` material-variance effect (ADR 0054) has nothing to route to.

How mature ERPs solve it (official/vendor documentation found 2026-09-30):

| System | Model |
|---|---|
| SAP | A *release strategy* chosen from the document's values, walking a chain of release codes; the document stays blocked until every code is set ([SAP](https://community.sap.com/t5/sap-for-retail-blogs/setting-up-purchase-order-release-strategy/ba-p/13263453)) |
| Dynamics 365 | Workflows with conditions and participants (role, user or manager hierarchy), plus per-person spending and approval limits that escalate up a hierarchy ([community](https://community.dynamics.com/blogs/post/?postid=eba5c6e7-f19b-4dfd-ab2b-8ba4863255bb)) |
| NetSuite | Limits on the person: below your own limit it auto-approves, otherwise it routes up the supervisor chain until an approver's limit covers it, compared in the approver's currency; a delegate can cover absences ([NetSuite](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2395573.html)) |
| Odoo | A single amount threshold ("double validation"); add-ons add sequential/parallel levels ([Odoo apps](https://apps.odoo.com/apps/modules/15.0/sequential_approval_odv)) |
| ERPNext | A state machine with a condition per transition and an "allow self approval" flag, plus authorisation rules by amount ([ERPNext](https://docs.erpnext.com/docs/v13/user/manual/en/setting-up/workflows)) |

They converge on: rules are configuration keyed on document facts; the document stays blocked until the approvals
exist; self-approval is an explicit decision; delegation and currency comparison are handled explicitly. They split
between a *chain* model (limits on people, escalate) and a *policy-with-steps* model (if X then these steps). A mature
company ends up needing both, so the engine supports steps *and* per-person authority ceilings.

## Decision

1. **One shared engine** in an `Approvals` module. Consuming modules own their documents and state transitions; the
   engine only decides whether the required approvals exist. It never mutates another module's status.
2. **Approval mode per subject type**, explicit and company-set: `NONE` (never asked), `POLICY` (asked only when an
   active policy matches, otherwise not required) and `REQUIRED` (a matching policy must exist; with none, submission
   is refused — a missing configuration is never read as "approved"). A subject type defaults to `NONE`, so a small
   company is unchanged until it opts in.
3. **Versioned policies.** A policy is a named rule set with versions (`DRAFT` → `ACTIVE` → `SUPERSEDED`), never
   edited in place once active. A request snapshots the version it was routed under and stays explainable under it even
   after a later version is activated. Among active policies of a subject type whose conditions all match, the highest
   priority wins.
4. **Typed conditions, no code.** A condition is `fact operator value` over facts the subject type declares (for a
   purchase order: total in base currency, supplier, warehouse; for a variance: effect, cost impact). Operators are
   bounded (`>=`, `>`, `<=`, `<`, `=`, `<>`, `in`).
5. **Steps with counted, distinct approvers.** A policy has ordered stages; a stage has one or more steps that run in
   parallel; a step needs `required_approvals` (1, 2, 3 …) decisions from *distinct* people. Eligibility is a stable
   capability (`purchasing.purchase-order.approve`) plus, when the step uses authority limits, a per-person ceiling held as
   data (`approval_authorities`), never a permission such as `approve-under-10000`. Whether the submitter may satisfy a step
   is a per-step setting (default: no) rather than a universal rule.
6. **Delegation acts within the delegator's authority.** A dated delegation lets the delegate decide *as* the delegator: it
   is valid only while the delegator would themselves be eligible (permission and ceiling), and the delegate's own
   authority is never added. The slot is counted against the delegator (the *principal*) and the delegate (the
   *decider*): one person can never fill two slots of a step, either personally or by also acting for someone else.
7. **Currency evidence.** Amounts are compared in the company base currency. A request stores the original amount and
   currency, the rate, its date and source, and the converted amount, so an approval never silently changes when a rate
   moves.
8. **Changes invalidate approvals.** A request stores a hash of the routing facts. An approval is honoured only while the
   subject's *current* facts hash equals it; any decision or later use re-checks it. A changed subject invalidates the
   request and must be resubmitted and re-routed. A purchase order is additionally frozen while a request is open (edit
   requires withdrawal), so the common case never reaches the mismatch.
9. **Expiry never decides.** A step may set `expires_after_hours` and `on_expiry`: `FLAG_OVERDUE` (the default; it stays
   decidable, is marked overdue and — if configured — widens eligibility to an `escalation_permission`) or `EXPIRE_REQUEST`
   (the request ends as `EXPIRED` and must be resubmitted). Expiry never approves or rejects.
10. **Decisions are append-only facts** with the reason and an authority snapshot (permission, ceiling, delegation used).
    Rejection is terminal for the request; the submitter revises and resubmits as a new request.
11. **First consumers:** (a) an optional purchase-order submit → approve → issue path (issue asserts approval when the
    mode requires it); (b) the `APPROVAL_REQUIRED` variance effect, which becomes selectable once the engine exists: a
    preview then shows which approval a plan would need, and the plan cannot be adopted until approved (adoption itself
    is Phase 4). Recalculation, negative-stock and cutover approvals are **not** migrated now: they keep working and move
    later, one at a time.

Department-scoped approver eligibility waits on [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope);
no half-organisation model is invented here. Policy conditions may use warehouse or category facts, and authority
ceilings are per person, which covers the common cases until then.

## Consequences

- **Positive:** Any number of approvers, amount bands and parallel or sequential stages are configuration. A new document
  type adds a subject definition, not a new engine.
- **Positive:** Approvals stay valid only for what was approved: changes invalidate them, policy versions keep history
  explainable, and FX evidence is stored.
- **Negative:** More surface: policies, authorities, delegations, settings and an inbox to build and understand.
- **Negative:** Recalculation, negative-stock and cutover approvals remain separate implementations until migrated.
- **Requires:** Every consuming workflow follows the per-workflow checklist in approval-workflows.md; the engine does not
  relax it.

## Alternatives considered

**Build the first workflow only in Purchasing, then extract** — rejected: two consumers are already waiting (purchase
orders and variance), so the shared shape is known.

**Chain-only (limits on people) like NetSuite** — rejected as the only model: it cannot express "two managers and a
finance step" or parallel approvals. It is supported inside steps through authority ceilings.

**Free-form expression conditions like ERPNext** — rejected: executable rules are hard to validate, explain and version.

**Make maker/checker always mandatory** — rejected: small companies may let the creator approve low-value work; it is a
per-step setting with a safe default.

**Let expiry auto-approve or auto-reject** — rejected: a control must never resolve itself silently.

## Related

- [Approval workflows](../architecture/approval-workflows.md), [Authorisation](../architecture/authorization.md)
- [ADR 0054](0054-risk-based-material-plan-variance-controls.md), [ADR 0040](0040-control-backdated-stock-recalculation-and-corrections.md)
- [ADR 0057](0057-stable-material-resolution-capabilities-with-dynamic-assignment.md)
