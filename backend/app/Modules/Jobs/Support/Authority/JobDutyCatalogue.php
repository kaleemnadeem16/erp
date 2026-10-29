<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Authority;

/**
 * The 23 Jobs duties proposed by Task F (docs/phases/phase-5-task-f-jobs-access-plan.md), exactly and in that order. All are SPECIFIED: no guarded Jobs consumer
 * exists, so none is STAGED and none is ACTIVE, and nothing here is registered, granted or enforced by an Action. A duty moves to STAGED only in the change that
 * delivers its consumer, and to ACTIVE only after the strict no-automatic-growth, holder and staging gates — never by editing a flag alone.
 */
final class JobDutyCatalogue
{
    /** @return list<JobDuty> */
    public static function all(): array
    {
        $specified = static fn (string $name, string $operation, string $projection, string $gate): JobDuty => new JobDuty($name, JobDutyStatus::Specified, $operation, $projection, $gate);

        return [
            $specified('job.view', 'Company operational list, worksheet and general operational history', 'No costs and no private source evidence', 'Safe query/read adapter; named operational reader'),
            $specified('job.receive', 'Internal, Sales-coordinator-only receipt of a verified released scope', 'Safe result references only', 'Actual receiver/verifier and transaction proof; the releaser must also be independently eligible under Sales; no receiver route'),
            $specified('job.assign', 'Append a supported responsibility or window change', 'Only the eligible-person selector it needs', 'User/team identity policy; named dispatcher'),
            $specified('job.start', 'Explicit supported execution start', 'Only the Job, baseline and hold context it needs', 'Assignment and any reviewed on-behalf context checked independently'),
            $specified('job.hold', 'Place one independent hold with a reason', 'Safe affected-work context', 'Applicable Sales hold authority is separate'),
            $specified('job.hold.resolve', 'Resolve one named hold after current prerequisites; other holds remain', 'Safe hold context', 'Neither the hold creator nor job.hold implies resolution'),
            $specified('job.complete', 'Attest the canonical fixed deliverable; no hours or cost prerequisite', 'Safe completion context', 'Actual pinned conditions, fulfilment and approval where configured'),
            $specified('job.completion.withdraw', 'Governed withdrawal of an unclosed completion and its dependent effects', 'Original evidence and dependent reconciliation', 'Original evidence and every dependent reconciliation'),
            $specified('job.cancel_unused', 'Cancel a proven-unused Job', 'Safe remainder and impact context', 'Actual proof and receipts; not a Sales claim release'),
            $specified('job.stop', 'Stop a worked Job, retaining actuals and the responsible remainder', 'Safe actuals-retained context', 'Source obligations are not cancelled implicitly'),
            $specified('job.close', 'Safe readiness assessment and operational close; no cost values', 'Close-only safe record profile (no job.view or job.cost.view prerequisite)', 'Independent safe record/readiness profile; actual source inventory and dispositions'),
            $specified('job.history.record', 'Capture a truthful late or held report with its original context', 'Original context only', 'No recognition or fulfilment inference'),
            $specified('job.history.review', 'Classify a historical or held report', 'Legitimate evidence access only', 'No automatic financial or source posting'),
            $specified('job.history.correct', 'Apply a supported operational historical correction lineage', 'Correction lineage', 'Independent source correction authority where effects require it'),
            $specified('job.labour.record', 'Record optional supported internal labour evidence', 'Authorised input only', 'J2 method/context and own/on-behalf policy'),
            $specified('job.labour.amount.declare', 'Declare accountable direct incurred internal labour', 'Declaration only', 'labour.record also required for recording; no external/procurement bypass'),
            $specified('job.labour.recognise', 'Recognise an exact supported labour revision and allocate once', 'Exact revision', 'Method, review and evidence policy; not implied by record'),
            $specified('job.labour.rate.adopt', 'Adopt and pin a supported actual class-rate basis', 'Pinned rate basis', 'Later usage/rate adapter; no live-master recost'),
            $specified('job.labour.correct', 'Governed correction of original or effective labour quantity, value or scope', 'Old and new scope', 'Mandatory dependent effects'),
            $specified('job.cost.view', 'Current-authorised cost projection with certainty and coverage', 'Cost with certainty and coverage', 'Real source/report consistency and independent source fields'),
            $specified('job.cost.coverage.declare', 'Scoped accountable completeness declaration', 'Coverage declaration', 'Supported inventory; no known omission or dispute; no automatic confirmation'),
            $specified('job.cost.coverage.reconcile', 'Authoritative source-backed coverage reconciliation', 'Reconciliation evidence', 'Actual complete writer and source inventory'),
            $specified('job.cost.export', 'Export exactly the permitted cost projection', 'Exactly the permitted projection', 'job.cost.view also required; independent files/download/retention adapter if applicable'),
        ];
    }
}
