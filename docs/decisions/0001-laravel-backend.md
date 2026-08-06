[← Decisions](README.md)

# 0001 — Use Laravel for the backend

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** everything

## Context

An ERP with formulation-based estimating, stock, job costing and accounting. Built initially by
a very small team. Deployment must be possible on customer-controlled infrastructure, and
shared hosting should remain viable as a fallback tier ([0013](0013-docker-dev-and-production.md)).

## Decision

Laravel 12 on PHP 8.3+.

## Consequences

- **Positive:** PHP is the only mainstream backend where shared hosting is a first-class target
  — Node, Django and Go all assume control of the process manager.
- **Positive:** Mature ERP-adjacent ecosystem: `spatie/laravel-permission`,
  `spatie/laravel-activitylog`, `laravel-excel`, `brick/money`. Audit logging and RBAC are core
  ERP requirements, not add-ons.
- **Positive:** Eloquent and the migration system abstract most Postgres/MySQL differences,
  which [0003](0003-postgres-with-mysql-compatibility.md) depends on.
- **Positive:** Queues run on a plain database table when Redis isn't available.
- **Negative:** PHP's concurrency model makes long-running or streaming workloads awkward.
  Acceptable — nothing in the domain requires them.

## Alternatives considered

**NestJS / Node** — a single language across the stack is appealing, and TypeScript end-to-end
is genuinely valuable. Rejected because it forecloses shared hosting entirely, and the
accounting/ERP package ecosystem is far thinner.

**Django** — excellent ORM and admin, strong data modelling. Rejected on the same hosting
grounds, and the admin's usefulness fades quickly once the UI is a custom SPA.

**Go** — best runtime characteristics of the three. Rejected as too slow to build in for a
domain this broad, with too little off-the-shelf support for the ERP-specific pieces.

## Related

- [0002 — API-first React SPA](0002-api-first-react-spa.md)
- [0014 — Modular monolith](0014-modular-monolith.md)
- [architecture/tech-stack.md](../architecture/tech-stack.md)
