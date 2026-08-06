[← Decisions](README.md)

# 0016 — PHP 8.4, aligned with existing infrastructure

- **Status:** accepted — **partially amended by [0020](0020-postgresql-only.md) and [0021](0021-multi-architecture-images.md)**
- **Date:** 2026-07-29
- **Affects:** Phase 0, all backend code

> **Amended 2026-07-29.** PHP 8.4 and base-image alignment stand. Two details changed:
> **`pdo_mysql` is no longer needed** (MySQL family dropped — [0020](0020-postgresql-only.md)),
> leaving only `intl`; and the base must be pinned by **index** digest so it resolves for both
> architectures ([0021](0021-multi-architecture-images.md)), not by platform digest.

## Context

[ADR 0001](0001-laravel-backend.md) specified "PHP 8.3+" before the target infrastructure had
been surveyed.

The survey ([environments.md](../architecture/environments.md)) found both existing Laravel
applications on mainvm running **PHP 8.4** (8.4.21 and 8.4.20) on an Alpine 3.23 aarch64 base.

mainvm has limited disk (72 GB free, with ~10 GB already reclaimable from stale images). Docker
shares layers between images **only when layer digests match**, which in practice means starting
`FROM` the same base image. Using a different PHP minor version would mean a completely separate
base layer stack.

## Decision

PHP **8.4**, from the same base image as the existing applications
(`php:8.4-fpm-alpine`, aarch64), **pinned by digest**.

Additionally install `pdo_mysql` and `intl`, which neither existing image has and both of which
we need — `pdo_mysql` for the portability test matrix
([ADR 0019](0019-mariadb-portability-target.md)), `intl` for localisation and number formatting.

## Consequences

- **Positive:** Base layers are shared with invoiceflow and crm. Our unique layers are
  extensions, `vendor/` and application code — roughly 400–600 MB rather than ~1.6 GB.
- **Positive:** One PHP version to reason about across the whole host.
- **Positive:** Laravel 12 supports 8.4 fully; no compatibility concern.
- **Negative:** Sharing is silently lost if the base tag drifts out of step with the other apps.
  **Mitigation: pin by digest and treat a base image bump as a deliberate, coordinated change.**
- **Negative:** `pdo_mysql` and `intl` create a layer the other apps don't have. Everything below
  it is still shared, so the cost is only those extensions.
- **Requires:** all images build for `linux/arm64` only. Both VMs are Ampere.

## Alternatives considered

**PHP 8.3 as originally specified** — no reason beyond it being the earlier default. Rejected: it
would cost a full duplicate base layer stack on a disk-constrained host for no benefit.

**Wait for PHP 8.5** — rejected. Aligning with what is actually deployed matters more than being
current, and moving all three applications together later is a single coordinated change.

**Match the existing images exactly, no extra extensions** — would maximise sharing. Rejected:
without `pdo_mysql` the portability matrix cannot run, which would quietly void
[ADR 0019](0019-mariadb-portability-target.md).

## Related

- [ADR 0001](0001-laravel-backend.md) — the original stack decision
- [architecture/environments.md](../architecture/environments.md)
- [ADR 0017](0017-deployment-topology.md)
