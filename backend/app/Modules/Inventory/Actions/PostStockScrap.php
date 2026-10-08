<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Models\StockScrap;
use App\Modules\Inventory\Numbering\StockScrapNumbering;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Modules\Numbering\Services\NumberingService;
use App\Support\ActionAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posts a Scrap document (ADR 0097): the one atomic point at which the stock leaves. In a single transaction each line becomes an immutable exit through the shared
 * `PostRejectedStockExit` core (movement, valuation feed, package evidence, ledger), the lines are linked to their exits, the document is numbered by the central
 * framework and frozen. Idempotent: posting an already posted document returns it unchanged; a failure on any line rolls back every line. It posts no journal and
 * no supplier credit — the write-off's accounting class travels on the movement's source type for the later ledger.
 */
final class PostStockScrap
{
    public function __construct(private readonly PostRejectedExitLines $lines, private readonly NumberingService $numbering) {}

    public function __invoke(StockScrap $document, User $actor): StockScrap
    {
        ActionAuthorization::require($actor, 'inventory.scrap.post', 'post a Scrap document');
        WarehouseScope::requireWarehouse($actor, 'inventory.scrap.post', (int) $document->warehouse_id, 'post a Scrap document');

        return DB::transaction(function () use ($document, $actor): StockScrap {
            $locked = StockScrap::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->status === StockScrap::POSTED) {
                return $locked->load('lines');
            }
            if ($locked->status !== StockScrap::DRAFT) {
                throw new DomainInvariantException('A cancelled document cannot be posted.', 'status', 'DOCUMENT_NOT_DRAFT');
            }
            $lines = $locked->lines()->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new DomainInvariantException('A document needs at least one line.', 'lines', 'EMPTY_DOCUMENT');
            }
            ($this->lines)(RejectedStockExit::SCRAP, 'STOCK_SCRAP_LINE', 'stock-scrap:'.$locked->id, $locked->reason, $lines, $actor);
            $postedAt = CarbonImmutable::now();
            $number = $this->numbering->allocate(StockScrapNumbering::contextFor($locked, $postedAt, $actor->id))->number;
            $locked->forceFill(['status' => StockScrap::POSTED, 'number' => $number, 'posted_by' => $actor->id, 'posted_at' => $postedAt])->save();

            return $locked->refresh()->load('lines');
        });
    }
}
