[← Decisions](README.md)

# 0022 — Module-level code docs, generated indexes, no file-by-file map

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** all code, CI, `docs/` conventions

## Context

The existing docs cover decisions, architecture and domain. None of them describe *the code* —
so an agent or developer looking for "where does invoice numbering happen" must search.

The proposal was a document describing what each file does, so that only relevant files need
loading rather than the whole tree.

The underlying need is real: an ERP will reach several hundred backend files, and loading them
to find one is wasteful and often impossible within a context window.

But **a file-by-file map is the wrong instrument for it.** It is the highest-churn, lowest-value
document in any repository: every file added, renamed, moved or deleted invalidates a line.
It rots silently, and a stale map is *worse than no map* — it sends the reader confidently to
the wrong place, whereas no map prompts a search that works.

It also duplicates information the code already carries, in a location that cannot be validated.

## Decision

Three tiers, chosen so that each piece of information lives where it is cheapest to keep true.

### 1. Module README — hand-written, low churn

One `README.md` per `app/Modules/<Name>/`, around 30 lines, from
[templates/module-readme.md](../templates/module-readme.md):

- What this module owns, and explicitly what it does not
- Key Actions and what each is for
- Events emitted and consumed — the module's real interface
- Which [domain doc](../domain/) governs it
- Where the non-obvious logic lives, and why it is non-obvious

Modules change far more slowly than files, so this stays true with little effort. It is also the
right granularity for the question actually being asked — "where does X happen" is nearly always
answered by a module plus an Action name.

### 2. Generated indexes — cannot rot

Regenerated in CI, committed, build fails on diff. Same mechanism as
[ADR 0006](0006-generated-api-client.md).

| File | Contents | Source |
|---|---|---|
| `docs/reference/actions.md` | Every Action, its module, its signature | scan `Modules/*/Actions/` |
| `docs/reference/events.md` | Every event, emitters, listeners | scan `Events/` + `Listeners/` |
| `docs/reference/routes.md` | Every endpoint | `php artisan route:list` |
| `docs/reference/schema.md` | Tables and columns | migrations |

These are the mechanical parts of a file map — exactly the parts a human should never maintain
by hand.

### 3. File header docblocks — travel with the code

Where a file's purpose is not obvious from its path and name, a short header comment explains it.

**The rule:** if a competent developer cannot guess what a file does from its location and name,
either *rename it* or *add a header comment*. Never add a line to a central map. Renaming is
usually the better fix, and a map would have hidden the need for it.

## Consequences

- **Positive:** Answers the navigation question at a granularity that stays accurate.
- **Positive:** The mechanical parts are generated, so they cannot drift.
- **Positive:** Adding a file requires no documentation update — the module README only changes
  when the module's *responsibilities* change.
- **Positive:** Enforces naming discipline. "This file needs explaining" becomes a prompt to
  rename rather than to document.
- **Negative:** Less complete than a full map. A file that is neither obvious nor important
  enough for a docblock is findable only by search — acceptable, and `grep` handles it.
- **Negative:** Four more generated files and a CI check. Small, and it reuses machinery already
  required by [ADR 0006](0006-generated-api-client.md).
- **Requires:** a module README exists before a module's first feature merges, and
  `docs/reference/` is generated from Phase 0 — retrofitting the generators later means writing
  them against a large codebase instead of an empty one.

## Alternatives considered

**File-by-file map** — as proposed. Rejected on churn and silent rot, as above.

**Nothing — rely on grep and naming** — defensible, and much of the value comes from good naming
regardless. Rejected because cross-cutting concerns (which module owns numbering? what listens to
`JobConfirmed`?) are not answerable by filename, and those are the questions actually asked.

**Auto-generated docs from docblocks (phpDocumentor and similar)** — comprehensive and always
current. Rejected: the output is API reference, not orientation. It answers "what are this
class's methods", never "where should I start".

**A single `ARCHITECTURE.md` describing the whole codebase** — one file, easy to find. Rejected:
it grows past useful length and violates the one-topic-per-file rule that keeps context loads
small.

## Related

- [architecture/code-structure.md](../architecture/code-structure.md) — the layout this documents
- [templates/module-readme.md](../templates/module-readme.md)
- [ADR 0006](0006-generated-api-client.md) — the generate-and-check-drift pattern
