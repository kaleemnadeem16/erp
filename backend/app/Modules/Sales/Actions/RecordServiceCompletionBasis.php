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
 * Records the customer-agreed completion conditions of one accepted service line as a new immutable version. Two honest ways (the database guard enforces both):
 *  - the lightweight default: the issued line's own description, verbatim, citing the acceptance's own consent evidence (pass `$agreedText` null) — states no material or
 *    subcontractor scope, so source applicability stays UNKNOWN;
 *  - new or extra conditions, or any EXCLUDED/REQUIRED scope: DEDICATED written, authority-verified evidence of the accepting case that is not the acceptance's own consent
 *    evidence, recorded after it, whose factual description IS the agreed text. An employee cannot invent an obligation or an exclusion and cite old general acceptance evidence.
 * Never backfills agreement; the same intent again is an idempotent no-op. No route, no Job, no release.
 */
final class RecordServiceCompletionBasis
{
    public const SCOPES = ['EXCLUDED', 'REQUIRED', 'UNSTATED'];

    public function __construct(private readonly CustomerDecisionOperation $operations, private readonly HandoverAccess $access) {}

    /** @return array{basis_id:int,version:int,case_id:int,replayed:bool} */
    public function __invoke(User $actor, ?string $requestKey, int $acceptanceId, ?string $agreedText, int $evidenceId, string $materialsScope = 'UNSTATED', string $subcontractScope = 'UNSTATED'): array
    {
        HandoverAccess::early($actor, false, P::HANDOVER_COMPLETION_BASIS_RECORD);
        $text = $agreedText === null ? null : trim($agreedText);
        if ($text !== null && ($text === '' || mb_strlen($text) > 4000)) {
            throw R::make(R::INVALID_INPUT, 'The agreed completion conditions must be 1 to 4000 characters.', 'agreed_text');
        }
        foreach (['materials_scope' => $materialsScope, 'subcontract_scope' => $subcontractScope] as $field => $value) {
            if (! in_array($value, self::SCOPES, true)) {
                throw R::make(R::INVALID_INPUT, 'A scope is EXCLUDED, REQUIRED or UNSTATED.', $field);
            }
        }
        $payload = ['acceptance_id' => $acceptanceId, 'text' => $text, 'evidence_id' => $evidenceId, 'materials' => $materialsScope, 'subcontract' => $subcontractScope];
        $digest = OperationIntent::hash('handover.basis.record', $payload);
        $outcome = $this->operations->run($actor, 'handover.basis.record', $requestKey, $payload,
            fn (): HandoverSubject => $this->access->lock($actor, $acceptanceId, false, P::HANDOVER_COMPLETION_BASIS_RECORD),
            function (HandoverSubject $s) use ($actor, $text, $evidenceId, $materialsScope, $subcontractScope, $digest): array {
                $head = ServiceCompletionBasis::query()->where('issued_line_id', $s->line->id)->orderByDesc('version')->first();
                if ($head !== null && $head->intent_digest === $digest) {
                    return ['basis_id' => $head->id, 'version' => $head->version, 'case_id' => $s->case->id];
                }
                $evidence = CustomerDecisionEvidence::query()->where(['id' => $evidenceId, 'case_id' => $s->case->id])->first();
                if ($evidence === null || $evidence->source_kind === 'VERBAL' || ! in_array($evidence->authority_verification_basis, ['DOCUMENT_ON_FILE', 'CONFIRMED_BY_SELLER'], true)) {
                    throw R::make(R::INVALID_INPUT, 'Cite written, authority-verified consent evidence of the accepting case.', 'evidence_id');
                }
                if (ServiceCompletionBasis::query()->where(['issued_line_id' => $s->line->id, 'evidence_id' => $evidence->id])->exists()) {
                    throw R::make(R::INVALID_INPUT, 'This evidence already supports a completion basis of this line; record new agreement evidence for a change.', 'evidence_id');
                }
                $ownConsent = $evidence->id === (int) $s->acceptance->consent_evidence_id;
                if ($ownConsent) {
                    $issued = trim((string) $s->line->description);
                    if (($text !== null && $text !== $issued) || $materialsScope !== 'UNSTATED' || $subcontractScope !== 'UNSTATED') {
                        throw R::make(R::INVALID_INPUT, 'The acceptance evidence supports only the issued line description; new conditions or a scope need their own customer-agreement evidence.', 'agreed_text');
                    }
                    $text = $issued;
                } else {
                    $consent = CustomerDecisionEvidence::query()->findOrFail($s->acceptance->consent_evidence_id);
                    if ($text === null || $evidence->recorded_at->lte($consent->recorded_at) || trim($evidence->factual_description) !== $text) {
                        throw R::make(R::INVALID_INPUT, 'New conditions need written evidence recorded after the acceptance whose description is exactly the agreed text.', 'evidence_id');
                    }
                }
                $basis = ServiceCompletionBasis::query()->create(['issued_line_id' => $s->line->id, 'version' => ($head === null ? 0 : $head->version) + 1, 'predecessor_id' => $head?->id,
                    'agreed_text' => $text, 'evidence_id' => $evidence->id, 'intent_digest' => $digest, 'recorded_by' => $actor->id, 'terms_source' => $ownConsent ? 'ISSUED_LINE' : 'EVIDENCE_TEXT',
                    'materials_scope' => $materialsScope, 'subcontract_scope' => $subcontractScope]);

                return ['basis_id' => $basis->id, 'version' => $basis->version, 'case_id' => $s->case->id];
            });

        return ['basis_id' => (int) $outcome['result']['basis_id'], 'version' => (int) $outcome['result']['version'], 'case_id' => (int) $outcome['result']['case_id'], 'replayed' => $outcome['replayed']];
    }
}
