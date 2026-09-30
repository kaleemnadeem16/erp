[← Docs index](../README.md)

# Product journeys

Product journeys explain how a person uses the ERP to complete a business outcome. They are the
teaching and operating layer: a reader can start at an entry point, follow screens and decisions
in order, understand each status, and know what the system changed at the end.

They sit beside the existing documentation rather than replacing it:

| Layer | Question it answers | Change rate |
|---|---|---|
| Decisions / ADRs | Why did we choose this rule? | Immutable; superseded, never rewritten |
| Architecture | How is the system structured? | Stable |
| Domain | What facts, states and invariants exist? | Stable |
| Product journeys | How does a user complete a task? | Changes with UI and workflow |
| Status | What is complete right now? | Volatile |

## Journey catalogue

| Journey | Audience | Coverage |
|---|---|---|
| [Approvals journey](approvals-journey.md) | Administrator, buyer, approver, finance | Switching approvals on, writing policies and limits, submitting and deciding a purchase order, delegation |
| [Phase 2 operational journey](phase-2-operational-journey.md) | New operator, buyer, receiver/QA operator, inventory controller | Implemented entry-to-exit flow from login and setup through purchasing, receiving, QA, stock visibility and control work |
| [Phase 3 material-planning journey](phase-3-material-planning-journey.md) | Estimator, planning lead, administrator | Implemented rules setup, Material Preview, stock proposal, what-if variance verdicts and the freshness check |
| Future Phase 4–7 journeys | To be added when each phase starts | Sales, job execution, finance, mobile and integrations |

## How to maintain journeys

1. Create one journey for one business outcome, using [templates/journey.md](../templates/journey.md).
   Do not create a screen catalogue without a user goal.
2. Put the journey in this directory and add it to this index. Every journey must link back to
   this index and end with a `Related` section.
3. Describe the implemented route, actor, prerequisites, happy path, status changes,
   irreversible actions, system effects, exception paths and end state. Use `Not yet available`
   for a planned step; never imply a future screen exists.
4. Keep business rules in the domain document and decision rationale in ADRs. Link to those
   sources instead of copying their full schema or rationale.
5. Add or update the journey in the same change as any frontend workflow change. Update its
   `Last verified` date and `Implementation status`.
6. If a new screen introduces a state, permission, consequence or exception, update the relevant
   domain/ADR first or in the same change, then update the journey. A screen is not complete until
   its user-facing flow is documented.
7. Review journeys at phase close and whenever routes or status enums change. Walk the flow in
   the running UI with realistic data and check routes, labels, permissions and outcomes against
   the code.

## What journeys can become

The Markdown journey is the canonical source. Training handouts, support articles and slide decks
may be generated from it later, but they are derived outputs and must not become a second source
of truth. A generated output should record the journey revision/date it came from.

## Related

- [Documentation map](../README.md)
- [User-interface delivery](../architecture/user-interface.md)
- [Phase 2 roadmap](../phases/phase-2-purchasing-stock.md)
- [Journey template](../templates/journey.md)
