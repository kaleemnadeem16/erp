<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Models\InventoryPolicyChange;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

final class UpdateLocation
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(Location $location, array $attributes, ?int $actorId = null): Location
    {
        return DB::transaction(function () use ($location, $attributes, $actorId): Location {
            $structuredFields = ['parent_id', 'type', 'barcode', 'sort_order', 'allows_stock', 'counts_as_usable'];
            if (array_intersect(array_keys($attributes), $structuredFields) !== []) {
                $company = Company::query()->lockForUpdate()->findOrFail($this->companyContext->id());
                if ($company->inventory_location_mode !== InventoryLocationMode::Structured) {
                    throw new DomainInvariantException(
                        'Structured location fields require STRUCTURED inventory location mode.',
                        'type',
                    );
                }
            }

            if (($attributes['active'] ?? true) === false && $location->active
                && $location->warehouse->locations()->where('active', true)->count() === 1) {
                throw new DomainInvariantException('A usable warehouse must retain at least one active location.', 'active');
            }
            $reason = trim((string) ($attributes['counts_as_usable_reason'] ?? ''));
            unset($attributes['counts_as_usable_reason']);
            $usabilityChanged = array_key_exists('counts_as_usable', $attributes)
                && $attributes['counts_as_usable'] !== $location->counts_as_usable;
            if ($usabilityChanged && ($actorId === null || $reason === '')) {
                throw new DomainInvariantException('Changing what counts as usable stock requires an actor and a reason.', 'counts_as_usable_reason');
            }
            $before = $location->counts_as_usable;
            $location->update($attributes);
            if ($usabilityChanged) {
                InventoryPolicyChange::query()->create([
                    'policy' => 'location_usability',
                    'old_values' => ['location_id' => $location->id, 'counts_as_usable' => $before],
                    'new_values' => ['location_id' => $location->id, 'counts_as_usable' => $location->counts_as_usable],
                    'reason' => $reason, 'changed_by' => $actorId, 'changed_at' => now(),
                ]);
            }

            return $location->refresh();
        });
    }
}
