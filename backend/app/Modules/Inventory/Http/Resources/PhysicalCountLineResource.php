<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Actions\PostGroupedCountLine;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use App\Modules\Inventory\Support\StockVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PhysicalCountLine */
class PhysicalCountLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PhysicalCountLine $line */
        $line = $this->resource;

        return [
            'id' => $line->id,
            'physical_count_id' => $line->physical_count_id,
            'item_id' => $line->item_id,
            'item' => $this->whenLoaded('item', fn () => new ItemResource($line->item)),
            'location_id' => $line->location_id,
            'location' => $this->whenLoaded('location', fn () => new LocationResource($line->location)),
            'batch_id' => $line->batch_id,
            'serial_no' => $line->serial_no,
            // The system quantity is withheld from a counter who holds only the count capability (blind counting, ADR 0083).
            'book_qty' => $this->when(StockVisibility::bookQuantity($request->user()), $line->book_qty),
            'counted_qty' => $line->counted_qty,
            'counted_by' => $line->counted_by,
            'counted_at' => $line->counted_at?->toIso8601String(),
            'found_unit_cost' => $this->when(
                $request->user()?->can('inventory.physical_count.post') || $request->user()?->can('inventory.valuation.view'),
                $line->found_unit_cost,
            ),
            'posted_stock_move_id' => $line->posted_stock_move_id,
            'grouped' => $line->grouped,
            // What was counted, by tracked package size plus the open/loose quantity (grouped lines only; never the book).
            'composition' => $this->when($line->grouped, fn () => $line->composition->map(fn ($row): array => [
                'packaging_id' => $row->item_packaging_id, 'count' => $row->whole_count, 'quantity' => (string) $row->quantity,
            ])->values()->all()),
            // Whether stock or package evidence moved since the line was captured, so it must be refreshed and recounted. Says nothing of the book figure.
            'stale_reason' => $this->when($line->grouped, fn (): ?string => $this->staleReason($request, $line)),
            'entries' => PhysicalCountLineEntryResource::collection($this->whenLoaded('entries')),
        ];
    }

    private function staleReason(Request $request, PhysicalCountLine $line): ?string
    {
        $user = $request->user();
        if ($user === null || ! $user->can('inventory.package-state.view')
            || PhysicalCount::query()->whereKey($line->physical_count_id)->value('status') !== PhysicalCountStatus::Draft) {
            return null;
        }

        return app(PostGroupedCountLine::class)->staleReason($line, $user);
    }
}
