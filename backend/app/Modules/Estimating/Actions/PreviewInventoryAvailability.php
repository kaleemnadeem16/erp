<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Http\Resources\Costing\CostResolutionResource;
use App\Modules\Estimating\Support\InventoryAllocation;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Estimating\Support\ResolvedSelectionStrategy;
use App\Modules\Estimating\Support\ResolvedStockMixControl;
use App\Modules\Estimating\Support\StockMix;
use App\Modules\Estimating\Support\StockMixLevel;
use App\Modules\Estimating\Support\StockMixVerdict;
use App\Modules\Inventory\Actions\QueryStockAvailability;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\InventoryScope;
use App\Modules\Inventory\Support\LocationType;
use App\Modules\Inventory\Support\StockBalance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

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
 * allocator still uses cost to rank balances, but every cost field is null —
 * hidden, never zero — and the wording of the criteria notes carries no amounts.
 * Every cost here comes from Inventory's read-only cost query (ADR 0077/0110):
 * balances are ranked on the unit cost of the draw they would first supply, and
 * each proposed or chosen draw is then priced for its actual quantity as a
 * SELECTED_DRAW resolution. That is the cost of the drawn quantity, never of the
 * whole requirement; a shortage is reported separately and a genuine zero cost
 * is a cost. A proposal, each alternative and a hand-built mix are separate
 * hypothetical scenarios on one read snapshot — none consumes another's layers.
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
        private readonly ResolveMaterialSelectionStrategies $resolveStrategies,
        private readonly PriceStockMix $priceMix,
        private readonly ResolveStockMixControls $resolveMixControls,
        private readonly JudgeStockMix $judgeMix,
        private readonly PriceStockDraw $draws,
    ) {}

    /**
     * @param  list<array{item: array{id: int, code: string, name: string}, standard_quantity: string, uom: array{id: int, code: string, name: string}}>  $leafRows
     * @param  array{warehouse_ids?: list<int>, location_ids?: list<int>}  $scopeInput
     * @param  array<int, string>  $plannedByItem  item id → what-if planned stock quantity; the proposal plans for it instead of the standard
     * @param  list<array{item_id: int, location_id: int, batch_id?: int|null, quantity: string, uom_id: int}>  $stockMix  hand-built draw lines (ADR 0072), validated and priced here
     * @param  list<array{item_id: int, note: string|null}>  $stockMixNotes  the reason given for a mix, where the company's control asks for one (ADR 0076)
     * @return array{data: array<string, mixed>, evidence: array<string, mixed>}
     */
    public function __invoke(CostReadSession $session, array $leafRows, array $scopeInput, ?MaterialSelectionStrategy $override, bool $includeCost, array $plannedByItem = [], array $stockMix = [], array $stockMixNotes = []): array
    {
        $headline = ($this->resolveStrategies)->companyLevel($override);
        $resolved = ($this->resolveStrategies)(array_map(fn (array $row): int => $row['item']['id'], $leafRows), $override);
        $scope = new InventoryScope($scopeInput['warehouse_ids'] ?? [], $scopeInput['location_ids'] ?? []);

        $items = Item::query()
            ->whereIn('id', array_map(fn (array $row): int => $row['item']['id'], $leafRows))
            ->get()
            ->keyBy('id');
        $stockedIds = $items->filter(fn (Item $item): bool => $item->is_stocked)->keys()->map(fn ($id): int => (int) $id)->all();
        $snapshot = ($this->availability)($stockedIds, $scope);

        $mixControls = ($this->resolveMixControls)(array_map(fn (array $row): int => $row['item']['id'], $leafRows));
        $mixLinesByItem = $this->groupMixLines($stockMix, $leafRows, $items, $mixControls);
        $mixNotes = [];
        foreach ($stockMixNotes as $entry) {
            $mixNotes[(int) $entry['item_id']] = $entry['note'] ?? null;
        }

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
            $itemStrategy = $resolved[$itemId];
            $strategy = $itemStrategy->strategy;
            $required = BigDecimal::of($plannedByItem[$itemId] ?? $row['standard_quantity']);
            $item = $items->get($itemId);
            $balances = $stocked
                ? array_map(fn (StockBalance $balance): StockBalance => $this->draws->ranked($item, $balance, $required, $session), $snapshot->balancesFor($itemId))
                : [];
            $recommendation = $stocked ? $this->priced($item, ($this->allocate)($required, $balances, $strategy), $session) : null;

            $mix = $recommendation === null || ! isset($mixLinesByItem[$itemId])
                ? null
                : ($this->priceMix)($item, $required, $balances, $mixLinesByItem[$itemId], $session);
            $verdict = $mix === null
                ? null
                : ($this->judgeMix)($item, $mixControls[$itemId], $mix, $recommendation, $mixNotes[$itemId] ?? null);

            $otherStrategies = $recommendation === null
                ? []
                : array_values(array_filter(MaterialSelectionStrategy::cases(), fn (MaterialSelectionStrategy $case): bool => $case !== $strategy));
            $alternatives = [];
            foreach ($otherStrategies as $other) {
                $plan = $this->priced($item, ($this->allocate)($required, $balances, $other), $session);
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
                    $warnings[] = "Some recommended stock of {$code} has no complete cost evidence, so the cost of that draw is incomplete.";
                }
            }

            if ($mix !== null && ! $mix->isComplete()) {
                $warnings[] = "The stock you chose for {$code} leaves {$mix->shortage->stripTrailingZeros()} {$uomCode} of the requirement uncovered.";
            }
            if ($mix !== null && $includeCost && $mix->estimatedCost === null) {
                $warnings[] = "Some stock you chose for {$code} has no complete cost evidence, so the cost of your mix is incomplete.";
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
                'strategy' => $this->resolvedRef($itemStrategy),
                'mix_control' => $this->controlRef($mixControls[$itemId]),
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
                'selected_mix' => $mix === null || $verdict === null ? null : $this->mix($mix, $recommendation, $required, $usable, $includeCost, $verdict, $mixControls[$itemId], $mixNotes[$itemId] ?? null),
                'balances' => $balanceRows,
            ];
            $itemEvidence[] = ['item_id' => $itemId, 'strategy' => $strategy->value, 'strategy_policy_id' => $itemStrategy->policyId, 'mix' => $mix === null ? null : $this->mixEvidence($mix), 'mix_control' => [$mixControls[$itemId]->level->value, $mixControls[$itemId]->thresholdPercent, $mixControls[$itemId]->policyId], 'mix_note' => $mix === null ? null : ($mixNotes[$itemId] ?? null), 'balances' => $balanceEvidence];
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
                    'code' => $headline->strategy->value,
                    'label' => $headline->strategy->label(),
                    'source' => $headline->source->value,
                    'rules_version' => AllocateInventory::RULES_VERSION,
                ],
                'items' => $itemBlocks,
                'warnings' => $warnings,
                'limitations' => $this->limitations(),
            ],
            'evidence' => [
                'rules_version' => AllocateInventory::RULES_VERSION,
                'scope' => ['warehouse_ids' => $scope->warehouseIds, 'location_ids' => $scope->locationIds],
                'items' => $itemEvidence,
            ],
        ];
    }

    /**
     * @return array{
     *     strategy: array{code: string, label: string},
     *     lines: list<array{warehouse: array{id: int, code: string, name: string}, location: array{id: int, code: string, name: string, type: string}, batch: array{id: int, code: string}|null, quantity: string, cost: CostResolutionResource|null}>,
     *     pick_count: int,
     *     covered_quantity: string,
     *     shortage_quantity: string,
     *     cost: CostResolutionResource|null,
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
                'cost' => $this->costRef($line->cost, $includeCost),
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
            'cost' => $this->costRef($plan->cost, $includeCost),
            'criteria' => $criteria,
            'limitations' => $plan->limitations,
        ];
    }

    /**
     * Groups the flat request lines by item and refuses lines for a material the assembly does not need or that is
     * not stocked — before anything is priced. The line's position in the request is kept so an error names it.
     *
     * @param  list<array{item_id: int, location_id: int, batch_id?: int|null, quantity: string, uom_id: int}>  $stockMix
     * @param  list<array{item: array{id: int, code: string, name: string}, standard_quantity: string, uom: array{id: int, code: string, name: string}}>  $leafRows
     * @param  Collection<int, Item>  $items
     * @param  array<int, ResolvedStockMixControl>  $controls
     * @return array<int, list<array{index: int, location_id: int, batch_id: int|null, quantity: string, uom_id: int}>>
     */
    private function groupMixLines(array $stockMix, array $leafRows, $items, array $controls): array
    {
        $required = [];
        foreach ($leafRows as $row) {
            $required[(int) $row['item']['id']] = true;
        }
        $grouped = [];
        foreach ($stockMix as $index => $line) {
            $itemId = (int) $line['item_id'];
            if (! isset($required[$itemId])) {
                throw new DomainInvariantException('This assembly does not require that material, so there is nothing to draw.', "stock_mix.{$index}.item_id");
            }
            if (! (bool) $items->get($itemId)?->is_stocked) {
                throw new DomainInvariantException('That material is not a stocked item, so there is no stock to choose from.', "stock_mix.{$index}.item_id");
            }
            if ($controls[$itemId]->level === StockMixLevel::NotAllowed) {
                throw new DomainInvariantException('Your company does not allow choosing stock by hand for this material; follow the proposal.', "stock_mix.{$index}.item_id");
            }
            $grouped[$itemId][] = [
                'index' => $index,
                'location_id' => (int) $line['location_id'],
                'batch_id' => isset($line['batch_id']) ? (int) $line['batch_id'] : null,
                'quantity' => (string) $line['quantity'],
                'uom_id' => (int) $line['uom_id'],
            ];
        }

        return $grouped;
    }

    /**
     * @return array{
     *     lines: list<array{warehouse: array{id: int, code: string, name: string}, location: array{id: int, code: string, name: string, type: string}, batch: array{id: int, code: string}|null, entered_quantity: string, entered_uom: array{id: int, code: string, name: string}, stock_unit_factor: string, quantity: string, usable_before_quantity: string, remaining_after_quantity: string, cost: CostResolutionResource|null}>,
     *     pick_count: int,
     *     required_quantity: string,
     *     covered_quantity: string,
     *     shortage_quantity: string,
     *     is_complete: bool,
     *     remaining_after_use_quantity: string,
     *     cost: CostResolutionResource|null,
     *     same_as_recommendation: bool,
     *     recommendation_estimated_cost: string|null,
     *     cost_difference: string|null,
     *     cost_difference_percent: string|null,
     *     control: array{level: array{code: string, label: string}, threshold_percent: string|null, status: string, observed_percent: string|null, extra_cost: string|null, can_adopt: bool, note: string|null, approval: array{policy: array{id: int, code: string, version_no: int, name: string}|null, steps: list<array{name: string, stage: int, required_approvals: int, uses_authority_limit: bool}>, note: string|null}|null, limitations: list<string>},
     *     limitations: list<string>
     * }
     */
    private function mix(StockMix $mix, InventoryAllocation $recommendation, BigDecimal $required, BigDecimal $usable, bool $includeCost, StockMixVerdict $verdict, ResolvedStockMixControl $control, ?string $note): array
    {
        $lines = [];
        foreach ($mix->lines as $line) {
            $lines[] = [
                'warehouse' => $this->warehouseRef($line->balance),
                'location' => $this->locationRef($line->balance),
                'batch' => $this->batchRef($line->balance),
                'entered_quantity' => (string) BigDecimal::of($line->enteredQuantity)->stripTrailingZeros(),
                'entered_uom' => $this->ref($line->enteredUom->id, $line->enteredUom->code, $line->enteredUom->name),
                'stock_unit_factor' => (string) $line->stockUnitFactor->stripTrailingZeros(),
                'quantity' => (string) $line->quantity,
                'usable_before_quantity' => (string) $line->balance->usable,
                'remaining_after_quantity' => (string) $line->remainingAfter,
                'cost' => $this->costRef($line->cost, $includeCost),
            ];
        }

        // How the mix compares with what the strategy recommended: information for the person choosing, never a
        // rule. A cost consequence that should escalate is a separate decision (ADR 0072); cost stays hidden without
        // the cost capability.
        $known = $includeCost && $mix->estimatedCost !== null && $recommendation->estimatedCost !== null;
        $difference = $known ? $mix->estimatedCost->minus($recommendation->estimatedCost)->toScale(4, RoundingMode::HALF_UP) : null;
        $percent = $known && $recommendation->estimatedCost->isGreaterThan(0)
            ? $difference->multipliedBy(100)->dividedBy($recommendation->estimatedCost, 2, RoundingMode::HALF_UP)
            : null;

        return [
            'lines' => $lines,
            'pick_count' => count($mix->lines),
            'required_quantity' => (string) $required,
            'covered_quantity' => (string) $mix->covered,
            'shortage_quantity' => (string) $mix->shortage,
            'is_complete' => $mix->isComplete(),
            'remaining_after_use_quantity' => (string) $usable->minus($mix->covered),
            'cost' => $this->costRef($mix->cost, $includeCost),
            'same_as_recommendation' => $mix->signature() === $recommendation->signature(),
            'recommendation_estimated_cost' => $includeCost && $recommendation->estimatedCost !== null ? (string) $recommendation->estimatedCost : null,
            'cost_difference' => $difference === null ? null : (string) $difference,
            'cost_difference_percent' => $percent === null ? null : (string) $percent,
            'control' => [
                'level' => ['code' => $control->level->value, 'label' => (string) $control->level->label()],
                'threshold_percent' => $control->thresholdPercent === null ? null : (string) BigDecimal::of($control->thresholdPercent)->stripTrailingZeros(),
                'status' => $verdict->status,
                // Cost figures follow the cost capability: the judgement is the same for everyone, the numbers are hidden (never zero).
                'observed_percent' => $includeCost && $verdict->observedPercent !== null ? (string) $verdict->observedPercent : null,
                'extra_cost' => $includeCost && $verdict->extraCost !== null ? (string) $verdict->extraCost : null,
                'can_adopt' => $verdict->canAdopt(),
                'note' => $note === null || trim($note) === '' ? null : trim($note),
                'approval' => $verdict->approval,
                'limitations' => $verdict->limitations,
            ],
            'limitations' => $this->mixLimitations(),
        ];
    }

    /** The draw's cost resolution for a reader who may see cost; hidden (null), never zero, otherwise. */
    private function costRef(?CostResolution $cost, bool $includeCost): ?CostResolutionResource
    {
        return $includeCost && $cost !== null ? new CostResolutionResource($cost) : null;
    }

    /** Prices a proposed draw as its own scenario, line by line within it. */
    private function priced(Item $item, InventoryAllocation $plan, CostReadSession $snapshot): InventoryAllocation
    {
        $session = $this->draws->scenario($snapshot);
        $lineCosts = [];
        foreach ($plan->lines as $line) {
            $lineCosts[] = $this->draws->resolve($item, $line->balance, $line->quantity, $session);
        }

        return $plan->priced($lineCosts, $this->draws->total($item, $lineCosts, $plan->covered, $session));
    }

    /** @return list<array{location_id: int, batch_id: int|null, quantity: string}> */
    private function mixEvidence(StockMix $mix): array
    {
        $evidence = [];
        foreach ($mix->lines as $line) {
            $evidence[] = ['location_id' => $line->balance->locationId, 'batch_id' => $line->balance->batchId, 'quantity' => (string) $line->quantity];
        }

        return $evidence;
    }

    /** @return list<string> */
    private function mixLimitations(): array
    {
        $limitations = [];
        $limitations[] = 'A chosen mix is a quantity per location and batch (QUANTITY_ONLY). It makes no claim about sealed or opened packages, and reserves nothing.';
        $limitations[] = 'Drawing more than the plan needs is not a mix choice: change the planned quantity so the variance rules apply.';

        return $limitations;
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

    /** @return array{level: array{code: string, label: string}, threshold_percent: string|null, source: string, scope_id: int|null} */
    private function controlRef(ResolvedStockMixControl $control): array
    {
        return [
            'level' => ['code' => $control->level->value, 'label' => (string) $control->level->label()],
            'threshold_percent' => $control->thresholdPercent === null ? null : (string) BigDecimal::of($control->thresholdPercent)->stripTrailingZeros(),
            'source' => $control->source->value,
            'scope_id' => $control->scopeId,
        ];
    }

    /** @return array{id: int, code: string}|null */
    private function batchRef(StockBalance $balance): ?array
    {
        return $balance->batchId === null ? null : ['id' => $balance->batchId, 'code' => (string) $balance->batchCode];
    }

    /** @return array{code: string, label: string, source: string, scope_id: int|null} */
    private function resolvedRef(ResolvedSelectionStrategy $resolved): array
    {
        return ['code' => $resolved->strategy->value, 'label' => $resolved->strategy->label(), 'source' => $resolved->source->value, 'scope_id' => $resolved->scopeId];
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
        $limitations[] = 'Each material follows its own stored selection strategy, else its category’s, else the company’s, else the built-in default; a strategy chosen for this preview overrides all of them and changes nothing stored.';

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
