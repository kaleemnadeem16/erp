<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\StockScrap;
use App\Modules\Inventory\Support\RejectedExitLines;
use App\Support\ActionAuthorization;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Drafts a Scrap document (ADR 0097). A draft records intent — the delivery lines, quantities, the reasons and, for a grouped item, the declared package portion — and
 * **moves no stock, writes no valuation and no package evidence**; only posting does. It writes rejected stock off as a loss; it states neither a quality decision nor a supplier credit.
 */
final class CreateStockScrap
{
    /** @param  array<string,mixed>  $input */
    public function __invoke(array $input, User $actor): StockScrap
    {
        ActionAuthorization::require($actor, 'inventory.scrap.create', 'draft a Scrap document');
        if ((int) $actor->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('The actor must belong to the current company.', 'actor');
        }

        return DB::transaction(function () use ($input, $actor): StockScrap {
            $document = new StockScrap;
            $this->fill($document, $input, $actor);
            $document->created_by = $actor->id;

            return $this->save($document, $input, $actor);
        });
    }

    /** @param  array<string,mixed>  $input */
    public function fill(StockScrap $document, array $input, User $actor): void
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainInvariantException('A reason is required.', 'reason');
        }
        $document->reason = $reason;
        $document->disposal_method = RejectedExitLines::nullable($input['disposal_method'] ?? null);
        $document->disposal_reference = RejectedExitLines::nullable($input['disposal_reference'] ?? null);
    }

    /** @param  array<string,mixed>  $input */
    public function save(StockScrap $document, array $input, User $actor): StockScrap
    {
        $resolved = RejectedExitLines::resolve($input['lines'] ?? [], $actor, ['inventory.scrap.create', 'inventory.scrap.post']);

        $document->warehouse_id = $resolved['warehouse_id'];
        $document->save();
        $document->lines()->delete();
        foreach ($resolved['rows'] as $row) {
            $document->lines()->create($row);
        }

        return $document->refresh()->load('lines');
    }
}
