# 0044 — Keep STATUS.md inside a fixed context budget with an append-only status log

- **Status:** superseded by [0095](0095-status-is-short-current-and-purpose-limited.md) (2026-10-08) — the fixed row/line budget is replaced by purpose limits; the append-only STATUS-LOG stays
- **Date:** 2026-09-03
- **Affects:** docs/STATUS.md, docs/STATUS-LOG.md, every agent session; code-structure.md's
  file-size rule

## Context

`docs/STATUS.md` is the first file every agent loads, every session — AGENTS.md mandates it.
By 2026-09-03 its "Done" table had grown to ~40 KB of essay-length rows, violating its own
"keep this file under one screen" footer. The cost is not cosmetic: the file is paid for in
context tokens at the start of *every* session, for *every* agent, forever, and most of its
content is narrative that the phase docs already own (or should). The same growth pattern had
reached code: `PostInventoryMovement` hit 872 lines before being split.

## Decision

1. `STATUS.md` keeps only a compressed header, a **Recently done** table of at most 8
   single-line rows (bold keyword, ≤2 sentences, link to the doc that owns the detail, date),
   Next/Blocked/Roadmap, and its own update rules. Target: under ~90 lines.
2. `STATUS-LOG.md` is the append-only archive: rows rotated out of STATUS.md move to its
   bottom verbatim. It is never loaded routinely and never rewritten.
3. Narrative detail goes to the owning phase/domain doc **first**, in the same commit as the
   work; the STATUS row links to it. A row that can't be one line is a sign the detail hasn't
   been written into its proper home yet.
4. The docs' ~300-line split rule now has a code counterpart in
   [code-structure.md](../architecture/code-structure.md#file-size-and-splitting): a class
   outgrowing ~300 lines splits by responsibility behind its existing public boundary.

## Consequences

- **Positive:** every session starts materially cheaper; STATUS.md is readable at a glance
  again; history is still complete and greppable in the log; phase docs stay the true home of
  detail instead of drifting behind a duplicate narrative.
- **Negative:** finding the full story of an old change now takes one extra hop (STATUS-LOG.md
  or the phase doc); rotation is a small manual chore on each update.
- **Requires:** the update rules embedded in STATUS.md's footer; discipline that rotation
  happens in the same commit that adds the ninth row.

## Alternatives considered

**Leave STATUS.md as-is** — rejected: the load cost recurs every session and grows without
bound; the file had already broken its own rule.

**Delete history outright and rely on git log** — rejected: `git log` doesn't survive doc
reorganisation well, isn't linkable from other docs, and the narrative rows carry decisions and
gotchas worth keeping greppable.

**Split STATUS.md per phase** — rejected: agents would load several files or miss context; the
phase docs already exist for per-phase detail.

## Related

- [STATUS.md](../STATUS.md)
- [STATUS-LOG.md](../STATUS-LOG.md)
- [code-structure.md](../architecture/code-structure.md#file-size-and-splitting)
- [ADR 0022](0022-code-documentation-strategy.md) — the documentation strategy this extends
