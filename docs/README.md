# ERP Documentation

> **Start with [STATUS.md](STATUS.md)** — it says where the project is right now.

## Map

| Section | Purpose | Read when |
|---|---|---|
| [STATUS.md](STATUS.md) | Current state: phases and workstreams, in progress, release state, next design areas, where to resume (short by purpose, [ADR 0095](decisions/0095-status-is-short-current-and-purpose-limited.md)) | Every session, first |
| [STATUS-LOG.md](STATUS-LOG.md) | Detailed, append-only history of completed work — including rotated STATUS narratives (read only for a past change's full story) | Looking up how something was built or decided |
| [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) | Decisions still needed, and what each one blocks | Before planning work |
| [GLOSSARY.md](GLOSSARY.md) | Domain vocabulary | A term is unfamiliar |
| [decisions/](decisions/) | ADRs — **why** things are the way they are | Before changing a decision |
| [architecture/](architecture/) | **How** the system is built | Before writing code |
| [architecture/research-and-design.md](architecture/research-and-design.md) | When existing research is enough and when new ERP comparison is required | Designing a new feature or workflow |
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
- **Research is recorded once and reused.** New ERP behaviour is compared with mature products
  when no adequate project research exists; later agents follow the recorded synthesis instead of
  repeating searches or improvising a thinner feature.
- **Schema as annotated pseudo-DDL**, not migration code — readable, and doesn't drift.
- Product journeys are the canonical teaching/operating source; presentations and handouts are
  derived outputs and link back to the journey revision they came from.

## Maintenance rules

See [AGENTS.md](../AGENTS.md) at the repo root — the canonical instruction file for every agent
and contributor. The short version:

- ADRs are never edited — supersede them
- `STATUS.md` is updated in the same commit that completes or starts a workstream, and the detail goes to `STATUS-LOG.md` ([ADR 0095](decisions/0095-status-is-short-current-and-purpose-limited.md))
- Answered open questions become ADRs and leave `OPEN-QUESTIONS.md`
- Docs change in the same commit as the code they describe
- A workflow screen is incomplete until its journey documents actors, statuses, effects and
  exception paths; see [journeys/README.md](journeys/README.md).

`AGENTS.md` also covers **working alongside other agents** — ADR number collisions, merge
hotspots, and what not to touch. Read it if more than one agent is active on the repo.
