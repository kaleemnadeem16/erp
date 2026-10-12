[← Architecture index](README.md)

# Reconciling implementation questions through the owner

**2026-10-12 · Documentation workflow under the owner's explicit instruction.** Use
[OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md) for unresolved decisions; this is a process/template,
not a parallel register. Implementation communication remains through the owner unless the owner
explicitly authorises direct messaging. Research does not implement or accept new business choices.

## Intake and classification

The owner forwards the coding agent's original question with a concrete example, module/slice,
code path or commit, checked contracts, impact and current verification status. Preserve the wording;
record interpretation separately. Mark **Origin: IMPLEMENTATION AGENT** even if research is delegated
back to planning. Questions discovered independently in research use **Origin: PLANNING RESEARCH**.
Do not label a research recommendation as a coding-agent question. None was forwarded with the
initial request establishing this process; Q43 is planning-originated R3 review.

Check governing ADRs, domain contract, phase spec, related questions and current code context first.

- **Already answered by accepted authority:** give a concise citation and clarification; do not
  reopen the business decision or issue a redundant ADR. Record the forwarded question, response
  and delivery status in its existing slice handoff/log using a stable `IQ-<slice>-<sequence>`
  reference. No active OPEN-QUESTIONS entry is needed. Planning supplies the owner a reviewable
  response; the coding owner records delivery in its own docs. Do not edit its in-flight handoff.
- **Unresolved business/architecture choice:** allocate the next unused repository `Q<number>`;
  insert one entry in OPEN-QUESTIONS, with origin and the fields below. Link existing owning
  research/spec rather than duplicating content. If an active Q already covers it, append distinct
  implementation origin/context under that Q; preserve the forwarded sub-reference.
- **Implementation mechanics under settled authority:** owner/coding agent resolves the mechanics;
  cite constraints and maintain delivery evidence in the owning slice, no policy Q/ADR required.
- **Outside current scope:** record the smallest relevant unresolved question and its true blocker;
  propose a separate future slice, do not silently fix or authorise it.

Before assigning an ID, recheck the shared tree. Q numbers are stable even if the owning module
changes; do not renumber closed references or use a separate implementation-only Q sequence.
IQ references identify forwarded clarification exchanges, not a second decision register.

## Active question template

```text
### Q<number> — <one concrete unresolved decision>
Origin: IMPLEMENTATION AGENT | PLANNING RESEARCH
Decision status: NEEDS_RESEARCH | READY_FOR_OWNER | AWAITING_OWNER
Delivery status: NOT_STARTED | BLOCKED_AFFECTED_OPERATION | UNAFFECTED_WORK_CONTINUES
Module/slice: <name>; owner: <role/person supplied, never guessed>
Context: <path:line, local commit, observed state/date>
Forwarded reference: <owner's message / IQ-slice-sequence, if applicable>
Original question: <preserved wording>
Business example: <simple quantities, dates, actors, expected decision>
Authority checked: <ADR/spec links, relevant provisions and remaining gap>
Consequences / blocks: <specific unsafe operation and independent work that can continue>
Options / trade-offs: <real alternatives>
Recommendation / research: <proposal, primary evidence or sufficient prior research>
Disposition needed: <existing authority clarification or exact new owner choice>
Follow-up: <response/ADR link when accepted; handoff and implementation/verification owner>
```

Open question templates may link a longer owning research record. Keep the entry brief without
omitting origin, original question/context, true blocker or exact requested choice. No new permission,
financial policy or irreversible schema choice becomes accepted merely by recommending it.

## Decision and delivery statuses are separate

| Decision state | Meaning / next action |
|---|---|
| NEEDS_RESEARCH | Gap is confirmed; compare real options before owner review. |
| READY_FOR_OWNER | Recommendation, trade-offs and dependencies are documented. |
| AWAITING_OWNER | Submitted through owner; affected new semantics remain unaccepted. |
| CLARIFIED_EXISTING_AUTHORITY | Citation answers it; no new decision/ADR. Record outside active register in existing slice history. |
| ACCEPTED_BY_OWNER | Record governing ADR for genuine new decision, then remove active question per convention. |
| DEFERRED | Still unresolved; keep a non-blocking entry naming future trigger and affected-operation restriction. |

After clarification or acceptance, the existing owning slice/handoff carries origin, question ID,
response/ADR, code commit and delivery status: **SPECIFIED**, **IMPLEMENTING**,
**IMPLEMENTED_AWAITING_VERIFICATION**, **VERIFIED**, or **DEFERRED_DELIVERY**. Verification records
actual checks/result/date/cause/owner; accepted or locally committed does not mean verified/released.
A historical anchor redirect in OPEN-QUESTIONS may preserve links, but not an active answered entry.
A closed ADR or clarification reference links to the owning follow-up; no second “closed questions”
registry is created. Planning reports required evidence without claiming the coding agent ran it.

Only the owning agent updates in-flight delivery docs. Planning documents its proposed clarification
in its own research/spec and returns it through the owner for integration. Independent approved work
continues; a question blocks only the affected dependent operation. Security, financial correctness,
tenant isolation and irreversible changes fail safely at that operation until adequate authority is
established. No broad emergency bypass or unrelated freeze follows from an independent question.

## What to forward

Send the original question and business example, module/slice, path or local commit, accepted
contracts checked, observed versus expected behaviour, affected operation and whether it is blocked,
options already considered, coding-owner recommendation, and any implementation/check evidence.
Include the actor/company/scope and quantity/UoM/currency/date where relevant. Do not send credentials
or unrestricted customer/cost data. A missing optional detail does not block independent research;
missing authority for the unsafe affected operation cannot be inferred from elapsed time.

## Related

- [Open questions](../OPEN-QUESTIONS.md) · [Research method](research-and-design.md)
- [Documentation rules](../../AGENTS.md) · [Phase 4 plan](../phases/phase-4-sales-quotations-and-handover.md)
- [Authorisation](authorization.md) · [Domain integrity](domain-integrity.md)
