<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\ServiceCompletionBasis;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use App\Modules\Sales\Support\Handover\Release\SalesCompletionBasis;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Support\Facades\DB;

/**
 * The CURRENT (head) agreed completion conditions of one accepted service: version, exact agreed text, how it was agreed and whether the customer evidence behind it is still the
 * current observation. For a person who may record those conditions or release the service (each judged by its own exact duty, plus the acceptance and quotation views). Not other
 * versions, not other evidence, no source pins, no money. Reads only, under the same locks as the release.
 */
final class ReadCompletionBasis
{
    public function __construct(private readonly HandoverAccess $access, private readonly SalesCompletionBasis $bases) {}

    /** @return array{acceptance_id:int,current:array{basis_id:int,version:int,terms_source:string,agreed_text:string,materials_scope:string,subcontract_scope:string,evidence_current:bool,recorded_at:string}|null} */
    public function __invoke(User $actor, int $acceptanceId): array
    {
        $duty = SalesAccess::may($actor, P::HANDOVER_RELEASE) ? P::HANDOVER_RELEASE : P::HANDOVER_COMPLETION_BASIS_RECORD;
        HandoverAccess::early($actor, false, $duty);

        return DB::transaction(function () use ($actor, $acceptanceId, $duty): array {
            $subject = $this->access->lock($actor, $acceptanceId, false, $duty);
            $basis = $this->bases->find($subject->acceptanceLine);
            if ($basis === null) {
                return ['acceptance_id' => $acceptanceId, 'current' => null];
            }
            $row = ServiceCompletionBasis::query()->findOrFail($basis->basisId);
            $superseded = $this->bases->evidenceSuperseded($basis);

            return ['acceptance_id' => $acceptanceId, 'current' => ['basis_id' => $basis->basisId, 'version' => $basis->version, 'terms_source' => $basis->termsSource, 'agreed_text' => $basis->agreedText,
                'materials_scope' => $basis->materialsScope, 'subcontract_scope' => $basis->subcontractScope, 'evidence_current' => ! $superseded, 'recorded_at' => $row->recorded_at->toIso8601String()]];
        });
    }
}
