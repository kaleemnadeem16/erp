[← Architecture](README.md)

# Feature-completion checklist

**Use this before you say a feature is done.** It is an index, not a rulebook: each line is a question, with the canonical document that holds the detail. A feature that skips a line is not finished —
the cost of doing it in the first commit is small, the cost of coming back is the audit, retrofit and hardening passes this repository has already paid for. Answer **"not applicable" in one line** when a
line does not apply (a read-only report has no idempotency key); do not leave it unanswered. Decisions of substance go in an ADR ([ADR 0096](../decisions/0096-cross-cutting-feature-standards.md)).

## The checklist

| # | Question | Detail lives in |
|---|---|---|
| 1 | **Is the domain semantics settled?** Users, lifecycle and states, what is irreversible, what needs a reason, what cost or valuation does (or must not do). For unfamiliar ERP behaviour, was the research gate followed? Is there an ADR for a real decision? | [research-and-design.md](research-and-design.md) · [user-interface.md](user-interface.md#design-gate-before-a-workflow-is-built) · [ADR template](../templates/adr.md) |
| 2 | **Tenant, scope and permissions.** Company isolation by id; organisation/warehouse/record scope and what a limited person gets (`403`/`404`/narrowed); is a new capability really needed or does an existing one fit; read vs write vs approve vs reverse; sensitive reads; separation of duties | [authorization.md](authorization.md) R1–R14 · [multi-tenancy.md](multi-tenancy.md) |
| 3 | **Guarded twice.** Request/controller *and* Action; **HTTP-only security is insufficient**; an explicit actor for jobs, commands and seeders | [authorization.md](authorization.md) R1 |
| 4 | **Domain integrity.** Logic in an Action, one transaction, idempotency key for retryable writes, stable lock order and stale-state refusal, append-only evidence, fail-closed advanced mode, snapshot at issue, money/quantity precision, named status transitions | [domain-integrity.md](domain-integrity.md) · [invariants.md](invariants.md) |
| 5 | **Database invariants.** Constraints/triggers for tenancy, conservation and immutability; composite company keys; indexes; additive migration; a real `down()` that refuses when it would lose data; rollback and re-apply checked | [database.md](database.md#migration-standards) |
| 6 | **API.** Resource with explicit types, Form Request validation, error mapping, stable `reason_code` where the UI acts on a refusal, filters/includes/pagination conventions, no breaking change to an existing endpoint, OpenAPI correct, **client regenerated and committed** | [api-conventions.md](api-conventions.md) |
| 7 | **Rollout.** New permissions registered, grant/continuity migration, administrator re-sync, role recipe documented; new settings default to today's behaviour; new modes start off | [authorization.md](authorization.md) R5 · [database.md](database.md#migration-standards) |
| 8 | **UI/UX.** Shared shell and components, semantic tokens in light and dark, hierarchy and density, loading/empty/error/denied states, validation text, confirmations for irreversible actions, progressive exposure (advanced controls hidden when the mode/capability does not apply), permission-aware controls, operator language | [erp-blue-design-standard.md](erp-blue-design-standard.md) · [ui-building-blocks.md](ui-building-blocks.md) · [user-interface.md](user-interface.md) |
| 8a | **Lookups survive growth.** No screen finds a known record with `.find` over a first page, and no picker over items, suppliers, locations or customers is a first page of 100: known records are resolved by id (`useItemsById` and friends), pickers search the server (`ItemCombobox`, `SupplierCombobox`, `LocationCombobox`, or `useItemLookup` for a themed `Combobox`) | [ui-building-blocks.md](ui-building-blocks.md#lookups-that-survive-large-datasets) |
| 9 | **Responsive and accessible.** Phone width (about 390 px): no horizontal overflow, dialogs fit, primary action reachable; keyboard and focus; contrast ≥ 4.5:1 text / 3:1 controls; labels and text alternatives | [erp-blue-design-standard.md](erp-blue-design-standard.md#workflow-behaviour-standards-every-screen-that-does-something) |
| 10 | **Tests** — the applicable rows: happy path, permissions, direct Action, cross-company, scope, database invariant, idempotency, rollback, stale/concurrent, legacy parity, rollout, browser journey, phone width, accessibility, ratchets registered | [testing-standards.md](testing-standards.md) |
| 11 | **Demo.** A meaningful new domain capability ships a module seeder: idempotent, realistic named scenarios, immutable history created once and never mutated, runnable by another developer | [environments.md](environments.md#development-demo-data) |
| 12 | **Docs, at the right level and in the same commit** (below). No duplicated current-state text; no stale "next" left behind | [AGENTS.md](../../AGENTS.md) rules for maintaining the docs |
| 13 | **Checks pass.** Pint, Larastan, backend tests incl. architecture, typecheck of all three frontend projects, build, client drift, the browser specs for the screens touched. Say honestly what you did not run | [testing-standards.md](testing-standards.md#running) · [ci-cd.md](ci-cd.md) |
| 14 | **Shared-checkout hygiene.** Only your files staged, by explicit path; no stash; no push to `main`; status reported accurately | [AGENTS.md](../../AGENTS.md#working-alongside-other-agents) |

## What a feature normally updates in the docs

| Change | Update | Not here |
|---|---|---|
| A real design or business decision | An **ADR** (immutable once accepted; supersede to change) and its index row | — |
| A lasting rule or invariant of behaviour | The **domain doc** (`docs/domain/`); the architecture doc if it is cross-cutting; `invariants.md` only for a rule that must never be broken | Phase doc narrative |
| A reusable API contract changes | [api-conventions.md](api-conventions.md) | Each feature doc |
| A significant user workflow | A **journey** in `docs/journeys/` (actors, statuses, effects, exceptions) | The domain doc |
| New permission or sensitive field | The domain doc names it; the [audit tracker](access-security-audit.md) coverage row; the role recipe | STATUS |
| Phase scope or acceptance state | The **phase doc** (tick the item) | STATUS |
| A workstream starts, completes or changes its resume point | The workstream **handoff**; one row in [STATUS.md](../STATUS.md) | Implementation narrative |
| Detailed record of what was done | [STATUS-LOG.md](../STATUS-LOG.md) (append-only, historical) | STATUS |
| An open question is answered | An ADR; remove it from [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md) | — |

`STATUS.md` stays short and current ([ADR 0095](../decisions/0095-status-is-short-current-and-purpose-limited.md)); the log is history. Describe a current state in **one** canonical place and link to it. Text that
records an earlier state says it is historical.

## Operational concerns

Mandatory only where the architecture already commits to them; otherwise advisory — decide per feature and record the decision.

| Concern | Rule | Status |
|---|---|---|
| **Audit trail and reasons** | Governed changes (policy, reversal, override, activation, approval) record actor, time and a mandatory reason, stored with the evidence; `activity_log` for generic audit | **Mandatory** ([domain-integrity.md §6](domain-integrity.md#6-immutable-append-only-evidence-corrections-are-new-records)) |
| **Printing and reporting** | Print/quick-print/export render only what the screen's projection allows; paper stays white and independently branded; a new export is a new security surface | **Mandatory** (R7) · [appearance-and-printing.md](appearance-and-printing.md) |
| **Import / export** | Import goes through the column-mapping engine into staging with no entry point to posting; export follows R7 | **Mandatory** where present · [data-import.md](data-import.md) |
| **Dates and time zones** | API timestamps ISO 8601 UTC; business dates are plain dates; presentation uses the company time zone | **Mandatory** ([api-conventions.md](api-conventions.md#dates)) |
| **Pagination and large data** | Lists paginate; a query over a table expected to exceed ~100k rows gets an `EXPLAIN`; no unbounded loads in the SPA; no N+1 in list endpoints | **Mandatory** for lists; query-count tests advisory |
| **Background jobs** | Redis queue/scheduler exist; a job passes an explicit actor, re-checks authority, is idempotent and namespaced; nothing is scheduled without the owner knowing (it changes production behaviour) | Mandatory *if you add one* |
| **Notifications** | **Not built** — Q33 decides the shared capability; do not build a feature-specific mechanism | Deferred (OPEN-QUESTIONS Q33) |
| **Observability and logging** | Domain refusals are typed exceptions with codes, not log lines; never log secrets or personal data; no bespoke monitoring stack per feature | Advisory |
| **Feature / capability exposure** | Domain capability, company configuration and UI exposure are separate; backend ahead of UI is legitimate; an existing screen must not silently ignore filters its own endpoint validates | **Mandatory** ([extensibility.md](extensibility.md#progressive-capability-modes) · [user-interface.md](user-interface.md)) |
| **Performance** | Index what you filter by; avoid recomputing in the browser what the server returns; the dev app must not load thousands of modules (the generated barrel stays type-only) | Advisory beyond the above |

## Related

- [AGENTS.md](../../AGENTS.md) — the short mandatory rules this expands
- [testing-standards.md](testing-standards.md) · [domain-integrity.md](domain-integrity.md) · [authorization.md](authorization.md) · [api-conventions.md](api-conventions.md) · [database.md](database.md)
- [erp-blue-design-standard.md](erp-blue-design-standard.md) · [ui-building-blocks.md](ui-building-blocks.md) · [user-interface.md](user-interface.md)
- [ADR 0096](../decisions/0096-cross-cutting-feature-standards.md) — why the standards are canonical docs plus a checklist
