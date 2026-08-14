[← Decisions](README.md)

# 0029 — `develop`/`main` branching model, deploy gated on `main` only

- **Status:** accepted
- **Date:** 2026-08-14
- **Affects:** [ci-cd.md](../architecture/ci-cd.md), all future feature work

## Context

CI/CD isn't built yet ([ci-cd.md](../architecture/ci-cd.md) is a design, not a working pipeline),
but the pipeline diagram already assumed a single branch: `main branch → deploy to mainvm` on
every push. That doesn't survive contact with real feature work.

Once features are being built, commits need to reach GitHub for backup/sync well before they're
deployable — a feature might be half-implemented, or the module it belongs to isn't finished yet.
Pushing to GitHub and deploying to mainvm are different acts and need to be triggerable
independently. A commit landing on the default branch must not automatically mean "this is now
live."

## Decision

Two long-lived branches, matching standard GitHub-flow-with-an-integration-branch:

- **`develop`** — the default working branch. All feature branches (`feature/*`) branch from and
  PR back into it. Push as often as needed; this is the sync mechanism. **Never deploys.**
- **`main`** — deploy-triggering only. Merging `develop` → `main` (when a module is complete and
  genuinely ready to ship) is the deliberate act that starts a deploy.

```
feature/*  → PR → develop   (lint, codegen-drift check, test — no image build)
develop    → PR → main      (full pipeline: build both arches, push GHCR, deploy mainvm)
hotfix/*   → PR → main      (urgent prod fix; bypasses develop's in-flight work)
                → back-merge into develop (keeps the fix from being lost/reintroduced)
```

**CI scope by branch**, to respect mainvm's constrained runner ([ADR 0017](0017-deployment-topology.md)
— 3 vCPU, shared with production, capped to 1 concurrent job):

| Branch | Lint | Codegen drift check | Test | Build images | Deploy |
|---|---|---|---|---|---|
| `feature/*` | ✅ | ✅ | ✅ | ❌ | ❌ |
| `develop` | ✅ | ✅ | ✅ | ❌ | ❌ |
| `main` | ✅ | ✅ | ✅ | ✅ (amd64 + arm64) | ✅ |

Only `main` pays for an image build — a WIP push to a feature branch gets full correctness
feedback without tying up the single-concurrency ARM runner building an image nobody will deploy.

Branch protection on `main` requires the CI status checks to pass before merge.

## Consequences

- **Positive:** Pushing to GitHub for backup/sync is always safe — only a `develop → main` merge
  can trigger a deploy, and that merge is a deliberate, reviewable action (a PR).
- **Positive:** Rollback stays simple (redeploy a prior SHA tag from `main`'s history), since
  every image that exists on GHCR came from a `main` build, not from every branch push.
- **Positive:** Hotfixes reach production without waiting on unrelated in-progress work on
  `develop` to finish or be reverted.
- **Negative:** One more branch to keep in sync. A hotfix merged into `main` must be explicitly
  back-merged into `develop`, or it's silently lost the next time `develop` merges up.
- **Negative:** `develop` can drift from `main` for a while if a module takes a long time — normal
  for an integration branch, but it means `develop` is not itself a deploy candidate and shouldn't
  be treated as a staging environment without saying so explicitly (it isn't one; there is no
  staging deploy in this ADR).
- **Requires:** branch protection rules on `main` (required status checks, no direct pushes) and
  on `develop` (PRs only, keeps history reviewable) configured in the repo once CI exists.

## Alternatives considered

**Trunk-based development with feature flags** — every commit lands on `main`, incomplete work
hides behind a flag. Rejected for now: it trades a branching problem for a feature-flag-management
problem, and this product has no live users yet to protect from an unfinished feature — the
flag infrastructure isn't earning its cost before Phase 1 domain code exists. Worth revisiting
once there's a live customer and flags start paying for themselves.

**Single branch + manual approval gate on deploy** (a GitHub Environment with required reviewers
guarding the deploy job, but only one branch) — considered as a lighter-weight alternative to a
second branch. Rejected: it still lets every commit share one branch's history and status, which
makes "is this commit deployable" a question about environment configuration rather than
something visible directly in the branch structure. The two-branch model makes deployability
visible in `git log --all --graph` without opening GitHub's UI.

**No integration branch — every feature branch PRs straight to `main`** — simplest possible
setup. Rejected: it re-creates the original problem. A feature branch would either have to stay
unmerged (accumulating merge-conflict risk) or merge to `main` before it's done (forcing a
deploy of unfinished work), since there'd be nowhere to integrate multiple in-progress features
together first.

## Related

- [architecture/ci-cd.md](../architecture/ci-cd.md) — pipeline this ADR shapes
- [ADR 0017](0017-deployment-topology.md) — why the runner is resource-constrained
