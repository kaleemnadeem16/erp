# Working on this repository

Instructions for any agent or contributor working here. This is the canonical file —
`CLAUDE.md` points to it. Keep it tool-neutral.

ERP system — formulation-based estimating, job costing and field service delivery.
Laravel API backend + React SPA frontend.

**Do not assume the project's state from this file** — it deliberately records only rules and
conventions, never progress. See [docs/STATUS.md](docs/STATUS.md) for what exists and where
implementation currently stands, before assuming anything about the current state.

---

## Read these first, every session

1. **[docs/STATUS.md](docs/STATUS.md)** — where the project is right now, what's next
2. **[docs/architecture/invariants.md](docs/architecture/invariants.md)** — rules that must never be broken

Both are short and deliberately kept that way.

## Then load only what you need

**Do not read all of `docs/`.** Every file covers exactly one topic so you can load the two or
three relevant ones without pulling in the rest.

| I'm about to… | Read |
|---|---|
| Build a feature | The matching file in [docs/domain/](docs/domain/) + the module's own `README.md` |
| Find where something lives | The module `README.md`, then `docs/reference/` *(generated in Phase 0; does not exist yet)* |
| Write a migration | [database.md](docs/architecture/database.md) + [multi-tenancy.md](docs/architecture/multi-tenancy.md) |
| Add an API endpoint | [api-conventions.md](docs/architecture/api-conventions.md) |
| Add/change any business action, endpoint or screen | [authorization.md](docs/architecture/authorization.md) — complete the checklist even when no new permission is needed |
| Add an approval or controlled transition | [approval-workflows.md](docs/architecture/approval-workflows.md) |
| Write backend code | [code-structure.md](docs/architecture/code-structure.md) |
| Start a phase | The matching file in [docs/phases/](docs/phases/) |
| Change an existing decision | The relevant ADR in [docs/decisions/](docs/decisions/) |
| Handle a per-customer difference | [extensibility.md](docs/architecture/extensibility.md) |
| Add or change demo data | [environments.md](docs/architecture/environments.md#development-demo-data) |
| Understand a term | [docs/GLOSSARY.md](docs/GLOSSARY.md) |

Start at [docs/README.md](docs/README.md) if unsure — it is the map.

---

## Rules for maintaining the docs

These matter more than the docs themselves. Docs that aren't maintained are worse than none,
because they mislead.

1. **Never edit an accepted ADR.** Decisions are immutable. To change one, write a new ADR that
   supersedes it and update the old one's `status` to `superseded by NNNN`. The record of *why
   we changed our mind* is the valuable part.

2. **Update `docs/STATUS.md` in the same commit as the work.** Not afterwards, not in a batch.
   If a phase task is finished, tick it in that commit.

3. **When an open question is answered, it becomes an ADR.** Write the ADR, remove the entry
   from `docs/OPEN-QUESTIONS.md`. Questions don't linger there once resolved.

4. **Docs change alongside the code they describe**, in the same commit. A schema change that
   doesn't touch the matching `docs/domain/` file is incomplete.

5. **One topic per file.** If a file passes ~300 lines, it covers more than one topic — split it
   and link. This is what keeps context loads small.

6. **No orphan files.** Every doc is linked from its section index and links back. Every doc
   ends with a `Related` section.

7. **Write down the alternatives you rejected.** The next person's first question is always
   "why not X?" Answer it once, in the ADR.

---

## Working alongside other agents

Multiple agents (Claude Code, Codex, and others) work in this repository. Assume you are not the
only one and that the tree may have changed since you last looked.

### Before you start

- **Re-read `docs/STATUS.md`.** Do not rely on remembered state — another agent may have moved
  the project on.
- **Check `git log` and `git status`** for work in progress you might collide with.

### ADR numbering

`docs/decisions/README.md` records the next free number. Two agents working in parallel *will*
both claim it.

- Take the number from the index and add your index row **in the same commit** as the ADR file
- If you hit a collision on merge, **renumber yours** — the earlier-committed ADR keeps the
  number. ADR numbers are identifiers, not a priority order, so renumbering is cheap
- Fix the cross-links in both directions after renumbering

### Merge hotspots

These files are edited by nearly every task. Keep changes minimal and surgical:

| File | Why |
|---|---|
| `docs/STATUS.md` | Every completed task touches it |
| `docs/decisions/README.md` | Every new ADR |
| `docs/OPEN-QUESTIONS.md` | Every answered question |

Edit only the specific rows or sections you own. Do not reformat or reorder these files as a
side effect of another change.

### Boundaries

- **Do not restructure `docs/` unilaterally.** The layout is deliberate and documented in
  [docs/README.md](docs/README.md). Propose structural changes as an ADR.
- **Do not "tidy" another agent's in-progress work.** Unfinished ≠ wrong.
- **Do not hand-edit generated files.** The `orval` output in `packages/core-api` is generated
  from the backend's OpenAPI spec — change the Laravel resource and regenerate. CI fails on
  drift.
- **Stay in scope.** If you discover a real problem outside your task, record it in
  `docs/OPEN-QUESTIONS.md` or as an ADR rather than fixing it silently.

### Report accurately

State what you actually did. If tests fail, say so and include the output. If you skipped part
of a task, say which part and why. Another agent will act on your summary — an optimistic
report costs more than an incomplete one.

---

## Conventions

- **A feature isn't done until backend, UI, permissions and docs land together** — small,
  coherent commits are fine (even one commit per vertical slice), but never a backend-only commit
  that leaves the UI thinner than what just shipped. If an endpoint gained a filter, sort field or
  include, the screen using it gains the control for it in the same pass, or the gap is written
  down as deferred rather than left silent. See
  [user-interface.md](docs/architecture/user-interface.md) ("not UI-complete merely because its
  endpoints have generated hooks") and
  [authorization.md](docs/architecture/authorization.md#per-feature-authorisation-checklist).
- New ADRs use [docs/templates/adr.md](docs/templates/adr.md), numbered sequentially
- Domain docs use [docs/templates/domain-doc.md](docs/templates/domain-doc.md)
- Module READMEs use [docs/templates/module-readme.md](docs/templates/module-readme.md) — required
  before a module's first feature merges
- Phase docs use [docs/templates/phase.md](docs/templates/phase.md), written **when the phase
  starts** — not in advance
- **Never hand-edit `docs/reference/`** — it is generated and CI fails on diff
- Schema is documented as annotated pseudo-DDL, not migration code — it stays readable and
  doesn't drift with framework versions
- Money is always `decimal(19,4)`; never use floats, not even in examples or fixtures
- British spelling in prose (labour, organisation) — match the surrounding text

## Environment notes

Full survey: [docs/architecture/environments.md](docs/architecture/environments.md). The
essentials:

- **Development is on Windows via Docker Desktop / WSL2.** The repository must live inside the
  WSL2 filesystem, not under `C:\Users\…` — cross-boundary file I/O is severely slow. See
  [deployment.md](docs/architecture/deployment.md#-windows-development).
- **Dev is x86_64, production is ARM64.** Images build for **both** `linux/amd64` and
  `linux/arm64`. **Never hardcode an architecture** in a Dockerfile — see
  [ADR 0021](docs/decisions/0021-multi-architecture-images.md).
- **PHP 8.4**, from the same index-digest-pinned Alpine base as the existing apps, so Docker
  layers are shared on a disk-constrained host. Do not bump it casually — see
  [ADR 0016](docs/decisions/0016-php-84-alignment.md).
- **PostgreSQL 16 only.** MySQL and MariaDB support was dropped along with the shared-hosting
  tier ([ADR 0020](docs/decisions/0020-postgresql-only.md)). JSONB, recursive CTEs, partial
  indexes and exclusion constraints are all available and should be used — see
  [database.md](docs/architecture/database.md).
- **Production shares infrastructure with three other applications** — Traefik, Redis and
  PostgreSQL on dbvm. Namespace Redis keys. Never point tests or migrations at production
  databases.
- **dbvm has 1 vCPU.** Index deliberately; a slow query degrades other products too.
