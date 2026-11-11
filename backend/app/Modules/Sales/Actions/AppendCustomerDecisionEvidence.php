<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionAccess;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionOperation;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionOutcome;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Appends one textual observation to a case (CA1): what the customer sent, from which source, with a truthful occurrence precision (unknown stays unknown) and how the sender's
 * authority was verified. It is a fact about what was observed — never consent, never acceptance — and consumes no quotation capacity, however late or contradictory. The case is
 * locked and the caller's expected generation must be its head (a moved case is stale); a correction or later version names an earlier observation of the same case as its
 * predecessor, and a chain never forks. No files: textual evidence only. Guarded by `sales.customer-decision.evidence.record` (STAGED) and an active member, judged again
 * inside the operation before any replay.
 */
final class AppendCustomerDecisionEvidence
{
    private const FIELDS = ['source_kind', 'external_reference', 'source_version', 'customer_representative_snapshot', 'authority_verification_basis', 'occurred_at', 'occurred_until',
        'occurrence_precision', 'source_timezone', 'factual_description'];

    public function __construct(private readonly CustomerDecisionOperation $operations) {}

    /** @param  array<string, mixed>  $fields */
    public function __invoke(User $actor, ?string $requestKey, int $caseId, int $expectedGeneration, array $fields, ?int $predecessorId = null): CustomerDecisionOutcome
    {
        $this->authorise($actor);
        if (array_diff(array_keys($fields), self::FIELDS) !== [] || ! isset($fields['source_kind'], $fields['authority_verification_basis'], $fields['occurrence_precision'], $fields['factual_description'])) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::INVALID_INPUT, 'An observation needs a source, an authority basis, an occurrence precision and a factual description, and nothing else.', 'evidence');
        }
        $fields = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $fields);

        $outcome = $this->operations->run(
            $actor, 'customer_decision.append_evidence', $requestKey, ['case_id' => $caseId, 'expected_generation' => $expectedGeneration, 'predecessor_id' => $predecessorId, 'evidence' => $fields],
            function () use ($actor, $caseId): CustomerDecisionCase {
                $this->decide($actor);

                return CustomerDecisionCase::query()->whereKey($caseId)->lockForUpdate()->first()
                    ?? throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::CASE_NOT_FOUND, 'The decision case was not found.', 'case_id');
            },
            function (CustomerDecisionCase $case) use ($actor, $expectedGeneration, $fields, $predecessorId): array {
                if ($case->generation !== $expectedGeneration) {
                    throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::CASE_STALE, "The case is at generation {$case->generation}, not {$expectedGeneration}; reload it before adding to it.", 'expected_generation');
                }
                if ($predecessorId !== null && (CustomerDecisionEvidence::query()->where('case_id', $case->id)->whereKey($predecessorId)->doesntExist()
                    || CustomerDecisionEvidence::query()->where('predecessor_id', $predecessorId)->exists())) {
                    throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::EVIDENCE_PREDECESSOR_INVALID, 'A predecessor is an earlier observation of this case that has no successor yet.', 'predecessor_id');
                }
                try {
                    $evidence = CustomerDecisionEvidence::create($this->instants($fields) + ['case_id' => $case->id, 'predecessor_id' => $predecessorId, 'observation_state' => 'OBSERVED', 'recorded_by' => $actor->id, 'recorded_at' => now()]);
                    $case->forceFill(['generation' => $case->generation + 1])->save();
                } catch (QueryException $e) {
                    if (($e->errorInfo[0] ?? '') === '23514' || ($e->errorInfo[0] ?? '') === '22007' || ($e->errorInfo[0] ?? '') === '22P02') {
                        throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::INVALID_INPUT, 'The observation is not valid: check its source, authority basis, occurrence precision and dates.', 'evidence');
                    }
                    throw $e;
                }

                return CustomerDecisionOutcome::result($case->id, $case->generation, $evidence->id);
            },
        );

        return CustomerDecisionOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }

    /**
     * The model writes a time without its offset, and the database reads that as UTC: an instant sent as "10:00+05:00" would be stored as 10:00 UTC, five hours late. So a time is
     * converted to the same instant in UTC here, exactly once, before it is written; a value that is not a time is refused like any other invalid observation.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function instants(array $fields): array
    {
        foreach (['occurred_at', 'occurred_until'] as $name) {
            if (isset($fields[$name]) && $fields[$name] !== '') {
                try {
                    $fields[$name] = CarbonImmutable::parse((string) $fields[$name])->utc()->format('Y-m-d H:i:s.u');
                } catch (\Throwable) {
                    throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::INVALID_INPUT, 'The observation is not valid: check its source, authority basis, occurrence precision and dates.', 'evidence');
                }
            }
        }

        return $fields;
    }

    private function authorise(User $actor): void
    {
        ActiveActor::require($actor, 'record customer decision evidence');
        SalesAccess::require($actor, PermissionVocabulary::CUSTOMER_DECISION_EVIDENCE_RECORD, 'record customer decision evidence');
    }

    /** Inside the transaction, before any lock or receipt look-up: the access fence, then the person as stored now. */
    private function decide(User $actor): void
    {
        CustomerDecisionAccess::decide($actor, PermissionVocabulary::CUSTOMER_DECISION_EVIDENCE_RECORD, 'record customer decision evidence');
    }
}
