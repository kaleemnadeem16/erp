<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\NegativeStockOverrideApproval;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use Illuminate\Support\Facades\DB;

final class DecideNegativeStockOverride
{
    public function __invoke(NegativeStockOverrideRequest $request, NegativeStockApprovalDecision $decision, string $notes, User $actor): NegativeStockOverrideApproval
    {
        return DB::transaction(function () use ($request, $decision, $notes, $actor): NegativeStockOverrideApproval {
            if (trim($notes) === '') {
                throw new DomainInvariantException('An approval decision requires notes.', 'notes');
            }
            $request = NegativeStockOverrideRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->authorisation_mode !== NegativeStockAuthorisationMode::PriorApproval) {
                throw new DomainInvariantException('Only prior-approval requests receive an approval decision.', 'request_id');
            }
            if ($request->requested_by === $actor->id) {
                throw new DomainInvariantException('Prior approval must be decided by a different user.', 'decided_by');
            }
            if (! $actor->can('inventory.negative-stock.approve')) {
                throw new DomainInvariantException('The actor is not permitted to decide negative-stock requests.', 'decided_by');
            }
            $existing = NegativeStockOverrideApproval::query()->where('request_id', $request->id)->first();
            if ($existing !== null) {
                if ($existing->decision !== $decision || $existing->decided_by !== $actor->id || $existing->notes !== trim($notes)) {
                    throw new DomainInvariantException('This request already has a different approval decision.', 'request_id');
                }

                return $existing;
            }

            return NegativeStockOverrideApproval::query()->create([
                'request_id' => $request->id, 'decision' => $decision, 'notes' => trim($notes),
                'decided_by' => $actor->id, 'decided_at' => now(),
            ]);
        });
    }
}
