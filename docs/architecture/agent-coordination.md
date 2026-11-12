[← Architecture index](README.md)

# Agent coordination: who owns what, how work and problems are reported, how it reaches `develop`

**Implemented 2026-10-21.** Built on [multi-agent-workspaces.md](multi-agent-workspaces.md) (worktrees, runners, private test databases, locks) and [AGENTS.md](../../AGENTS.md). Nothing here changes an accepted ADR or authorises business work: **the owner authorises each business feature**; the coordinator integrates it.

## Current branch rule — until the owner changes it

**Owner instruction, 2026-11-12: all work is directly on `develop` in the main checkout.**
Follow [AGENTS.md](../../AGENTS.md#develop-only-workflow--owner-instruction-2026-11-12).
No feature/task branch or worktree creation/use for ongoing work. This takes precedence over
older task branch fields, briefs and the historical worktree procedure. Existing work stays
intact; transferring it needs separate owner approval. No automatic merge, deletion or stash.

Use `--branch develop` when claiming/submitting tasks and record the exact owned commits and
paths. On a shared checkout inspect the staged diff before committing; if someone else's changes
are staged, coordinate before committing rather than including or unstaging their work.

## Roles

| Role | Identity (`.erp-agent` / `ERP_AGENT`) | Workspace | Owns | Must not |
|---|---|---|---|---|
| **Coordinator / integrator** | `coordinator` | main checkout (`develop`), shared with agents | the task register, assignments and dependencies, review of exact `develop` commits, CI/regression checkpoints, routing defects | write other agents' fixes, transfer existing branch work without the owner's approval, push without authorisation |
| **Phase 4 feature agents A, B (C optional)** | `agent-a`, `agent-b`, `agent-c` | shared main checkout, `develop` only | one bounded workstream each (below) | change another agent's module without the issue process, create/use a feature branch |
| **Phase 5 planner** | `phase5` | shared main checkout, `develop` only | Phase 5 research, decisions, readiness reviews, handoffs; Phase 5 docs | implement Phase 4, change Phase 4 contracts (it raises an issue instead) |
| **ERP UI standards agent** | `ui` | shared main checkout, `develop` only | UI/UX of existing screens using the shared design system | redesign a screen an active Phase 4 task owns; change a shared component without coordinator review |

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
tasks.sh claim N --branch develop   take a Ready task
tasks.sh submit N --branch develop --tests "…"   exact commit/check evidence → In Review
tasks.sh report … | triage | outcome       cross-agent issues
tasks.sh inbox [--seen]              what changed for you since your last checkpoint
```

Rules `claim` enforces (so two agents cannot unknowingly start the same work): the task is Ready, has no owner (or is pre-assigned to you by the coordinator; one pre-assigned to someone else is refused), carries no gate, all dependencies are Integrated or Closed, and **no other owner is active in the same workstream** (one accountable owner per workstream). A blocked agent takes another Ready task from the board (`tasks.sh board`) or says so on the blocker; it does not wait idle. Ownership changes only through `tasks.sh reassign` (coordinator) or `release` (owner) and leaves a comment with the reason. Cross-workstream changes need the coordinator's approval, recorded on the task.

**Notifications are not automatic.** GitHub Issues give shared visibility, not delivery to a separate AI session. There is no background poller. Agents therefore check at fixed points: at task start (`board`, `inbox`, dependencies), before using another agent's contract (is its issue Integrated?), after finishing, and before integration-sensitive work. The coordinator reviews new issues at its own checkpoints and routes them. `inbox --seen` moves your checkpoint.

## Cross-agent issues

Example: Agent B (Documents) finds a possible defect in quotation snapshots owned by Agent A.

| # | Who | Does | Command |
|---|---|---|---|
| 1 | B | Records expected (citing the ADR/spec), actual, evidence (commit, test, output) and which of B's tasks it blocks | `tasks.sh report --title … --ws sales-quotes --against "agent-a / snapshots (#10)" --expected … --actual … --evidence … --blocks <B's task>` |
| 2 | Coordinator | Identifies the accountable owner and dependency; names an investigator (first) if ownership is unclear | `tasks.sh triage N --owner agent-a --investigator agent-a` |
| 3 | A | Investigates **before changing anything**: genuine defect / missing contract / misunderstanding / duplicate | `tasks.sh outcome N <kind> -m "findings and proposed resolution"` |
| 4 | A | If confirmed, fixes owned paths on `develop` with regression coverage, then submits | `tasks.sh status N in-progress`, `tasks.sh submit N …` |
| 5 | Coordinator | Reviews and sequences the fix before B's dependent work | exact-commit review, dependency acceptance and CI evidence |
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

Never weakened: CI, ratchets, Pint/Larastan, the migration cycle. A failing test is classified and fixed, never skipped to save time. Run checks appropriate to the exact `develop` commits being reviewed. If those commits have not been published, do not claim hosted CI has verified them; coordinator full-regression triggers above still apply. Pushing `develop` needs owner authorisation.

<a id="integration-coordinator"></a>

## Review and acceptance on `develop` (coordinator)

1. Agent verifies the checkout is `develop`, claims a bounded task and coordinates file/hunk ownership.
2. Agent runs applicable checks and commits only owned authorised paths/hunks. Do not commit someone
   else's staged work; never stage the whole tree. Record exact commit(s), paths and limitations.
3. Submit with `tasks.sh submit N --branch develop --tests "…"`. The branch comparison report may
   show no diff against itself: review the task's actual commit diff(s), not that empty comparison.
4. Independent/coordinator review checks scope, dependencies, security, migrations, client and test
   evidence at the exact commit. Review/acceptance is still required even though code is on `develop`.
5. Publish only with owner authorisation, using a normal fast-forward push. Check the complete outgoing
   committed range; staged/uncommitted files are not part of the push and must remain untouched.
6. Record exact-SHA CI and applicable cross-module/browser evidence on the task; accept/close only
   after the required review/check gates. Do not claim an unrun or different-SHA check passed.

The old branch-merge `integrate.sh merge` procedure does not apply to new work already on `develop`.
It is not a workaround for approval gates or permission to merge previously existing branches.
Never rewrite shared history or switch the main checkout away from `develop`.

<a id="persistent-workspaces-and-the-next-task"></a>

## Next task

Stay in the same main checkout on `develop`. Re-read status, board/inbox, latest commits and dirty/
staged paths; claim the next authorised task with `--branch develop`. Do not run `worktree.sh add`
or `worktree.sh next`. Preserve existing feature worktrees and their work until the owner explicitly
approves what to transfer. Test wrappers/private agent databases and shared stack locks remain required.

## Reusable agent briefs

Give an agent the block for its role, plus [AGENTS.md](../../AGENTS.md). Replace `<N>`; the branch is `develop`.

**Feature agent (A/B/C)**
> *Start:* work in the main checkout on `develop` regardless of an older task branch field (`git status`, `git branch --show-current`). `docker/dev/tasks.sh board` and `inbox`: confirm nobody else has this work, your dependencies are Integrated, and nothing blocks you. Read the task's specs/ADRs and AGENTS.md. `tasks.sh claim <N> --branch develop`.
> *During:* stay inside the task's scope and ownership. Focused tests, touched-file Pint, applicable checks (test matrix). Report blockers at once with `tasks.sh report --blocks` or `block`. Do not edit another agent's module: file an issue. File out-of-scope discoveries with `--follow-up` and carry on.
> *Finish:* commit by explicit path; run the module regression; `tasks.sh submit <N> --branch develop --tests "…"` with counts, failures, skipped, risks, cross-module need. Do not create/use a feature branch or push without authorisation.
> *Next task:* stay on `develop`, confirm required dependency acceptance, check the board and current file ownership, claim the next authorised task.

**Coordinator**
> Check `tasks.sh board` and `inbox` at each checkpoint; route issues and ownership, never overwrite another agent's work. Review exact task commits on `develop`, dependencies, authorisation and checks; an empty branch-comparison report is insufficient. Publish only with owner authorisation; record exact-SHA CI and post-change checks before accepting/closing tasks. Existing branch transfers require separate approval. Escalate ADR conflicts and business decisions to the owner.

**Phase 5 planner**
> Work directly on `develop` in the shared main checkout. Own Phase 5 docs, ADRs and handoffs; use the same register (`ws:phase5`) for tasks and the same Git rules. Do not change Phase 4 implementation contracts or code: raise an issue for the coordinator when a shared architectural change is needed. Submit exact owned commits for coordinator/independent review.

**UI standards agent**
> Work directly on `develop` in the shared main checkout. Before touching a screen, `tasks.sh board`: if an active Phase 4 task owns it (workstream, hotspots), do not change it. Use the shared design system; a change to a shared component needs a `ws:ui` task, coordinator review, the frontend checks, and the browser specs that use the component (run from the main checkout with the shared wrapper).

## Limits

Identity and roles are conventions; the register depends on GitHub being reachable (calls retry; creations and comments verify before retrying); the browser-on-branch boundary and the PostgreSQL exits are tracked in the register (#13, #14); there is no automatic notification between sessions by design.
