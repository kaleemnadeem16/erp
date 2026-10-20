[← Phase index](README.md)

# Documents DF0 — workspace and implementation handoff

**2026-10-20 · PREPARATION ONLY. Nothing here authorises code.** The owner authorises DF0 separately, naming the implementation agent and the exact batch
([document-print-framework-slices.md](document-print-framework-slices.md): "Every slice needs separate code authorisation"; [phase-4-implementation-handoff.md](phase-4-implementation-handoff.md): "it authorises none").

## Workspace (ready, empty)

| | |
|---|---|
| Path / branch | `/home/kaleem/projects/erp-documents` / `phase4/documents`, created from `develop` at `afe70e1` |
| Test database | `erp_testing_phase4_documents` (change `.erp-agent` to the agent's own name to rename it) |
| Commands | [multi-agent-workspaces.md](../architecture/multi-agent-workspaces.md#agent-cheat-sheet-inside-your-worktree) |
| Sanity check done | `--filter=Numbering` passes in the worktree (69 tests) |

## What DF0 is (as accepted)

From the [DF0 row](document-print-framework-slices.md) and batch **B5** of the [handoff](phase-4-implementation-handoff.md) (DF0/DF1 plus the DOC0 Sales snapshot/binding/layout; "can progress independently"):

- **Shared Documents delivery:** a Documents module owning the provider, type and access contracts, and the tenant-safe common model, constraints, receipts and immutability foundation, with the **public entry disabled**.
- **Sales-side adapter pieces:** a quotation binding **skeleton** with its required allowlist/schema, and a pure **PO-shaped typed fixture** that makes no Purchasing writes.
- **Required verification:** a missing adapter refuses; raw cross-company binding/FK/direct invalid writes are refused by the database; no Sales-only universal line schema; no duplicate archive.

DF1 (configuration and layouts), DF2 (freeze and generation intents; needs the real QL3 issue contract), DF3 (renderer), DF4 (recovery) and DF5 (public pilot) are **not** part of DF0.

## Accepted prerequisites (cite, do not re-decide)

[ADR 0137](../decisions/0137-share-document-and-print-infrastructure-through-module-adapters.md) (shared infrastructure through module adapters; Q50 closed) · [Documents contract](../domain/documents.md) · [R8 DOC0–DOC7 slices](phase-4-r8-implementation-slices.md) ·
[ADRs 0138–0140](../decisions/0138-authorise-sales-and-document-duties-with-explicit-scope-and-grants.md) and the [R9 permission rollout](phase-4-r9-permission-rollout.md) (duties, scope, grants; Q49 closed) · [Sales duty matrix](../domain/sales-duty-matrix.md).
Already on `develop`: AU0 duty manifest and AU1 safe registration (`b12acd8`), the Sales module with QL0 quotation identities and draft foundation (`1ad0752`), the Numbering and Company modules.

## Ownership boundaries

| Owner | Owns |
|---|---|
| **Documents agent** | The shared Documents module and its common tables, contracts and tests; the PO-shaped fixture; the Documents owning docs |
| **Sales quotation agent** | Sales-owned `quotation_customer_document_snapshots` and `quotation_document_bindings`, the quotation Actions and the adapter that supplies typed projections. The quotation binding skeleton in DF0 touches these, so it is a **coordination point** (owner decision below) |
| **Company** | Branding and assets (DF1; Company facts come only through its owning contract) |
| Purchasing | Untouched: the existing PO Quick Print is a different track and stays unchanged; no PO refactor is authorised |

## Out of scope for DF0

Any public route, grant or registration of live duties; the renderer library or any package install; email; changing existing demo credentials; production seeding; the real QL3 binding; bulk adapter rollout.

## Hotspots shared with the quotation agent

Migrations (additive common tables with company-scoped keys; check each other's tables), `docs/STATUS.md`/`STATUS-LOG.md`, and the Sales module's provider/registration files. DF0 has a **disabled** public entry, so no API/client change is expected: if the generated client moves, something is wrong. Run the ratchets that apply
(`CompanyScopedForeignKeyTest`, `CompanyScopedUniqueIndexTest`, `PermissionCheckRegistrationTest`, `WriteRouteGuardTest`, `RecordScopeCoverageTest`) before committing.

## Checks the code owner must report at hand-off

Pint and Larastan; the focused suites, then one full suite; the ratchets above; `docker/backend/migrate-cycle.sh`; `docker/backend/codegen.sh --check` (expected: no change); frontend type checks only if a frontend file changed; the owning docs and status updated in the same commits. Browser checks are not applicable to a disabled entry.

## Decisions the owner needs to make to authorise DF0

1. Which agent is the Documents implementation agent, and whether the first batch is **DF0 alone** or DF0 + DF1 (the handoff allows both as B5).
2. Who delivers the **quotation binding skeleton**: the Documents agent in coordination with the quotation agent, or the quotation agent after DF0's contracts land.
3. Whether the Documents work waits for any quotation slice (the accepted plan says DF0/DF1 can be delivered disabled without an implemented quotation issue; only DF2 needs QL3).
4. The module name and path for the shared infrastructure (the specs give names that "specify contracts, not existing classes").
