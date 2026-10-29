[← Quotation record access design](quotation-record-access-design.md)

# Quotation OWN scope foundation — QA1b

This is a disabled scope foundation. The shipped quotation view, prepare and assignment duties remain STAGED; the existing separately
gated local-only developer preview can enable view/prepare, not assignment. This slice adds no
permission registration, grant, route, UI, assignment recipe or record-level
read/write adapter is activated by this slice. QA1a owns family responsibility and its immutable
events. QA1b does not alter creator, ownership Actions or historical baseline capture.

`access_scope_limits` retains its company/user/role-or-direct identity and default MY_UNITS;
its database check additionally recognises OWN and the reserved OWN_OR_DELEGATED. Rollback
locks the table and refuses while either new value exists. Existing assignments are not changed.
Administration accepts OWN only for assignments whose every conferring duty has an adapter that
supports it; mixed or empty roles, unrelated direct duties and reserved delegation are refused.
A role already limited to OWN cannot be edited to introduce an unsupported duty. The role row
is locked when checking a role edit or applying an OWN role limit. The permission registry and
assignment guard still control which duties can actually be assigned; a staged duty is not made
live by the supported-scope registry.

`ResourceScopeAdapters` registers module-owned supported duty/mode pairs. Sales registers the
`quotation_family` adapter for view, prepare and assign with OWN and ALL only. `RecordScope`'s
exact-duty `resolveForResource` yields OWN, ALL or NONE. An unrestricted conferring grant wins;
other mandatory duties are resolved independently. Unknown resources, unsupported limited modes,
MY_UNITS on quotation families and reserved delegation fail closed. The generic resolver retains
its existing MY_UNITS behaviour for warehouse and refuses other limited modes rather than
interpreting them as units or ALL. A malformed mixed OWN role also refuses at resolution.

`QuotationFamilyScopeAdapter` scopes a builder to the active company even if a caller removed
Eloquent global scopes. OWN uses the **current responsible owner**, never the immutable creator;
unresolved historical families are excluded from OWN. ALL remains an explicit unrestricted
scope, including the separately governed recovery path. This adapter supplies record eligibility,
not capability permission: future callers must independently check active duty, actor, operation
and source/field authority. Existing SalesAccess remains unchanged and still refuses limited
holders; existing quotation Actions, requests, replay and HTTP remain unchanged until QA3.

The `SalesDuty` catalogue marks these three quotation duties as quotation-family scope metadata
while retaining their STAGED statuses. CRM/configuration duties keep company-wide metadata.
Named sharing, organisation-unit manager eligibility, epoch fencing, activation and holder rollout
are QA2–QA4 work, not implied by this foundation.

## Related

- [Architecture and owner decisions](quotation-record-access-design.md)
- [Storage and Action contract](quotation-record-access-storage.md)
- [Ordered delivery slices](../phases/phase-4-quotation-record-access-slices.md)
- [Scope and authorisation](../architecture/authorization.md)
