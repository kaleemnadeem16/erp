# ERP Documentation

> **Start with [STATUS.md](STATUS.md)** — it says where the project is right now.

## Map

| Section | Purpose | Read when |
|---|---|---|
| [STATUS.md](STATUS.md) | Current phase, what's done, what's next, what's blocked | Every session, first |
| [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) | Decisions still needed, and what each one blocks | Before planning work |
| [GLOSSARY.md](GLOSSARY.md) | Domain vocabulary | A term is unfamiliar |
| [decisions/](decisions/) | ADRs — **why** things are the way they are | Before changing a decision |
| [architecture/](architecture/) | **How** the system is built | Before writing code |
| [domain/](domain/) | **What** the system models | Building a feature |
| [phases/](phases/) | Build plan, scope and acceptance criteria | Starting a phase |
| [journeys/](journeys/) | User-facing entry-to-exit workflows and training paths | Teaching or operating the app |
| `reference/` *(generated in Phase 0)* | Indexes of actions, events, routes, schema — never hand-edited | Finding where code lives |
| [templates/](templates/) | Templates for new docs | Adding a doc |

## How these docs are organised

Four kinds of document, deliberately separated because they change at different rates:

```
DECISIONS   immutable   why we chose this, and what we rejected
ARCHITECTURE  stable    how the system is built — rules and structure
DOMAIN        stable    what the system models — entities and behaviour
JOURNEYS    operational how a user completes a business outcome
STATUS      volatile    where we are today
```

Mixing these is the usual reason docs rot: a volatile progress note buried inside a stable
architecture document means nobody trusts either.

## Design principles

- **One topic per file.** You should be able to load two or three files to do a task, not the
  whole folder. Files over ~300 lines get split.
- **Dense linking.** Every file links to its index and to related topics. No orphans.
- **Rejected alternatives are recorded.** "Why not X?" is answered once, in an ADR.
- **Schema as annotated pseudo-DDL**, not migration code — readable, and doesn't drift.
- Product journeys are the canonical teaching/operating source; presentations and handouts are
  derived outputs and link back to the journey revision they came from.

## Maintenance rules

See [AGENTS.md](../AGENTS.md) at the repo root — the canonical instruction file for every agent
and contributor. The short version:

- ADRs are never edited — supersede them
- `STATUS.md` is updated in the same commit as the work
- Answered open questions become ADRs and leave `OPEN-QUESTIONS.md`
- Docs change in the same commit as the code they describe
- A workflow screen is incomplete until its journey documents actors, statuses, effects and
  exception paths; see [journeys/README.md](journeys/README.md).

`AGENTS.md` also covers **working alongside other agents** — ADR number collisions, merge
hotspots, and what not to touch. Read it if more than one agent is active on the repo.
