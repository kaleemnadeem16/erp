[← Decisions](README.md)

# 0129 — Demo and sandbox data live in a separate demo deployment

- **Status:** accepted (the separate deployment is **deferred** until the product nears completion; the interim rule applies now)
- **Date:** 2026-10-14
- **Affects:** `backend/database/seeders/Demo/`, `demo:seed`, [environments.md](../architecture/environments.md), [deployment.md](../architecture/deployment.md), [multi-tenancy.md](../architecture/multi-tenancy.md)

## Context

The owner wants people outside the team to try the ERP with their own accounts, create records freely, and have the
environment return to a known state when asked. Today production Company 1 doubles as the demo company (the owner's
choice during development; no other company is onboarded) and `demo:seed` is forced past its `local`/`testing` guard with
`--env=local`. Reading the seeders shows why a re-run on a shared database is unsafe: they hard-code `Company::findOrFail(1)`,
overwrite company-wide settings (name, currency, backdating and recalculation rules, location mode), and delete and rebuild
formulations and application rates. About 140 tables also carry database triggers that forbid updating or deleting posted
documents, stock moves, counts and audit records (the append-only invariants), so "reset a company by deleting its rows"
is not possible without disabling protections that real customers will rely on.

## Research basis

Compared 2026-10-14 from vendor documentation and support articles: **Xero** (a separate Demo Company that anyone can reset
at any time and that resets itself every 28 days; no bank feeds, no user invitations), **Odoo** (demo data is chosen when the
database is created and cannot be switched off later; the advice is a fresh database), **Dynamics 365 Business Central**
(sandbox environments hold the CRONUS demo company and receive no production data), **ERPNext** (a per-company "Delete
Transactions" action behind a System Manager, a password and the typed company name, irreversible, with an audit record).
Common pattern: demo data lives in a separate, clearly flagged container, and a reset only ever touches that container.
Where a product resets inside one database it can only do so because its documents are deletable; ours are not by design.

## Decision

1. **Target: a separate demo deployment** (own database on dbvm, own Compose project, own hostnames, the same images and
   releases, `DEMO_MODE=true`). A reset is "drop and recreate that database, migrate, seed", exposed as one confirmed,
   audited command (and later a settings action visible only in that deployment). It is safe because the deployment owns its
   database; the guard is the deployment flag plus a database-name check, never `APP_ENV`.
2. **Deferred.** Build it when the product is near completion. Until then production Company 1 keeps its current demo data,
   people may create records in it, and **`demo:seed` is not re-run against production**.
3. **Rules that hold from now on:** demo seeding never runs as part of a deploy; it never targets a company that is not
   explicitly designated a demo company; seeders must be create-only on re-run (no overwriting settings, no delete-and-rebuild),
   take the target company as a parameter instead of `1`, and give each reviewer their own generated credentials rather
   than one shared published default password; real customer companies are never seeded.

## Consequences

- **Positive:** real onboarding later starts in a database that holds no fake data or demo logins; a reset cannot touch a customer; no need to weaken append-only protections.
- **Negative:** until the demo deployment exists there is no reset; reviewers share Company 1 with the team's own test data; a second stack to run later (one more database, ~four containers, two hostnames).
- **Requires:** when built — a `DEMO_MODE` guard, parameterised and create-only seeders, a reset command with typed confirmation and an audit entry, a deploy-script target for the demo project, and a note on [deployment.md](../architecture/deployment.md).

## Alternatives considered

**A flagged demo company inside production, reset from a settings screen** — the append-only triggers forbid the deletes a reset needs; "retire and recreate" scheme leaves fake data and a public demo login beside real data and keeps cross-company deletion risk alive.
**Never reset (keep Company 1 as the demo)** — acceptable now; not a standard approach once real companies exist, and not what the owner asked for long term.
**Disabling the triggers for a demo company's reset** — removes the protections the invariants exist to guarantee, in a database that will hold real money and stock history.

## Related

[ADR 0128](0128-observe-with-the-existing-grafana-loki-stack.md) · [environments.md](../architecture/environments.md#development-demo-data) · [invariants.md](../architecture/invariants.md)
