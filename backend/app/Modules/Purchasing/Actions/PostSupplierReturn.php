<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\PostRejectedExitLines;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Modules\Purchasing\Numbering\SupplierReturnNumbering;
use App\Support\ActionAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posts a Supplier Return (ADR 0097): the one atomic point at which the stock leaves. In a single transaction each line becomes an immutable exit through the shared
 * `PostRejectedStockExit` core (movement, valuation feed, package evidence, ledger), the lines are linked to their exits, the document is numbered by the central
 * framework and frozen. Idempotent: posting an already posted document returns it unchanged; a failure on any line rolls back every line. It posts no journal and
 * no supplier credit — its expected credit is information only, and settling it belongs to accounts payable (Phase 6).
 */
final class PostSupplierReturn
{
    public function __construct(private readonly PostRejectedExitLines $lines, private readonly NumberingService $numbering) {}

    public function __invoke(SupplierReturn $document, User $actor): SupplierReturn
    {
        ActionAuthorization::require($actor, 'purchasing.supplier-return.post', 'post a Supplier Return');
        WarehouseScope::requireWarehouse($actor, 'purchasing.supplier-return.post', (int) $document->warehouse_id, 'post a Supplier Return');

        return DB::transaction(function () use ($document, $actor): SupplierReturn {
            $locked = SupplierReturn::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->status === SupplierReturn::POSTED) {
                return $locked->load('lines');
            }
            if ($locked->status !== SupplierReturn::DRAFT) {
                throw new DomainInvariantException('A cancelled document cannot be posted.', 'status', 'DOCUMENT_NOT_DRAFT');
            }
            $lines = $locked->lines()->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new DomainInvariantException('A document needs at least one line.', 'lines', 'EMPTY_DOCUMENT');
            }
            ($this->lines)(RejectedStockExit::SUPPLIER_RETURN, 'SUPPLIER_RETURN_LINE', 'supplier-return:'.$locked->id, $locked->reason, $lines, $actor);
            $postedAt = CarbonImmutable::now();
            $number = $this->numbering->allocate(SupplierReturnNumbering::contextFor($locked, $postedAt, $actor->id))->number;
            $locked->forceFill(['status' => SupplierReturn::POSTED, 'number' => $number, 'posted_by' => $actor->id, 'posted_at' => $postedAt])->save();

            return $locked->refresh()->load('lines');
        });
    }
}
