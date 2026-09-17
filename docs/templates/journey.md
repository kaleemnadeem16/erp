[← Product journeys](../journeys/README.md)

# Journey — Name

- **Implementation status:** implemented | partial | planned
- **Audience:** role(s) performing or approving the journey
- **Starting point:** route, event or external trigger
- **Successful end state:** observable business result
- **Last verified:** YYYY-MM-DD

## Purpose

What business outcome does this journey achieve, and why does the user perform it?

## Before you start

List required master data, permissions, policy settings, open documents and physical facts.
Link to the authoritative domain document for each important prerequisite.

## Journey map

```text
Entry → Step 1 → Step 2 → Decision/exception → Step 3 → End state
```

## Steps

For every step, record:

- **Screen/action:** route or named action
- **User does:** the decision or data entry
- **System does:** validation, status change and side effects
- **Next:** where the user goes, including a blocked/exception branch

## Status and consequence table

| Object | Status/action | What it means | What becomes possible or blocked |
|---|---|---|---|
| … | … | … | … |

## Exceptions and corrections

Explain the safe path for partial work, rejection, reversal, cancellation, approval, timeout or
data correction. Say what is not available yet when the next phase owns it.

## Not yet available

List adjacent steps that are intentionally deferred, with the owning phase.

## Source of truth

- Domain: [link]
- Architecture: [link]
- ADRs: [link]
- UI routes/code: [link]

## Related

- [Product journeys](../journeys/README.md)
