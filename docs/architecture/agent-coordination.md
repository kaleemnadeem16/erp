[← Architecture index](README.md)

# Agent coordination: who owns what, how work and problems are reported, how it reaches `develop`

**Implemented 2026-10-21.** Built on [multi-agent-workspaces.md](multi-agent-workspaces.md) (worktrees, runners, private test databases, locks) and [AGENTS.md](../../AGENTS.md). Nothing here changes an accepted ADR or authorises business work: **the owner authorises each business feature**; the coordinator integrates it.

## Roles

| Role | Identity (`.erp-agent` / `ERP_AGENT`) | Workspace | Owns | Must not |
|---|---|---|---|---|
| **Coordinator / integrator** | `coordinator` | main checkout (`develop`), kept clean | the task register, assignments and dependencies, review, merges to `develop`, CI/regression checkpoints, routing defects | write other agents' fixes, merge without the owner's approval, push without authorisation |
| **Phase 4 feature agents A, B (C optional)** | `agent-a`, `agent-b`, `agent-c` | one persistent worktree each, a fresh branch per task | one bounded workstream each (below) | change another agent's module without the issue process, merge into `develop` |
| **Phase 5 planner** | `phase5` | its own worktree (recommended: main must stay clean for merges) | Phase 5 research, decisions, readiness reviews, handoffs; Phase 5 docs | implement Phase 4, change Phase 4 contracts (it raises an issue instead) |
| **ERP UI standards agent** | `ui` | its own worktree | UI/UX of existing screens using the shared design system | redesign a screen an active Phase 4 task owns; change a shared component without coordinator review |

Initial Phase 4 boundaries (candidates, gated until the owner authorises each; see the register): **A** Sales quotation Actions, lifecycle and Sales-owned adapters [#10]; **B** shared Documents foundation [#11]; **C** independent pricing/tax foundation [#12]. Identity is by convention, not security: a name in `.erp-agent` is who you say you are.

## The task register (GitHub Issues)

**One source of truth: the repository's GitHub Issues.** Permanent decisions stay in `docs/` and ADRs; pull requests and issue comments carry review evidence; nothing is duplicated into a markdown task list. Chosen over a repo file because: it is already authenticated for the owner, comments give an audit trail, issues link to commits and CI, two agents never edit the same file, and it needs no service. The repository is **public**: no secrets, credentials, customer data or production hostnames in an issue; link to docs instead of pasting.

Driven by `docker/dev/tasks.sh` (a thin `gh` wrapper that applies the conventions and refuses duplicate ownership). Status is a label; Closed is the closed state.

| Field | Where |
|---|---|
| Task / issue ID | issue number |
| Status: Ready · In Progress · Blocked · In Review · Integrated · Closed | `status:*` label (Closed = closed issue) |
| Priority | `prio:p0` blocks others · `p1` · `p2` |
| Responsible agent | `owner:<identity>` label (exactly one) |
| Feature / workstream | `ws:<name>` label |
| Dependencies | `**Depends on:** #12, #15` line (the board marks unmet ones ✗) |
| Branch / worktree | `**Branch / workspace:**` line, set by `claim` and `submit` |
| ADRs / specs | `**Specs / ADRs:**` line (links, not copies) |
| Problem and reproduction | issue body (`tasks.sh report`: expected, actual, evidence are mandatory) |
| Investigation outcome | `outcome:*` label + comment (`tasks.sh outcome`) |
| Tests and verification | the `submit` comment |
| Commit / PR / integration references | the `integrate.sh record` comment |
| Gates | `needs:authorisation`, `needs:owner-decision`, `needs:triage`, `approved` |

```
tasks.sh board                       who works on what, what depends on what, what is blocked, what is ready
tasks.sh claim N [--branch B]        take a Ready task
tasks.sh submit N --branch B --tests "…"   evidence + read-only readiness report → In Review
tasks.sh report … | triage | outcome       cross-agent issues
tasks.sh inbox [--seen]              what changed for you since your last checkpoint
```

Rules `claim` enforces (so two agents cannot unknowingly start the same work): the task is Ready, has no owner, carries no gate, all dependencies are Integrated or Closed, and **no other owner is active in the same workstream** (one accountable owner per workstream). A blocked agent takes another Ready task from the board (`tasks.sh board`) or says so on the blocker; it does not wait idle. Ownership changes only through `tasks.sh reassign` (coordinator) or `release` (owner) and leaves a comment with the reason. Cross-workstream changes need the coordinator's approval, recorded on the task.

**Notifications are not automatic.** GitHub Issues give shared visibility, not delivery to a separate AI session. There is no background poller. Agents therefore check at fixed points: at task start (`board`, `inbox`, dependencies), before using another agent's contract (is its issue Integrated?), after finishing, and before integration-sensitive work. The coordinator reviews new issues at its own checkpoints and routes them. `inbox --seen` moves your checkpoint.

## Cross-agent issues

Example: Agent B (Documents) finds a possible defect in quotation snapshots owned by Agent A.

| # | Who | Does | Command |
|---|---|---|---|
| 1 | B | Records expected (citing the ADR/spec), actual, evidence (commit, test, output) and which of B's tasks it blocks | `tasks.sh report --title … --ws sales-quotes --against "agent-a / snapshots (#10)" --expected … --actual … --evidence … --blocks <B's task>` |
| 2 | Coordinator | Identifies the accountable owner and dependency; names an investigator (first) if ownership is unclear | `tasks.sh triage N --owner agent-a --investigator agent-a` |
| 3 | A | Investigates **before changing anything**: genuine defect / missing contract / misunderstanding / duplicate | `tasks.sh outcome N <kind> -m "findings and proposed resolution"` |
| 4 | A | If confirmed, fixes in its own branch with regression coverage, then submits | `tasks.sh status N in-progress`, `tasks.sh submit N …` |
| 5 | Coordinator | Reviews and sequences the fix before B's dependent work | readiness report, `approve`, `integrate.sh merge` |
| 6 | B | Verifies against the integrated baseline and resumes | `tasks.sh unblock <task>` |
| 7 | Coordinator | Closes with a clear outcome | `tasks.sh close N -m "…"` |

- **No silent rewrites.** An agent never edits another agent's module on its own. The owner (or a coordinator-assigned investigator) changes it.
- **Ambiguous ownership:** the coordinator assigns an investigator first, then the owner.
- **Contradicts an accepted ADR, or needs a business decision:** record the outcome `owner-decision` (`tasks.sh outcome N owner-decision`); this sets `needs:owner-decision` and the coordinator asks the owner. Nobody proceeds on a guess.
- **Non-blocking discoveries** are reported with `--follow-up` (priority p2) and do not expand the finder's scope.
- Status stays visible: a blocked task shows its blocker on the board; `unblock` refuses while the blocker is not Integrated or Closed.

## Test responsibilities

The goal: no repeated full-suite runs, no loss of correctness. GitHub CI runs the entire suite (four backend shards), lint, codegen and e2e on **every push to any branch and on pull requests** ([pipeline.yml](../../.github/workflows/pipeline.yml)); it is the authoritative full run. Local full suites are for the triggers below.

| When | Who | Runs |
|---|---|---|
| While developing | feature agent | focused tests (`run-tests-tmpfs.sh --filter=…`); Pint on touched files; Larastan before submitting (whole project, not every edit) |
| Touching a route, write, read, permission, stock writer or scope | feature agent | the ratchets ([cheat sheet](multi-agent-workspaces.md#agent-cheat-sheet-inside-your-worktree)) |
| A migration | feature agent | `migrate-cycle.sh` and the migration/security tests |
| The API surface | feature agent | `codegen.sh --check` |
| Frontend files | feature agent | `tsc` ×3, `vite build`, unit specs (`run-e2e.sh --project=unit`) |
| **Before submitting** | feature agent | the **module regression** (everything for the module: `run-tests-tmpfs.sh tests/Feature/Modules/<Module> tests/Unit/Modules/<Module>`, naming only directories that exist; a focused run, no full-suite lock; measured: Sales, 220 tests, 18 s, against 20+ minutes for the full suite). Reports counts, failures, skipped tests, known limitations, and whether cross-module regression is needed. **Not** the full backend suite |
| Before integration | coordinator | reads the diff against ownership, dependency readiness, tests/security/invariants, migrations and generated-client changes, authorisation; runs `integrate-check.sh`; failures go back to the owner |
| After integration | coordinator | CI result on the integrated commit; targeted cross-module tests where the change reaches other modules; browser specs from the main checkout (one at a time) when UI or API behaviour changed |
| **Full local regression** | coordinator (takes the one full-suite lock) | only at: a meaningful feature group or milestone; broad architectural, database or security change; release readiness; an unexplained cross-module failure; CI unavailable. Not to duplicate a successful equivalent CI run |

Never weakened: CI, ratchets, Pint/Larastan, the migration cycle. A failing test is classified and fixed, never skipped to save time. If the feature branch was not pushed (so CI has not seen it) the coordinator's fallback is one full local suite on the merged result before publishing `develop`; whether feature agents may push their own branches so CI does this first is a pending owner decision [#17].

## Integration (coordinator)

The owner approves the business feature; the coordinator integrates. A branch is merged only if the owner's approval names **its exact tip commit**.

1. Feature agent finishes in its worktree and runs `tasks.sh submit N --branch B --tests "…"` (evidence + readiness report; status In Review). It does **not** merge.
2. Coordinator checks readiness: `docker/dev/integrate-check.sh B` (read-only), the diff, ownership boundaries, dependencies (`tasks.sh deps N`). It requests fixes through the owner.
3. The owner authorises integration (a message, or an explicit standing rule for a bounded batch). Coordinator: `tasks.sh approve N <sha> --ref "<where the owner said so>"`. If the branch moves afterwards the approval no longer matches and merging is refused.
4. `ERP_AGENT=coordinator docker/dev/integrate.sh merge B N` from the clean main checkout on `develop`: a non-rewriting `git merge --no-ff`; the message carries the task, owner and source commit. Conflicts abort and change nothing (the owner merges `develop` into its branch and resubmits). It does not push.
5. Publishing `develop` is a separate step that needs the owner's authorisation (`git push`, normal fast-forward).
6. CI verifies the integrated commit; then `integrate.sh record N` writes the trace on the issue (task → owner → source commit → integration commit → CI run) and marks it Integrated only when CI passed.
7. Post-integration checks (table above); the coordinator tells affected agents (`tasks.sh` comment); agents merge `develop` and confirm before consuming the new contract.
8. `tasks.sh close N -m "outcome"`.

Do not merge unrelated work, push feature branches without authorisation, or rewrite anyone's history.

## Persistent workspaces and the next task

An implementation agent keeps its worktree (path, `.env`, `node_modules`, private test database, identity) and gets a **fresh branch per task**:

```sh
docker/dev/worktree.sh next sales-quotes phase4/ql2-review     # from the worktree's parent repo, any checkout
```

`next` refuses unless: the worktree is clean (nothing modified, staged **or untracked**) and no merge/rebase/cherry-pick is in progress; the new branch does not exist; the previous branch is already integrated into `develop` (or you pass `--retain`, which keeps it exactly as it is: branches are never deleted by this tool); `develop` is not behind or ahead of `origin/develop` (the baseline is the published one; `--allow-unpushed` only if the coordinator says so). It then branches from `develop`, keeps the workspace identity and test database, re-runs the PHP dependency check and tells you whether migrations, the generated client, shared `docker/` scripts or `package-lock.json` changed since your previous base (and what to run). Each transition is appended to the worktree's `.erp-transitions`. Record the new branch on the next task with `tasks.sh claim`.

**Reuse the worktree** when the previous task is integrated, or abandoned with its branch retained and nothing depends on it. **Create a new worktree** when two tasks are active at once (unfinished work plus a dependent task that must start from it), when a task needs different dependencies for a long time (`worktree.sh vendor`/`deps` give it its own), or for a new agent. Cleanup is a report, not an action: `docker/dev/worktree.sh cleanup-report` lists merged branches, orphaned test databases and vendor volumes with the exact commands; a person reviews and deletes.

## Reusable agent briefs

Give an agent the block for its role, plus [AGENTS.md](../../AGENTS.md). Replace `<N>`, `<branch>`.

**Feature agent (A/B/C)**
> *Start:* work only in your workspace and on the branch the task names (`git status`, `git branch --show-current`). `docker/dev/tasks.sh board` and `inbox`: confirm nobody else has this work, your dependencies are Integrated, and nothing blocks you. Read the task's specs/ADRs and AGENTS.md. `tasks.sh claim <N>`.
> *During:* stay inside the task's scope and ownership. Focused tests, touched-file Pint, applicable checks (test matrix). Report blockers at once with `tasks.sh report --blocks` or `block`. Do not edit another agent's module: file an issue. File out-of-scope discoveries with `--follow-up` and carry on.
> *Finish:* commit by explicit path; run the module regression; `tasks.sh submit <N> --branch <branch> --tests "…"` with counts, failures, skipped, risks, cross-module need. Do not merge into `develop` and do not push without authorisation.
> *Next task:* wait for the Integrated confirmation (or a coordinator-approved dependent arrangement), `docker/dev/worktree.sh next <topic> <new-branch>`, check the board, claim.

**Coordinator**
> Check `tasks.sh board` and `inbox` at each checkpoint; route new issues (`triage`), assign investigators for unclear ownership, never write another agent's fix. Before integration: `integrate-check.sh`, read the diff and tests, check dependencies and migrations/client. Merge only owner-approved exact commits (`approve`, `integrate.sh merge`), one at a time, never push without authorisation. After: CI, `integrate.sh record`, post-integration checks, tell the affected agents, close. Schedule full regression at the triggers only. Escalate ADR conflicts and business decisions to the owner.

**Phase 5 planner**
> Work in your own worktree. Own Phase 5 docs, ADRs and handoffs; use the same register (`ws:phase5`) for tasks and the same Git rules. Do not change Phase 4 implementation contracts or code: raise an issue for the coordinator when a shared architectural change is needed. Merge only through the coordinator.

**UI standards agent**
> Work in your own worktree. Before touching a screen, `tasks.sh board`: if an active Phase 4 task owns it (workstream, hotspots), do not change it. Use the shared design system; a change to a shared component needs a `ws:ui` task, coordinator review, the frontend checks, and the browser specs that use the component (run after integration, from the main checkout).

## Limits

Identity and roles are conventions; the register depends on GitHub being reachable (calls retry; creations and comments verify before retrying); the browser-on-branch boundary and the PostgreSQL exits are tracked in the register (#13, #14); there is no automatic notification between sessions by design.
