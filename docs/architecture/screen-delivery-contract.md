[← Architecture](README.md)

# Screen delivery contract

Required for new screens and material extensions to existing screens. Purchase Orders is the
reference for reusable interactions, not a requirement to copy its business fields or every
optional feature. Use the shared components and current presentation configuration; do not copy
PO-specific JSX, permissions or accidental limitations. Existing screens are retrofitted only
within an authorised slice; these rules do not claim that every shipped screen already complies.

## Specify coverage before coding

Record a short interaction specification in the owning domain doc, with acceptance scenarios in
the phase plan or journey. Inspect the endpoint's Requests, Resources, Actions, permissions and
generated client, as well as the domain docs. Research unresolved business behaviour through
[research-and-design.md](research-and-design.md); wiring an already-decided contract needs no
fresh ERP comparison.

The specification names users, frequent decisions, lifecycle/status actions, record scope,
screen inventory and enabled capability modes. Include a coverage matrix:

| Contract element | UI destination | Availability | Acceptance scenario / omission |
|---|---|---|---|
| Each action or transition | Row action, form, detail review or setup | Status, permission, mode, preconditions | Outcome, confirmation, refusal and refresh |
| Each editable business field | Main form, advanced group or derived value | Create/edit, defaults, validation | Saved value and reopening behaviour |
| Each useful response fact | Default/optional column, card, detail or history | Permission and mode | Correct label, relationship, unit/currency |
| Each validated filter and sort | Frequent/advanced filter or sort control | Endpoint scope and permission | Request, result and URL state |
| Each enabled capability | All affected surfaces above | Company configuration plus access | Complete workflow in each supported mode |

Classify each entry as implemented, not applicable with a reason, or deliberately deferred with
its reason and agreed delivery scope. Do not silently omit fields/actions because the first
draft did not need them. Response identifiers and internal metadata need not become columns;
unrelated backend capabilities need not acquire screens. A deliberate scope exclusion is visible
in the specification and handoff, not only in an agent's final message.

## Record presentation and navigation

Create, edit and detail records default to right-hand `RecordSheet` children of their list,
using the presentation registry and shared content. Keep direct links and the page alternative.
A dedicated workspace may use a page when simultaneous cross-record work or interaction needs
justify it; record the exception and obtain the owner's agreement before departing from this
default. A preview or a dense form alone is not an automatic exception—PO supports both in sheets.

Carry search/filter/sort/page through open, edit, save, cancel and close. Direct entry without a
list context uses a sensible list fallback. Mutation success refreshes affected list/detail
queries; stale, unavailable and denied records offer a safe recovery route. Closing a dirty form
uses the shared unsaved-change pattern rather than silently discarding input.

## Lists answer the operator's question

Choose default and optional columns deliberately. Consider identity and status; related parties,
warehouse or owner; operational dates, quantities, line counts and outstanding work; permitted
financial amounts with currency; and actionable exceptions. Record why a category does not apply.
Provide enough information to identify, compare and act without opening every record. Never sum
incompatible quantities or currencies into a misleading total. Resolve human labels through real
response relationships or by-id lookups, not assumed nested objects or first-page searches.

Use the shared column catalogue for table and mobile cards, saved column visibility and view
preferences, formatting and semantic badges. Essential identity/status remains understandable
in both layouts. Optional columns are discoverable; fields unavailable to the person never leak
through cards, print or export.

Expose this screen's validated filters and sort fields or document a deliberate exclusion.
Search wording matches the server's searched fields. Frequent filters sit beside search; rare
criteria use advanced disclosure; mobile secondary filters use the shared filter sheet. Provide
active chips, individual removal, Clear all and a useful filtered-empty state. Reference pickers
search the server and preserve selected labels. Filter changes reset pagination appropriately.
Sort direction is visible, and a supported sort remains reachable when its column is hidden
(through an explicit sort control or a documented Columns → header interaction). Paginate on
the server, preserve page size and handle a page becoming empty after mutation.

## Row actions and selection

Assess safe bulk use cases explicitly; checkboxes are required when an applicable bulk action is
delivered, not on every table regardless of purpose. Use shared row actions and selection.
Show only permitted actions, distinguish unavailable preconditions with a reason, and send
controlled transitions through the record's review/confirmation flow.

For bulk operations, specify current-page versus all-matching scope. Default to current-page
selection; all-matching needs an explicit supported server contract. Include row/select-all
checkboxes, selected count, selected styling and bulk toolbar; clear selection when page, sort
or filters change. Define mixed-status eligibility, permission checks, confirmations, partial
failure/retry results and refresh behaviour. Do not infer bulk posting safety from a single-record
endpoint. Printing/export and approvals are conditional feature decisions, not universal PO extras.

## Detail and forms

Detail presents identity/status, related parties and references, lines with relevant totals or
summaries, state-valid actions, reasons and history. Link related records where useful. Distinguish
business dates and event times; show quantities with units and amounts with currencies.

Create/edit reuse form content, defaults, validation and advanced-option semantics. Support
keyboard-efficient repeated lines, searchable references, inline and server field errors, retained
input after failure, pending-submit protection and review before consequential actions. Use a
wizard only when steps help the task. Reopening an existing override reveals its group; hiding
presentation never silently changes saved business configuration. Follow
[capability-delivery-contract.md](capability-delivery-contract.md) for modes and disclosure.

## Completion evidence

Reconcile every coverage-matrix entry against the delivered UI before claiming completion.
Browser acceptance checks cover relevant filter requests/results, sorting, overview relationships,
context preservation, save/reopen/refresh, applicable selection and enabled-mode behaviour, as
well as the critical journey, permissions, phone width, keyboard and light/dark accessibility.
Use deterministic fixtures and meaningful behavioural assertions. Report agreed omissions and
unverified scenarios explicitly; a passing happy path or screenshot does not prove coverage.

## Related

- [UI delivery](user-interface.md) · [Screen design standard](erp-blue-design-standard.md)
- [Building blocks](ui-building-blocks.md) · [Capability delivery](capability-delivery-contract.md)
- [Completion checklist](feature-completion-checklist.md) · [Testing](testing-standards.md)
