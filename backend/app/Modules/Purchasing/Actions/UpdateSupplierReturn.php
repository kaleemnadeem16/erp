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
final class UpdateSupplierReturn
{
    public function __construct(private readonly CreateSupplierReturn $create) {}

    /** @param  array<string,mixed>  $input */
    public function __invoke(SupplierReturn $document, array $input, User $actor): SupplierReturn
    {
        ActionAuthorization::require($actor, 'purchasing.supplier-return.create', 'edit a Supplier Return');
        WarehouseScope::requireWarehouse($actor, ['purchasing.supplier-return.create', 'purchasing.supplier-return.post'], (int) $document->warehouse_id, 'edit a Supplier Return');

        return DB::transaction(function () use ($document, $input, $actor): SupplierReturn {
            $locked = SupplierReturn::query()->lockForUpdate()->findOrFail($document->id);
            if (! $locked->isDraft()) {
                throw new DomainInvariantException('Only a draft can be edited; this document is '.strtolower($locked->status).'.', 'status', 'DOCUMENT_NOT_DRAFT');
            }
            $this->create->fill($locked, $input, $actor);

            return $this->create->save($locked, $input, $actor);
        });
    }
}
