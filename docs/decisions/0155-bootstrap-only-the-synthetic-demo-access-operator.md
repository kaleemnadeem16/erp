[← Decisions](README.md)

# 0155 — Bootstrap only the synthetic demo access operator

- **Status:** accepted (owner conditions); implementation delivered and independently reviewed (bootstrap `c34821b6`, corrected in `f9fbd424`); the missing exact-SHA CI run for `c34821b6` is an explicit exception accepted in [0170](0170-accept-the-missing-exact-sha-ci-evidence-for-the-demo-operator-bootstrap.md) (owner decision D10, 2026-11-12); all other conditions stand; production activation remains blocked
- **Date:** 2026-11-03
- **Affects:** Sales demo access bootstrap; supplements [0156](0156-quotation-demo-access-and-activation-boundaries.md)

## Context

The owner approved one synthetic access operator for the quotation demo. Ordinary SaveRole and
SyncMemberAccess correctly forbid granting a permission the grantor does not hold. None of the
existing personas holds the new staged assignment/share duties, so ordinary access administration
cannot create their first holder. The exception must not spread to ordinary administration.

## Decision

Authorise one narrowly scoped, create-once bootstrap Action exclusively for the exact synthetic
access-operator identity in designated demo Company 1. It grants only the explicitly approved
staged assignment/share duty set, transactionally, idempotently and with auditable provenance.

- Enforce exact identity and company restrictions inside the Action, including hydrated targets
  from another company. Refuse production; do not treat DEMO_MODE as a production bootstrap bypass.
- Successful bootstrap fixes its exact grant set and provenance. Refuse additional or altered
  grants after success; do not silently repair or replenish grants on rerun.
- No public route or UI, broad grant, issue/repair permission or permission activation is authorised.
- Keep default-deny and no-grant-what-you-do-not-hold unchanged for ordinary operations. Subsequent
  persona roles, member access and main-user presets use the existing authorised Actions.
- Prove negative security, tenant isolation, transactional rollback, idempotence and altered-grant
  refusal. Submit implementation for **exact-SHA review and CI before merging**. This review gate
  applies to the bootstrap implementation even though earlier completion work targets develop.

The staged sharing/delegation/revocation consumers are not yet delivered. Do not bootstrap an
incomplete assignment-only set now and later add share rights behind an existing successful receipt.
Deliver the complete approved recipe and its consumers together, keeping bootstrap unapplied until
its prerequisites and review gate are satisfied.

## Consequences

- **Positive:** the synthetic setup has an explicit first-holder origin; ordinary guards stay intact.
- **Negative:** a tightly privileged exception needs dedicated storage/provenance and negative tests;
  its immutable successful grant set cannot double as a general incremental permission seeder.
- **Requires:** exact allowlist, environment/company/identity refusal, append-only evidence, and a
  separately reviewable commit before integration. Production activation remains blocked.

## Alternatives considered

**Weaken ordinary no-grant or self-management checks** — rejected by the owner; the first-holder
exception belongs exclusively to this synthetic bootstrap Action.

**Bootstrap a broad administrator or restore missing grants on every rerun** — rejected: neither
is create-once or limited to the approved staged duty set.

**Assume an existing authorised holder** — not supported by the source-defined personas; the
owner explicitly selected the narrowly scoped bootstrap option instead.

## Research basis

This is a local security bootstrap decision, not new ERP business behaviour. It reuses the existing
[authorisation contract](../architecture/authorization.md) and [0156](0156-quotation-demo-access-and-activation-boundaries.md).

## Related

- [Demo persona/preset contract](../domain/quotation-access-demo.md)
- [Completion resume checkpoint](../domain/quotation-access-resume.md)
- [Issue #77](https://github.com/kaleemnadeem16/erp/issues/77)
