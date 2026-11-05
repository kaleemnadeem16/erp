[← Decisions](README.md)

# 0156 — Bound quotation demo access and preserve activation gates

- **Status:** accepted (owner decisions; implementation and verification incomplete); supersedes [0129](0129-demo-and-sandbox-data-live-in-a-separate-demo-deployment.md) only for the interim create-only demo seeding and shared-password rules
- **Date:** 2026-11-03
- **Affects:** Sales quotation access, demo seeders, `sales:dev-grant-quotation`, QA4/QA5, issue #77

## Context

The owner uses Company 1 on the VM as a development and demo company and needs to try each
quotation access feature as it ships. The old local preview excludes assignment; old seeders
include settings overwrites and delete/rebuild behaviour. Neither represents the approved
create-only, parameterised extension. The ordinary access Actions forbid self access editing
and granting permissions the actor does not hold; those guards must remain.

## Decision

Only staged-duty **handout** is demo-specific. Permissions, scope evaluation, ownership checks
and server-side Actions remain production code with identical rules everywhere. The shipped
registry and normal administration screens retain STAGED duties and production default-deny.

- Demo handout requires `APP_ENV` local/testing **or** the existing `DEMO_MODE=true` deployment
  flag, and the designated demo Company 1. Refuse every other company/environment. Seeders
  receive the company as a parameter; they do not embed `1` as their target.
- New seeders are create-only, preserve settings, credentials and manual changes, never delete
  and rebuild, and never touch real accounts or non-demo companies. They may be rerun on the
  owner's VM demo Company 1. This does **not** authorise rerunning old overwriting seeders or
  making demo seeding part of deployment. A shared demo password is accepted for now.
- Reuse the existing Sales personas and hq/main/khi units. Seed roles, grants and scopes through
  the same administration Actions used by the screens. Keep fixture-only manifest tests.
- One additional synthetic `demo-access-operator@erp.x1techs.com` is approved because the existing
  personas cannot administer access. It holds role/access administration and only the approved
  quotation duties needed by the presets, never issue or repair. It applies the main user's
  presets through unchanged `SyncMemberAccess`, preserving both self-management and grant guards.
  Its first-holder bootstrap mechanism still needs resolution; approval of the persona is not
  a permission to bypass those guards silently.
- Main demo login presets are off, preparer (OWN), manager (MY_UNITS), governor (management and
  named-share duties), and full (ALL). `--revoke` means off; print effective rights and scope.
  Seeder and command share role definitions. Every feature includes personas/presets and demo data.
- Reassignment, shares, expiry and revocation demonstrations call the real Actions. No grant
  includes issue or repair; this decision does not activate real holders or widen authority.
- Preview/edit require **both** view and prepare. Prepare alone remains refused without disclosing
  whether a record exists. Any future prepare-alone proposal needs a separate product decision.
- Single-host synthetic contention measurements are accepted with explicit limits: synthetic
  private fixtures, no representative concurrency profile and no production capacity claim.
  Parameterise company count, concurrent writers, row volumes and lock_timeout. Production
  activation stays **BLOCKED** until the owner supplies representative company counts, concurrent
  workload profile and latency budgets. No targets are invented; separate hardware is unavailable.

## Consequences

- **Positive:** the owner can exercise real access rules using repeatable synthetic examples.
- **Negative:** demo grant setup is a privileged boundary that needs its own refusal tests and
  provenance; local measurements cannot settle production contention capacity.
- **Requires:** exact preset/scope tests, OWN non-disclosing refusal, MY_UNITS containment, off
  default-deny, unchanged self-management refusal, operator without issue/repair, environment
  and company refusals, create-only rerun proofs, and fresh CI `demo:seed`.

## Alternatives considered

**Keep the old local switch unchanged** — rejected by the owner: assignment and shares must be
tryable in the designated demo deployment as well as local development.

**Activate staged duties generally or weaken the access Actions** — rejected: the demo restriction
belongs to handout; authority checks and production rollout remain unchanged.

**Rerun the old demo seeders or invent credentials/latency targets** — rejected: overwrites are
unsafe, the shared password is explicitly accepted, and representative workload inputs are absent.

## Research basis

This is an owner-directed technical/demo packaging decision. It reuses the accepted quotation
[record-access design](../domain/quotation-record-access-design.md) and [0129](0129-demo-and-sandbox-data-live-in-a-separate-demo-deployment.md);
it does not redesign quotation business authority or select a new external ERP model.

## Related

- [Demo persona and preset contract](../domain/quotation-access-demo.md)
- [Safety measurements and limits](../domain/quotation-access-safety-proofs.md)
- [Ordered implementation slices](../phases/phase-4-quotation-record-access-slices.md)
- [Issue #77](https://github.com/kaleemnadeem16/erp/issues/77)
