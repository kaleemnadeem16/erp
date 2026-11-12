[← Domain](README.md)

# Quotation access demo contract

**Delivered for the local preview (ADR [0161](../decisions/0161-switchable-quotation-demo-presets-and-local-preview-activation.md)); the
operator bootstrap awaits its exact-SHA review.** [0156](../decisions/0156-quotation-demo-access-and-activation-boundaries.md)
records the owner's decisions. The tables below distinguish source-defined legacy personas from
the QA4 recipe; they are not an inventory of grants on the owner's database. No owner database
has been seeded or inspected by this implementation work. The delivered mechanism is described
first; the older tables that follow keep the original accepted contract and are marked where the
delivered recipe differs.

## Delivered: switch the main login between presets

Local preview only: `APP_ENV=local` and `SALES_DEV_QUOTATION_DUTIES=true`. Company 1 only. The
preview now makes the access family (assign, share, revoke), the concessions (discount, price
override, commercial view) and approve ACTIVE; none is active in the shipped manifest and
activating grants nobody anything. Repair, withdraw and send are never activated.

```
php artisan demo:seed                       # once (create-only; also builds the operator and personas below)
php artisan sales:demo-preset --list        # presets and what they give
php artisan sales:demo-preset preparer      # switch user@erp.x1techs.com; prints the resulting rights and scope
php artisan sales:demo-preset --revoke      # same as "off"
```

Docker: prefix with `docker compose exec app` (or `docker/backend/run.sh`). Each run is made by the
synthetic operator through the guarded `SyncMemberAccess::patchQuotationDemo` (ADR 0159: quotation
access only, unrelated rights kept, audited, rerun adds nothing) plus audited workflow add-ons.

| Preset | Access (scope) | Workflow add-ons |
|---|---|---|
| off | none | none |
| viewer | view (ALL) | none |
| preparer | view + prepare (OWN) | none |
| collaborator | view + prepare (OWN or named share) | none |
| shared-viewer | view (named shares only) | none |
| delegate | view + prepare + share + revoke (OWN) | none |
| concessions | view + prepare (OWN) | discount, price override, commercial view |
| manager | view + prepare (MY_UNITS) | none |
| issuer | view (ALL) | issue |
| approver | view (ALL) | approve |
| governor | view + prepare + assign + share + revoke (MY_UNITS) | none |
| access-manager | view + assign + share + revoke (ALL) | none |
| full | governor rights (ALL) | issue, discount, price override, commercial view, approve, plus the Discount authority settings duties (`sales.financial.configure`, `sales.pricing.reference.approve`; local preview only; the server still refuses approving a reference you proposed) |

Add-ons are direct ALL-scoped grants like the existing `sales:dev-grant-quotation`; the patch itself
still never manages issue, repair or workflow duties. `sales:dev-grant-quotation` is unchanged
and, if used, hands view and prepare out directly: the next preset switch resets them. Making
the main login useful for MY_UNITS presets: the seeder adds it to Head office only while it belongs to
no unit (membership alone grants nothing).

## Delivered: operator and personas

All addresses `@erp.x1techs.com`, shared demo password. Created by `QuotationAccessDemoSeeder`
(create-only, company passed as a parameter, refuses any company but Company 1, writes nothing
outside the local preview).

| Login | Access | Purpose |
|---|---|---|
| demo-access-operator | role + access administration; exactly view, prepare, assign, share, revoke (ALL) via the bootstrap | applies presets; never issue, repair or approve |
| demo-quote-manager | preparer role, MY_UNITS, Head office | unit containment |
| demo-quote-governor | governor role, MY_UNITS, Head office | unit-level reassignment and sharing |
| demo-quote-recovery | access manager, ALL | places quotations in units, reassigns, revokes |
| demo-quote-delegate | delegate role, OWN | owns quotations and shares them by name |
| demo-quote-collaborator | preparer role, OWN or named share | receives PREPARE shares; owns one |
| demo-quote-viewer | viewer role, named shares only | receives VIEW shares |
| demo-quote-approver (existing) / demo-quote-director | view + approve | decide approvals; never their own draft |

Existing CRM personas keep their CRM roles: the operator cannot retain CRM rights it does not hold,
so it cannot re-assign them. The new logins are separate.

Seeded quotations through the real Actions (markers in the `demo-acc-` request keys): shared read-only
(delegate to viewer), a PREPARE share that ends 2030-01-01, a share given then revoked, a quotation placed
in Head office then handed to the unit manager by the governor, an owner transferred from delegate to
collaborator, and the collaborator's own. Two override drafts exercise the two-stage approval below.

**Fictitious demo approval routing (not the owner's thresholds).** The engine routes only on the kind
of concession and currency, not on a percentage. Demo policy `demo-quotation-override-two-stage`: a
price override (alone or with a discount) needs a sales lead and then a director; a discount alone keeps
the existing single-step policy. Step names are constants in the seeder. Real thresholds (for example
"up to N% sales lead, above that director") need a routed percentage fact in the Approvals subject first;
the owner supplies the numbers later.

**Bootstrap (ADR 0155).** `BootstrapDemoAccessOperator` is the only first-holder path: exact operator
identity, Company 1, not production, duties already active, exactly the five approved duties, an
append-only receipt that names the initiating member (`initiated_by_user_id`, NULL on older receipts; the activity row records the same actor), never replenished, any other set refused. The missing exact-SHA CI run is an accepted exception ([0170](../decisions/0170-accept-the-missing-exact-sha-ci-evidence-for-the-demo-operator-bootstrap.md)). On the VM demo (non-local `DEMO_MODE`) the duties cannot be activated by this preview, so presets
are local-only for now.

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
[0159 subset method](../decisions/0159-patch-only-approved-quotation-access-for-the-main-demo-user.md)
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

- [0159 quotation-only patch decision](../decisions/0159-patch-only-approved-quotation-access-for-the-main-demo-user.md)
- [0156 owner decision](../decisions/0156-quotation-demo-access-and-activation-boundaries.md)
- [0129 original demo deployment decision](../decisions/0129-demo-and-sandbox-data-live-in-a-separate-demo-deployment.md)
- [Record access architecture](quotation-record-access-design.md)
- [Ordered slices](../phases/phase-4-quotation-record-access-slices.md)
- [Environments](../architecture/environments.md#development-demo-data)
