<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\StockScrap;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/** Edits or cancels a Scrap document draft (ADR 0097). Only a draft can change; a draft still moves nothing. */
final class UpdateStockScrap
{
    public function __construct(private readonly CreateStockScrap $create) {}

    /** @param  array<string,mixed>  $input */
    public function __invoke(StockScrap $document, array $input, User $actor): StockScrap
    {
        ActionAuthorization::require($actor, 'inventory.scrap.create', 'edit a Scrap document');
        WarehouseScope::requireWarehouse($actor, ['inventory.scrap.create', 'inventory.scrap.post'], (int) $document->warehouse_id, 'edit a Scrap document');

        return DB::transaction(function () use ($document, $input, $actor): StockScrap {
            $locked = StockScrap::query()->lockForUpdate()->findOrFail($document->id);
            if (! $locked->isDraft()) {
                throw new DomainInvariantException('Only a draft can be edited; this document is '.strtolower($locked->status).'.', 'status', 'DOCUMENT_NOT_DRAFT');
            }
            $this->create->fill($locked, $input, $actor);

            return $this->create->save($locked, $input, $actor);
        });
    }
}
