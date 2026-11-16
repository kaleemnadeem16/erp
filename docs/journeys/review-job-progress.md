[← Product journeys](README.md)

# Review Job progress

## Audience and prerequisites

An employee reviews agreed operational work, responsibility and its recorded history.
The company's `job.view` duty must be ACTIVE, the employee currently active, and their
exact permission must have ALL scope. **All shipped Jobs duties remain SPECIFIED:**
these screens are unavailable to production users until a separate approved rollout.
A Sales duty, a close duty or a handcrafted inactive grant does not enable this journey.

## Find the work

Open **Jobs** when navigation is available. Search the Job number or short description,
filter by status, or open **More filters** (phone: **Filters**) for open holds, exact
responsible employee id and inclusive creation dates. Employee ids are shown in the
assignment details; this read screen does not grant access to the employee directory.
Remove an individual chip or clear all filters if nothing matches.

The list shows the Job's number, description, recorded status, responsible employee,
open-hold flag and creation date. Columns also offer release and start dates. Use the
column/sort controls and server pagination to compare work; phones default to cards.

## Review the record

Open a row by click or keyboard Enter. The detail sheet shows original agreed scope,
deliverable and conditions, current assignment and intended dates, default activity,
and unresolved holds. Missing facts are labelled rather than inferred.

Completion and operational closeout appear separately when immutable records exist.
Their recorded timestamps are server event times. A claimed occurrence, if supplied,
is labelled with its precision. Closing a Job is not a financial settlement.

Read **Job history** in recorded sequence: release, assignment, start, holds and their
resolutions, completion and close. Previous/Next history controls paginate on the server.
A claimed occurrence never changes this authoritative ordering.

Close the sheet or press Escape to return to the same list filters, sort and page.
The registered page presentation also returns to the same list context.

## Exceptions and effects

No Jobs: no work has been released, or the current filters match nothing. A failure
explains the refusal and offers retry. Missing and foreign records are indistinguishable.
A revoked/inactive permission or suspended membership is refused on subsequent requests;
the screens confer no authority beyond the protected API.

This journey only reads existing data. It has no assignment, start, hold, complete or
close controls, bulk mutation, print/export, financial values, private customer evidence
or permission activation. Independent integration review still gates the pilot.

## Related

- [Jobs read API and screen coverage](../domain/job-read-api.md)
- [Job lifecycle](../domain/job-execution-lifecycle.md)
- [Phase 5](../phases/phase-5-job-execution.md)
