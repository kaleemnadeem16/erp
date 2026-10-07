<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use App\Modules\Inventory\Models\NegativeStockOverrideReview;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use App\Modules\Inventory\Support\NegativeStockReviewOutcome;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Support\Facades\DB;

final class ReviewNegativeStockOverride
{
    public function __invoke(NegativeStockOverrideRequest $request, NegativeStockReviewOutcome $outcome, string $notes, User $actor): NegativeStockOverrideReview
    {
        return DB::transaction(function () use ($request, $outcome, $notes, $actor): NegativeStockOverrideReview {
            if (trim($notes) === '') {
                throw new DomainInvariantException('A review outcome requires notes.', 'notes');
            }
            $request = NegativeStockOverrideRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->authorisation_mode !== NegativeStockAuthorisationMode::ImmediateWithReview) {
                throw new DomainInvariantException('Only immediate overrides receive a review outcome.', 'request_id');
            }
            if (! $request->allow_self_review && $request->requested_by === $actor->id) {
                throw new DomainInvariantException('Independent review is required by company policy.', 'reviewed_by');
            }
            if (! $actor->can('inventory.negative-stock.review')) {
                throw new DomainInvariantException('The actor is not permitted to review negative-stock overrides.', 'reviewed_by');
            }
            WarehouseScope::requireLocation($actor, 'inventory.negative-stock.review', $request->location_id, 'review a negative-stock override');
            $existing = NegativeStockOverrideReview::query()->where('request_id', $request->id)->first();
            if ($existing !== null) {
                if ($existing->outcome !== $outcome || $existing->reviewed_by !== $actor->id || $existing->notes !== trim($notes)) {
                    throw new DomainInvariantException('This request already has a different review outcome.', 'request_id');
                }

                return $existing;
            }

            return NegativeStockOverrideReview::query()->create([
                'request_id' => $request->id, 'outcome' => $outcome, 'notes' => trim($notes),
                'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            ]);
        });
    }
}
