<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Support\InventoryAllocation;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Inventory\Actions\QueryStockAvailability;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\InventoryScope;
use App\Modules\Inventory\Support\LocationType;
use App\Modules\Inventory\Support\StockBalance;
use Brick\Math\BigDecimal;

/**
 * The inventory half of ADR 0058's preview: for each leaf material, what
 * usable internal stock exists in the requested scope, what draw the effective
 * strategy recommends, what the other two presets would do instead, and what
 * is left uncovered. Advisory and read-only — nothing is reserved or posted.
 *
 * Planned quantity equals standard quantity for now: hypothetical planned
 * adjustments and ADR 0054 variance evaluation are a later slice, so this
 * block never invents a second quantity. Only stock this company holds is
 * considered — no supplier offers, no replenishment (ADR 0051).
 *
 * Cost is evidence, gated separately (ADR 0057): with `$includeCost` false the
 * allocator still uses cost to rank balances, but every unit/line/estimated
 * cost field is null — hidden, never zero — and the wording of the criteria
 * notes carries no amounts.
 *
 * It is only ever called for an actor who holds
 * `estimating.inventory_availability.view`; the controller decides that, and
 * without it this class is never reached and no availability is queried.
 *
 * The response is assembled from literal arrays, typed helpers and typed
 * value objects on purpose: Scramble derives the OpenAPI schema (and so the
 * generated client's types) by reading this code, and it loses the shape
 * through closures such as array_map and through array-typed parameters
 * (docs/architecture/api-conventions.md, "Generated client").
 */
final class PreviewInventoryAvailability
{
    private const TRACKING_MODE = 'QUANTITY_ONLY';

    public function __construct(
        private readonly QueryStockAvailability $availability,
        private readonly AllocateInventory $allocate,
    ) {}

    /**
     * @param  list<array{item: array{id: int, code: string, name: string}, standard_quantity: string, uom: array{id: int, code: string, name: string}}>  $leafRows
     * @param  array{warehouse_ids?: list<int>, location_ids?: list<int>}  $scopeInput
     * @param  array<int, string>  $plannedByItem  item id → what-if planned stock quantity; the proposal plans for it instead of the standard
     * @return array{data: array<string, mixed>, evidence: array<string, mixed>}
     */
    public function __invoke(array $leafRows, array $scopeInput, ?MaterialSelectionStrategy $override, bool $includeCost, array $plannedByItem = []): array
    {
        $strategy = $override ?? MaterialSelectionStrategy::companyDefault();
        $scope = new InventoryScope($scopeInput['warehouse_ids'] ?? [], $scopeInput['location_ids'] ?? []);

        $items = Item::query()
            ->whereIn('id', array_map(fn (array $row): int => $row['item']['id'], $leafRows))
            ->get()
            ->keyBy('id');
        $stockedIds = $items->filter(fn (Item $item): bool => $item->is_stocked)->keys()->map(fn ($id): int => (int) $id)->all();
        $snapshot = ($this->availability)($stockedIds, $scope);

        $warnings = [];
        $itemBlocks = [];
        $itemEvidence = [];

        foreach ($leafRows as $row) {
            $itemId = (int) $row['item']['id'];
            $code = (string) $row['item']['code'];
            $uomCode = (string) $row['uom']['code'];
            $stocked = (bool) $items->get($itemId)?->is_stocked;

            // A non-stocked item goes through the same literal below with every availability figure
            // null: "does not apply", never a zero or a shortage.
            $required = BigDecimal::of($plannedByItem[$itemId] ?? $row['standard_quantity']);
            $balances = $stocked ? $snapshot->balancesFor($itemId) : [];
            $recommendation = $stocked ? ($this->allocate)($required, $balances, $strategy) : null;

            $otherStrategies = $recommendation === null
                ? []
                : array_values(array_filter(MaterialSelectionStrategy::cases(), fn (MaterialSelectionStrategy $case): bool => $case !== $strategy));
            $alternatives = [];
            foreach ($otherStrategies as $other) {
                $plan = ($this->allocate)($required, $balances, $other);
                $alternatives[] = [
                    'strategy' => $this->strategyRef($other),
                    'same_as_recommendation' => $plan->signature() === $recommendation->signature(),
                    'plan' => $this->plan($plan, $includeCost),
                ];
            }

            if ($recommendation === null) {
                $warnings[] = "{$code} is not a stocked item, so no inventory availability applies to it.";
            } else {
                if ($recommendation->shortage->isGreaterThan(0)) {
                    $warnings[] = "Usable stock of {$code} does not cover the requirement: short by {$recommendation->shortage->stripTrailingZeros()} {$uomCode}.";
                }
                if ($includeCost && $recommendation->lines !== [] && $recommendation->estimatedCost === null) {
                    $warnings[] = "Some recommended stock of {$code} has no defensible cost evidence, so its estimated cost is unavailable.";
                }
            }

            $onHand = BigDecimal::zero()->toScale(4);
            $held = BigDecimal::zero()->toScale(4);
            $unusableLocation = BigDecimal::zero()->toScale(4);
            $usable = BigDecimal::zero()->toScale(4);
            $balanceRows = [];
            $balanceEvidence = [];
            foreach ($balances as $balance) {
                $onHand = $onHand->plus($balance->onHand);
                $held = $held->plus($balance->held);
                $unusableLocation = $unusableLocation->plus($balance->unusableLocation);
                $usable = $usable->plus($balance->usable);
                $balanceRows[] = [
                    'warehouse' => $this->warehouseRef($balance),
                    'location' => $this->locationRef($balance),
                    'batch' => $this->batchRef($balance),
                    'location_usable' => (bool) $balance->locationUsable,
                    'location_usable_source' => $balance->locationMarked === null ? 'TYPE' : 'MARKED',
                    'on_hand_quantity' => (string) $balance->onHand,
                    'held_for_qa_quantity' => (string) $balance->held,
                    'usable_quantity' => (string) $balance->usable,
                ];
                $balanceEvidence[] = [
                    'location_id' => $balance->locationId,
                    'batch_id' => $balance->batchId,
                    'usable' => (string) $balance->usable,
                    'unit_cost' => $includeCost && $balance->unitCost !== null ? (string) $balance->unitCost : null,
                ];
            }

            $itemBlocks[] = [
                'item' => $this->ref($itemId, $code, (string) $row['item']['name']),
                'uom' => $this->ref((int) $row['uom']['id'], $uomCode, (string) $row['uom']['name']),
                'is_stocked' => $stocked,
                'standard_quantity' => (string) $row['standard_quantity'],
                'required_quantity' => (string) $required,
                'on_hand_quantity' => $stocked ? (string) $onHand : null,
                'held_for_qa_quantity' => $stocked ? (string) $held : null,
                'unusable_location_quantity' => $stocked ? (string) $unusableLocation : null,
                'usable_quantity' => $stocked ? (string) $usable : null,
                'covered_quantity' => $recommendation === null ? null : (string) $recommendation->covered,
                'shortage_quantity' => $recommendation === null ? null : (string) $recommendation->shortage,
                'remaining_after_use_quantity' => $recommendation === null ? null : (string) $usable->minus($recommendation->covered),
                'recommendation' => $recommendation === null ? null : $this->plan($recommendation, $includeCost),
                'alternatives' => $alternatives,
                'balances' => $balanceRows,
            ];
            $itemEvidence[] = ['item_id' => $itemId, 'balances' => $balanceEvidence];
        }

        return [
            'data' => [
                'tracking_mode' => self::TRACKING_MODE,
                'as_of' => $snapshot->asOf->toIso8601String(),
                'scope' => [
                    'narrowed' => $scope->narrowed(),
                    'warehouse_ids' => $this->ids($scope->warehouseIds),
                    'location_ids' => $this->ids($scope->locationIds),
                    'excluded_location_types' => $this->excludedLocationTypes(),
                ],
                'strategy' => [
                    'code' => $strategy->value,
                    'label' => $strategy->label(),
                    'source' => $override === null ? 'COMPANY_DEFAULT' : 'REQUEST_OVERRIDE',
                    'rules_version' => AllocateInventory::RULES_VERSION,
                ],
                'items' => $itemBlocks,
                'warnings' => $warnings,
                'limitations' => $this->limitations(),
            ],
            'evidence' => [
                'strategy' => $strategy->value,
                'rules_version' => AllocateInventory::RULES_VERSION,
                'scope' => ['warehouse_ids' => $scope->warehouseIds, 'location_ids' => $scope->locationIds],
                'items' => $itemEvidence,
            ],
        ];
    }

    /**
     * @return array{
     *     strategy: array{code: string, label: string},
     *     lines: list<array{warehouse: array{id: int, code: string, name: string}, location: array{id: int, code: string, name: string, type: string}, batch: array{id: int, code: string}|null, quantity: string, unit_cost: string|null, cost_method: string|null, line_cost: string|null}>,
     *     pick_count: int,
     *     covered_quantity: string,
     *     shortage_quantity: string,
     *     estimated_cost: string|null,
     *     criteria: list<array{code: string, label: string, applied: bool, note: string}>,
     *     limitations: list<string>
     * }
     */
    private function plan(InventoryAllocation $plan, bool $includeCost): array
    {
        $lines = [];
        foreach ($plan->lines as $line) {
            $lines[] = [
                'warehouse' => $this->warehouseRef($line->balance),
                'location' => $this->locationRef($line->balance),
                'batch' => $this->batchRef($line->balance),
                'quantity' => (string) $line->quantity,
                'unit_cost' => $includeCost && $line->balance->unitCost !== null ? (string) $line->balance->unitCost : null,
                'cost_method' => $includeCost ? $line->balance->costMethod : null,
                'line_cost' => $includeCost && $line->lineCost !== null ? (string) $line->lineCost : null,
            ];
        }
        $criteria = [];
        foreach ($plan->criteria as $criterion) {
            $criteria[] = [
                'code' => $criterion->code,
                'label' => $criterion->label,
                'applied' => (bool) $criterion->applied,
                'note' => $criterion->note,
            ];
        }

        return [
            'strategy' => $this->strategyRef($plan->strategy),
            'lines' => $lines,
            'pick_count' => $plan->pickCount(),
            'covered_quantity' => (string) $plan->covered,
            'shortage_quantity' => (string) $plan->shortage,
            'estimated_cost' => $includeCost && $plan->estimatedCost !== null ? (string) $plan->estimatedCost : null,
            'criteria' => $criteria,
            'limitations' => $plan->limitations,
        ];
    }

    /** @return array{id: int, code: string, name: string} */
    private function ref(int $id, string $code, string $name): array
    {
        return ['id' => $id, 'code' => $code, 'name' => $name];
    }

    /** @return array{id: int, code: string, name: string} */
    private function warehouseRef(StockBalance $balance): array
    {
        return ['id' => $balance->warehouseId, 'code' => $balance->warehouseCode, 'name' => $balance->warehouseName];
    }

    /** @return array{id: int, code: string, name: string, type: string} */
    private function locationRef(StockBalance $balance): array
    {
        return ['id' => $balance->locationId, 'code' => $balance->locationCode, 'name' => $balance->locationName, 'type' => $balance->locationType->value];
    }

    /** @return array{id: int, code: string}|null */
    private function batchRef(StockBalance $balance): ?array
    {
        return $balance->batchId === null ? null : ['id' => $balance->batchId, 'code' => (string) $balance->batchCode];
    }

    /** @return array{code: string, label: string} */
    private function strategyRef(MaterialSelectionStrategy $strategy): array
    {
        return ['code' => $strategy->value, 'label' => $strategy->label()];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[] = (int) $id;
        }

        return $out;
    }

    /**
     * Built in a loop so the generated client types this as `string[]` rather than a tuple of
     * these exact sentences, which would need regenerating on every wording change.
     *
     * @return list<string>
     */
    private function limitations(): array
    {
        $limitations = [];
        $limitations[] = 'Stock is proposed as a quantity per location (QUANTITY_ONLY): no sealed-pack, open-remnant or container claims are made, and expiry is not considered.';
        $limitations[] = 'Availability is a snapshot as of the time shown. It can be out of date immediately and reserves nothing.';
        $limitations[] = 'Stock held for QA release is not counted as usable. Neither is stock in a quarantine, scrap, transit, returns, dispatch or production location, unless the company has marked that location as counting (each balance says whether its location follows its type or was marked).';
        $limitations[] = 'The selection strategy is the built-in company default unless overridden here; item- and category-level strategy settings are not implemented yet.';

        return $limitations;
    }

    /** @return list<string> */
    private function excludedLocationTypes(): array
    {
        $types = [];
        foreach (LocationType::cases() as $type) {
            if ($type !== LocationType::View && ! $type->holdsUsableStock()) {
                $types[] = $type->value;
            }
        }

        return $types;
    }
}
