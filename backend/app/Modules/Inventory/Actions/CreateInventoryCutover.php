<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Support\InventoryCutoverMode;
use Illuminate\Support\Facades\DB;

final class CreateInventoryCutover
{
    /** @param array{mode:string,cutover_at:mixed,source_system:string,target_valuation_policy:array<string,mixed>,control_quantity:string,control_value:string,base_control_value:string} $input */
    public function __invoke(array $input, User $actor): InventoryCutover
    {
        if (! $actor->can('inventory.cutover.create')) {
            throw new DomainInvariantException('The actor is not permitted to create an inventory cutover.', 'created_by');
        }
        if (trim($input['source_system']) === '' || $input['target_valuation_policy'] === []) {
            throw new DomainInvariantException('Source system and target valuation policy are required.');
        }

        return DB::transaction(function () use ($input, $actor): InventoryCutover {
            return InventoryCutover::query()->create([
                ...$input,
                'mode' => InventoryCutoverMode::from($input['mode']),
                'source_system' => trim($input['source_system']),
                'created_by' => $actor->id,
            ])->refresh();
        });
    }
}
