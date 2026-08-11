[← Architecture](README.md)

# CI runner setup — second self-hosted runner

**Status: done (2026-08-11).** `mainvm-erp-arm64` is registered and running as a systemd service.
This runbook is kept as a reference for anyone who needs to reproduce or troubleshoot the setup —
tracked as a prerequisite in
[phase-0-foundation.md](../phases/phase-0-foundation.md#infrastructure-prerequisites).

## Why a second runner, not an org-level one

[ci-cd.md](ci-cd.md#runners-one-per-architecture) needs a runner for `linux/arm64` builds that
isn't scoped to the existing `invoiceflow` repository. GitHub offers two ways to share a runner
across repos: an **organisation-level** runner, or a **repo-scoped** runner registered to each
repo individually.

**invoiceflow, the CRM, and this ERP repo all live under a personal GitHub account, not an
organisation** — org-level runners are an Organization/Enterprise feature and aren't available
here. So this project registers a **second, fully independent repo-scoped runner**, installed
alongside the existing one on mainvm rather than replacing or sharing it. If these repos ever move
under a GitHub organisation, the two runners could be consolidated into one org-level runner at
that point — not necessary now.

## What already exists

Per [environments.md](environments.md#mainvm--application-tier): a self-hosted runner runs as OS
user `github-runner` from `~/actions-runner` on mainvm, scoped to the `invoiceflow` repo. **Do not
touch this** — the new runner is a separate installation, separate directory, separate service.

## Setup steps

Run as the `github-runner` user on mainvm (reuse the existing user — no need for a second OS
account; the runner directory is what keeps the two installations apart).

### 1. Match the existing runner's version

```bash
cat ~/actions-runner/.runner        # shows the version in use
```

Use the same version for the new runner where possible — no functional requirement, just avoids
two versions to track.

### 2. Install into a separate directory

```bash
mkdir ~/actions-runner-erp && cd ~/actions-runner-erp

curl -o actions-runner-linux-arm64-<ver>.tar.gz -L \
  https://github.com/actions/runner/releases/download/v<ver>/actions-runner-linux-arm64-<ver>.tar.gz
tar xzf ./actions-runner-linux-arm64-<ver>.tar.gz
```

### 3. Get a repo-scoped registration token

GitHub → the ERP repo → **Settings → Actions → Runners → New self-hosted runner**. Personal
accounts only offer repo-scoped tokens — there is no org to register against.

### 4. Configure and install as a service

```bash
./config.sh --url https://github.com/<you>/erp --token <TOKEN> \
  --name mainvm-erp-arm64 --labels self-hosted,arm64,erp --work _work

sudo ./svc.sh install github-runner
sudo ./svc.sh start
```

The systemd unit name is derived from the repo + runner name, so it cannot collide with the
existing invoiceflow runner's unit as long as `--name` differs (`mainvm-erp-arm64` here).

### 5. Point the workflow at it explicitly

```yaml
runs-on: [self-hosted, arm64, erp]
```

The `erp` label is unique to this runner. Using it (not just `self-hosted, arm64`) means a
workflow typo can never schedule an ERP build on invoiceflow's runner, or vice versa — each repo
can only see its own repo-scoped runner anyway, but the explicit label keeps intent obvious in the
workflow file.

### 6. Verify

```bash
sudo ./svc.sh status        # active (running)
```

And check **Settings → Actions → Runners** on the ERP repo shows `mainvm-erp-arm64` as **Idle**.

## Resource note

Two independent runner services can now both be building at once — this one, and invoiceflow's.
Both compete with mainvm's 3 vCPU and its production containers.
[ci-cd.md](ci-cd.md#runner-constraints) already caps ERP CI to 1 concurrent job; that cap was
written assuming this exact setup and still applies. It does not, and cannot, limit invoiceflow's
runner — that's a pre-existing, separate concern.

## Related

- [ci-cd.md](ci-cd.md) — pipeline design, why two runners (one per architecture) are needed at all
- [environments.md](environments.md) — the existing invoiceflow runner, mainvm specs
- [phase-0-foundation.md](../phases/phase-0-foundation.md) — tracks this as a prerequisite
- [ADR 0021](../decisions/0021-multi-architecture-images.md) — why builds are multi-arch in the
  first place
