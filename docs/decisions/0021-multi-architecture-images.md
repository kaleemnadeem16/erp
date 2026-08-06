[← Decisions](README.md)

# 0021 — Multi-architecture images (amd64 + arm64)

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** Dockerfiles, CI, Phase 0

## Context

The current VMs are Oracle Ampere **ARM64**
([environments.md](../architecture/environments.md)), and
[ADR 0017](0017-deployment-topology.md) specified `linux/arm64` images.

Two things make single-architecture wrong:

1. **The development machine is `x86_64`.** Docker Desktop on Windows runs amd64 containers.
   Development and production already differ in architecture — this is a present condition, not
   a future risk.
2. **The current VMs are a build environment, not the product's home.** Once the ERP is
   sellable it will run on customer infrastructure, which is far more likely to be x86 than ARM.

An image built only for arm64 cannot run on either the development machine or a typical
customer VPS.

## Decision

Build **multi-architecture images** for `linux/amd64` and `linux/arm64`, published as a single
manifest list per tag. Docker resolves the correct variant on pull.

Three rules make this work, and all three must hold from the first Dockerfile:

1. **Never hardcode an architecture.** No `aarch64`/`x86_64` in base image tags, paths or
   download URLs. Where a build step genuinely needs it, use BuildKit's `TARGETARCH`/`TARGETPLATFORM`.
2. **Pin base images by *index* digest**, not by a per-platform digest. An index digest resolves
   per-platform; a platform digest silently locks the image to one architecture and breaks the
   other build with a confusing error.
3. **Build each architecture natively, then merge manifests.** arm64 on the self-hosted Ampere
   runner, amd64 on a GitHub-hosted x86 runner. Cross-building either under QEMU makes
   `composer install` and `npm ci` several times slower.

## Consequences

- **Positive:** The same tag runs on the dev machine, on the ARM VMs, and on a customer's x86
  VPS. No separate build path.
- **Positive:** Architecture stops being a deployment decision. Moving the product to different
  infrastructure is a hostname change.
- **Positive:** Local development uses the same image as production, so "works on my machine"
  covers a genuine case rather than a lookalike.
- **Negative:** Two builds per release instead of one. Mitigated by native builds in parallel —
  wall-clock time is roughly unchanged.
- **Negative:** Registry storage roughly doubles. Small next to the retention problem already
  identified in [ci-cd.md](../architecture/ci-cd.md#image-retention).
- **Negative:** An architecture-specific bug can reach one platform only. Low risk for PHP and
  Node, but the test suite should run on both before release.
- **Note:** layer sharing with the existing apps ([ADR 0016](0016-php-84-alignment.md)) still
  applies — it holds per-architecture, and the arm64 variant shares with them as before.

## Alternatives considered

**arm64 only** (ADR 0017 as written) — simplest, matches current infrastructure. Rejected: it
does not run on the development machine, and it assumes the product's eventual home is ARM.

**amd64 only** — matches likely customer infrastructure and the dev machine. Rejected: it would
run under emulation on the current VMs, wasting the hardware actually available.

**Build amd64 under QEMU on the ARM runner** — one runner, no coordination. Rejected: PHP
extension compilation and `npm ci` under emulation are slow enough to make CI painful, which
means it gets skipped.

## Related

- [ADR 0017](0017-deployment-topology.md) — refined, not superseded; the topology stands
- [architecture/ci-cd.md](../architecture/ci-cd.md)
- [architecture/environments.md](../architecture/environments.md)
