<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/** Edits or cancels a Supplier Return draft (ADR 0097). Only a draft can change; a draft still moves nothing. */
final class CancelSupplierReturn
{
    /** Cancels a draft. A cancelled document keeps its lines for the record and is immutable. */
    public function __invoke(SupplierReturn $document, User $actor): SupplierReturn
    {
        ActionAuthorization::require($actor, 'purchasing.supplier-return.create', 'cancel a Supplier Return');
        WarehouseScope::requireWarehouse($actor, ['purchasing.supplier-return.create', 'purchasing.supplier-return.post'], (int) $document->warehouse_id, 'cancel a Supplier Return');

        return DB::transaction(function () use ($document, $actor): SupplierReturn {
            $locked = SupplierReturn::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->status === SupplierReturn::CANCELLED) {
                return $locked->load('lines');
            }
            if (! $locked->isDraft()) {
                throw new DomainInvariantException('Only a draft can be cancelled; this document is posted.', 'status', 'DOCUMENT_NOT_DRAFT');
            }
            $locked->forceFill(['status' => SupplierReturn::CANCELLED, 'cancelled_by' => $actor->id, 'cancelled_at' => now()])->save();

            return $locked->load('lines');
        });
    }
}
