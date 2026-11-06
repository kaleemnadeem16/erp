[← Domain](README.md)

# Quotation access demo contract

**Accepted contract; not yet delivered.** [0156](../decisions/0156-quotation-demo-access-and-activation-boundaries.md)
records the owner's decisions. The tables below distinguish source-defined legacy personas from
the intended QA4 recipe; they are not an inventory of grants on the owner's database. No owner
database has been seeded or inspected by this implementation work.

## Existing persona definitions before this extension

Addresses use `@erp.x1techs.com`. Existing roles are CRM roles, not quotation role names.

| Persona | Existing source-defined access | Existing quotation scope |
|---|---|---|
| demo-sales-rep | Sales representative: pipeline read, lead/opportunity work, Party management | No quotation grant |
| demo-sales-manager | Sales manager: representative work plus opportunity assignment/close and stages | No quotation grant |
| demo-sales-branch | Branch seller (limited): pipeline and lead/opportunity work; role MY_UNITS | No quotation grant; CRM restriction is refused where unsupported |
| demo-sales-viewer | Sales viewer: pipeline read | No quotation grant |
| demo-sales-reviewer | Attribution reviewer: pipeline read and sensitive attribution correction | No quotation grant |
| demo-sales-leaver | Direct opportunity work; suspended through ChangeMemberStatus | No quotation grant; remains suspended |
| demo-quote-preparer | Direct quotation view + prepare and pipeline read | OWN on quotation view and prepare |
| user | Legacy demo administrator; several seeders grant direct permissions | Must inventory direct and role paths before applying a preset; no assumed scope |

Source: `SalesCrmDemoSeeder` and `Demo/Sales/QuotationDraftsDemoSeeder`. Their old bootstrap/raw
grant paths are historical context, not the mechanism for this extension. Never rerun old
settings-overwriting seeders on the VM.

## Accepted QA4 mapping and remaining implementation

| Recipe | Persona | Required access/scope |
|---|---|---|
| Representative | demo-sales-rep | Quotation view + prepare, OWN |
| Unit manager | demo-sales-manager | Quotation view + prepare, MY_UNITS; hq subtree |
| Branch seller | demo-sales-branch | Quotation view + prepare, MY_UNITS; khi subtree |
| View-only recipient | demo-sales-viewer | View only; own/named-share eligibility, no prepare |
| Governor | demo-sales-reviewer | View, assignment, delegation/share and revocation within explicit scope; no issue/repair |
| Departed owner | demo-sales-leaver | Preserve suspended status; negative/recovery example, never silently reactivate |
| Preparer/collaborator | demo-quote-preparer | OWN preparation; independent named-share eligibility where required |
| Recovery holder | main demo user under full preset | Company-wide view + assignment/revocation, with recorded grant and operation provenance |
| Access setup operator | demo-access-operator (new) | Role/access administration plus only the approved preset quotation duties; no issue/repair |

The additional operator is needed because none of the existing personas administers access and
main-user self access editing is forbidden. Its creation is owner-approved; its first-holder
bootstrap is now approved only under [0155](../decisions/0155-bootstrap-only-the-synthetic-demo-access-operator.md): exact identity/company, non-production, immutable approved staged grant set, transactional/idempotent audit, and exact-SHA review/CI before merging. Implementation is outstanding. It must never weaken `SaveRole`, `SyncMemberAccess` or `AccessAssignmentGuard`.

## Accepted main-user presets

These are the required command/seeder contract, not commands claimed available at this checkpoint.
Each preset must print its actual resulting rights and scope and use the same role definitions
as the persona seeder. All exclude issue and repair.

| Preset | Result |
|---|---|
| off | No effective approved quotation rights; default-deny |
| preparer | Quotation view + prepare, OWN |
| manager | Quotation view + prepare, MY_UNITS |
| governor | Manager plus assignment, delegation/share and revocation within its scope |
| full | Approved quotation duties with explicit ALL, including recovery management |
| --revoke | Same result as off |

Do not let an old direct/unrestricted grant defeat a limited preset. Preserve unrelated ERP
access and record the quotation-specific before/after provenance. Preset application uses the
synthetic operator and real access Actions, never the target acting on itself. The owner-approved
[0158 subset method](../decisions/0158-patch-only-approved-quotation-access-for-the-main-demo-user.md)
inside SyncMemberAccess manages only the five approved quotation duties for the exact main demo
login. Ordinary full replacement and its grant guard stay unchanged. It preserves unrelated pivot
and scope rows, refuses mixed conferring roles, audits before/after changes and adds nothing on an
unchanged rerun. It cannot bootstrap the operator, register/activate a duty, or grant issue/repair.
Implementation is local; preset command/seeder and bootstrap integration remain outstanding.

## Guard, examples and delivery evidence

Only local/testing or `DEMO_MODE=true`, and only designated demo Company 1, allow staged-duty
handout. New seeders take a company parameter, preserve settings/manual edits/passwords, and
add nothing on a second run. The shared demo password is accepted. No real account except the
explicitly approved main demo login is targeted. Do not invoke seeders on the owner database
as part of implementation verification: use isolated private fixtures.

Required examples are OWN versus another owner, unit containment, reassignment, VIEW/PREPARE
shares, expiry/revocation and recovery provenance through real Actions. Preview/edit always
require view **and** prepare. Keep isolated fixture-manifest tests alongside demo tests and
prove fresh `demo:seed` succeeds in CI. Each new feature extends these presets and examples.

Production activation is blocked independently of demo delivery. The owner must supply
representative company counts, concurrent workload profile and latency budgets before the
parameterised contention harness can establish representative capacity.

## Related

- [0158 quotation-only patch decision](../decisions/0158-patch-only-approved-quotation-access-for-the-main-demo-user.md)
- [0156 owner decision](../decisions/0156-quotation-demo-access-and-activation-boundaries.md)
- [0129 original demo deployment decision](../decisions/0129-demo-and-sandbox-data-live-in-a-separate-demo-deployment.md)
- [Record access architecture](quotation-record-access-design.md)
- [Ordered slices](../phases/phase-4-quotation-record-access-slices.md)
- [Environments](../architecture/environments.md#development-demo-data)
