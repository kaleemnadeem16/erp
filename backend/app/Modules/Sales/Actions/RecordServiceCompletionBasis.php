<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\ServiceCompletionBasis;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionOperation;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal as R;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use App\Modules\Sales\Support\Handover\HandoverSubject;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Modules\Sales\Support\PermissionVocabulary as P;

/**
 * Records the customer-agreed completion conditions of one accepted service line as a new immutable version, citing written consent evidence of the accepting case.
 * Never backfills agreement: it needs a genuine acceptance, and the same text and evidence again is an idempotent no-op. No route, no Job, no release.
 */
final class RecordServiceCompletionBasis
{
    public function __construct(private readonly CustomerDecisionOperation $operations, private readonly HandoverAccess $access) {}

    /** @return array{basis_id:int,version:int,case_id:int,replayed:bool} */
    public function __invoke(User $actor, ?string $requestKey, int $acceptanceId, string $agreedText, int $evidenceId): array
    {
        HandoverAccess::early($actor, false, P::HANDOVER_COMPLETION_BASIS_RECORD);
        $text = trim($agreedText);
        if ($text === '' || mb_strlen($text) > 4000) {
            throw R::make(R::INVALID_INPUT, 'The agreed completion conditions must be 1 to 4000 characters.', 'agreed_text');
        }
        $digest = OperationIntent::hash('handover.basis.record', ['acceptance_id' => $acceptanceId, 'text' => $text, 'evidence_id' => $evidenceId]);
        $outcome = $this->operations->run($actor, 'handover.basis.record', $requestKey, ['acceptance_id' => $acceptanceId, 'text' => $text, 'evidence_id' => $evidenceId],
            fn (): HandoverSubject => $this->access->lock($actor, $acceptanceId, false, P::HANDOVER_COMPLETION_BASIS_RECORD),
            function (HandoverSubject $s) use ($actor, $text, $evidenceId, $digest): array {
                $evidence = CustomerDecisionEvidence::query()->where(['id' => $evidenceId, 'case_id' => $s->case->id])->first();
                if ($evidence === null || $evidence->source_kind === 'VERBAL' || $evidence->authority_verification_basis === 'UNVERIFIED') {
                    throw R::make(R::INVALID_INPUT, 'Cite written, authority-bearing consent evidence of the accepting case.', 'evidence_id');
                }
                $head = ServiceCompletionBasis::query()->where('issued_line_id', $s->line->id)->orderByDesc('version')->first();
                if ($head !== null && $head->intent_digest === $digest) {
                    return ['basis_id' => $head->id, 'version' => $head->version, 'case_id' => $s->case->id];
                }
                $basis = ServiceCompletionBasis::query()->create(['issued_line_id' => $s->line->id, 'version' => ($head === null ? 0 : $head->version) + 1, 'predecessor_id' => $head?->id,
                    'agreed_text' => $text, 'evidence_id' => $evidence->id, 'intent_digest' => $digest, 'recorded_by' => $actor->id]);

                return ['basis_id' => $basis->id, 'version' => $basis->version, 'case_id' => $s->case->id];
            });

        return ['basis_id' => (int) $outcome['result']['basis_id'], 'version' => (int) $outcome['result']['version'], 'case_id' => (int) $outcome['result']['case_id'], 'replayed' => $outcome['replayed']];
    }
}
