[← Roadmap](README.md)

# Phase N — Name

- **Status:** not started | in progress | complete
- **Depends on:** Phase N−1
- **Blocked by:** open questions, if any

## Goal

One paragraph. What this phase makes possible that wasn't possible before. A phase should be
independently shippable — if it isn't, it's scoped wrong.

## Scope

### In

- Concrete deliverables

### Out

- Explicitly excluded, with the phase it belongs to instead

Naming what's *out* prevents scope creep more effectively than naming what's in.

## Tasks

- [ ] For each new business capability, identify and link sufficient existing research or complete
      the bounded primary-source comparison in
      [research-and-design.md](../architecture/research-and-design.md) before fixing its model
- [ ] Task, small enough to finish in one sitting
- [ ] …
- [ ] Every new/changed action and screen has an explicit authorisation decision, registered
      permissions, record/field scope and allowed/denied tests where applicable

Tick these in the same commit as the work.

## Acceptance criteria

How we know the phase is done. Observable and testable — not "auth works" but "a user can log
in, receives a token, and an unauthenticated request to a protected route returns 401".

- [ ] …

## Docs to update on completion

- [ ] `docs/STATUS.md` (the phase row only) and `docs/STATUS-LOG.md` ([ADR 0095](../decisions/0095-status-is-short-current-and-purpose-limited.md))
- [ ] Every slice passed the [feature-completion checklist](../architecture/feature-completion-checklist.md)
- [ ] Any domain doc whose schema this phase implements
- [ ] New ADRs for decisions made during the phase

## Related

- [Domain docs this phase implements](../domain/...)
- [ADRs governing this phase](../decisions/...)
