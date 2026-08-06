[← Decisions](README.md)

# 0018 — One repository for docs and application

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** repository structure, CI

## Context

The question was whether documentation should live in its own repository, given that some
documentation is internal and some may eventually be customer-facing.

The concern behind it is real, but it conflates two different things: **where documentation
lives** and **who is allowed to see it**. Those have different solutions.

## Decision

**A single private repository** containing documentation and application code.

```
erp/
  AGENTS.md  CLAUDE.md  README.md
  docs/                 architecture, decisions, domain, phases
  backend/              Laravel
  frontend/             pnpm workspace — packages/core-ui, core-api; apps/*
  docker/
  .github/workflows/
```

Audience separation, when it becomes necessary, is handled by **publishing** — a CI job that
renders a designated subtree to a documentation site — not by splitting the repository.

## Consequences

- **Positive:** [Maintenance rule 4](../../AGENTS.md#rules-for-maintaining-the-docs) — "docs
  change alongside the code they describe, in the same commit" — is only *possible* in one
  repository. Across two repos, the code PR and the docs PR are separate reviews that merge at
  different times, so drift is guaranteed by construction.
- **Positive:** An ADR and the code implementing it share a commit and a history. `git log` on a
  file leads to the decision that shaped it.
- **Positive:** One CI pipeline, one set of secrets, one place to look.
- **Positive:** Matches the existing per-app repository convention (`invoiceflow`).
- **Negative:** Anyone with repository access sees all documentation. **This is the real
  constraint**, and the trigger for revisiting: if contractors or customers ever need domain
  documentation without source access, that is when a split earns its cost.
- **Negative:** Documentation changes trigger CI. Mitigated with `paths-ignore` on the build and
  test jobs.

## When two repositories would be right

Not now, but worth recording so the decision can be re-evaluated against real criteria rather
than instinct:

- **Different access control** — people who need docs but must not see source. The strongest reason.
- **Different release cadence** — public API reference published independently of releases
- **Shared across products** — documentation covering several applications, owned by none

None of these currently apply. There is no customer-facing documentation, and no reader who is
not also a contributor.

## Alternatives considered

**Separate docs repository now** — anticipates a future need. Rejected: it breaks the
same-commit rule immediately in exchange for flexibility that has no current use. The migration
later is a `git filter-repo` and an afternoon.

**Monorepo with the whole product line** (erp + invoiceflow + crm together) — shared tooling and
atomic cross-product changes. Rejected: the existing convention is one repo per application, the
products have no shared code, and it would complicate the per-repo CI and deploy that already
works.

**Docs in a GitHub wiki** — rejected. Wikis are not versioned with the code, not reviewable in
PRs, and not visible to agents working in the repository.

## Related

- [AGENTS.md](../../AGENTS.md) — the maintenance rules this enables
- [architecture/ci-cd.md](../architecture/ci-cd.md)
