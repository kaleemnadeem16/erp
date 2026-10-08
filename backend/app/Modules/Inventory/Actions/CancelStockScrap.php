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
final class CancelStockScrap
{
    /** Cancels a draft. A cancelled document keeps its lines for the record and is immutable. */
    public function __invoke(StockScrap $document, User $actor): StockScrap
    {
        ActionAuthorization::require($actor, 'inventory.scrap.create', 'cancel a Scrap document');
        WarehouseScope::requireWarehouse($actor, ['inventory.scrap.create', 'inventory.scrap.post'], (int) $document->warehouse_id, 'cancel a Scrap document');

        return DB::transaction(function () use ($document, $actor): StockScrap {
            $locked = StockScrap::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->status === StockScrap::CANCELLED) {
                return $locked->load('lines');
            }
            if (! $locked->isDraft()) {
                throw new DomainInvariantException('Only a draft can be cancelled; this document is posted.', 'status', 'DOCUMENT_NOT_DRAFT');
            }
            $locked->forceFill(['status' => StockScrap::CANCELLED, 'cancelled_by' => $actor->id, 'cancelled_at' => now()])->save();

            return $locked->load('lines');
        });
    }
}
