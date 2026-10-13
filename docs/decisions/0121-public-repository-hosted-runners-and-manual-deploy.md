[← Decisions](README.md)

# 0121 — Public repository: GitHub-hosted runners only, deploy by script on mainvm

- **Status:** accepted
- **Date:** 2026-10-13
- **Affects:** [ci-cd.md](../architecture/ci-cd.md), [ci-runner-setup.md](../architecture/ci-runner-setup.md), `.github/workflows/pipeline.yml`, `deploy/deploy.sh`

## Context

The private repository's GitHub Actions minutes ran low. Public repositories get unlimited
hosted-runner minutes, including native `linux/arm64` runners (`ubuntu-24.04-arm`, generally
available for public repositories since August 2025). Until now the arm64 image build, the
manifest merge and the production deploy ran on `mainvm-erp-arm64`, a self-hosted runner on the
production host ([ADR 0021](0021-multi-architecture-images.md), [ci-cd.md](../architecture/ci-cd.md)).

A self-hosted runner on a public repository is a remote-code-execution path: a fork's pull request
can edit the workflow file it runs from (including its `if:` guards and `runs-on:`), so anyone could
run code on mainvm, next to production and other applications' data. GitHub warns against it for
this reason. Public Actions logs would also expose anything a job echoes.

## Decision

1. **No self-hosted runner is attached to this repository.** Every job runs on a GitHub-hosted
   runner: amd64 images on `ubuntu-latest`, arm64 images natively on `ubuntu-24.04-arm`, the
   manifest merge on `ubuntu-latest`. No emulation.
2. **GitHub does not deploy.** `main` still builds and pushes multi-arch images to GHCR
   ([ADR 0029](0029-develop-main-branching-model.md) is unchanged: `main` is the only branch that
   builds images). The deploy is `deploy/deploy.sh`, run on mainvm by the owner after a green `main`.
3. Hostnames, the administrator email and other deployment-specific values live on mainvm
   (`/srv/apps/erp/deploy.env`), not in the repository or in Actions logs.

## Consequences

- **Positive:** unlimited CI minutes; nothing a pull request can trigger reaches mainvm; native ARM
  builds without a self-managed runner or Buildx workarounds; production deploys are a deliberate act.
- **Negative:** a merge to `main` no longer deploys by itself — someone must run the script. The
  deploy is not recorded in GitHub's deployment history.
- **Requires:** removing the runner registration from the repository before it is made public;
  "require approval for all outside contributors" for fork workflows; a deployment-branch rule on the
  `production` environment; a full-history secret scan before the visibility change.

## Alternatives considered

**Keep the self-hosted runner and harden it** (approval for fork PRs, no `pull_request` trigger,
environment reviewers) — rejected: every control is one setting away from remote code execution on
the production host; the risk is not worth the saved script.
**A separate private repository holding the runner and a `workflow_dispatch` deploy** — rejected for
now: one more repository and a runner on mainvm that GitHub can call into, for a deploy that happens
a few times a month. Can be revisited if deploy frequency grows.
**Stay private and use self-hosted runners for all jobs** — rejected: the shared 3-vCPU production
host would run the full test and e2e load.
**A public mirror of a private repository** — rejected: two repositories to keep in sync.
**QEMU-emulated arm64 on amd64 runners** — rejected: several times slower
([ci-cd.md](../architecture/ci-cd.md)) and no longer necessary.

## Related

[ADR 0021](0021-multi-architecture-images.md) · [ADR 0029](0029-develop-main-branching-model.md) ·
[ci-cd.md](../architecture/ci-cd.md) · [ci-runner-setup.md](../architecture/ci-runner-setup.md)
