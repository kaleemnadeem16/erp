<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Pricing\PricingInput;
use App\Modules\Sales\Support\Pricing\PricingSource;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Pricing\PricingSourceRegistry;
use Brick\Math\RoundingMode;

/**
 * Proves a draft line's source through the PR0 adapters and derives the measures from what they proved. Only a direct Item and governed manual scope are
 * authorised in QL1a: a service Item, an Assembly version and any other kind refuse, because their cost or estimating evidence is not delivered. The Item's
 * own stock unit is the only selling unit the kernel supports, so offered, priced and stock measures coincide and the conversion version is 1 (identity); a
 * package or alternate unit refuses in the kernel as `SELLING_UNIT_UNSUPPORTED` rather than guessing a factor. No cost is read into or stored on the line.
 */
final class DraftSource
{
    public const CONVERSION_IDENTITY = 1;

    public function __construct(private readonly PricingSourceRegistry $sources) {}

    /**
     * @return array{description: string, offered_quantity: string, offered_uom_id: int|null, measures: array<string, mixed>}
     */
    public function resolve(User $actor, PricingSourceKind $kind, ?int $itemId, ?string $description, ?string $manualMeasure, mixed $quantity, ?int $uomId, int $currencyId, ?int $partyId): array
    {
        $source = match ($kind) {
            PricingSourceKind::DirectItem => PricingSource::directItem($itemId ?? throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A direct Item line names its Item.', 'item_id')),
            PricingSourceKind::Manual => PricingSource::manual((string) $description, (string) $manualMeasure),
            default => throw QuotationRefusal::make(QuotationRefusal::SOURCE_KIND_NOT_AUTHORISED, "A {$kind->value} line cannot be prepared yet: only direct Item and manual lines are authorised.", 'source_kind'),
        };
        // An Item is sold in its own stock unit; naming it is optional, and naming any other unit is the kernel's SELLING_UNIT_UNSUPPORTED refusal.
        if ($kind === PricingSourceKind::DirectItem && $uomId === null) {
            $uomId = Item::query()->whereKey($itemId)->value('stock_uom_id');
        }
        // The adapters prove the source and quantity; they never settle or read the Party, so a draft without a recipient can still be checked. Preview verifies the Party.
        $rule = new SettlementRule($currencyId, new CurrencyQuantum(StorageAmount::SCALE), RoundingMode::HALF_UP);
        $resolved = $this->sources->adapterFor($kind)->resolve(new PricingInput(SalesActorContext::forUser($actor, 'quotation.draft'), $partyId ?? 0, $source, $quantity, $uomId, $rule));
        $proven = $resolved->quantity->toScale(4, RoundingMode::UNNECESSARY);

        if ($kind === PricingSourceKind::Manual) {
            return ['description' => (string) $description, 'offered_quantity' => (string) $proven, 'offered_uom_id' => null, 'measures' => ['priced_quantity' => null, 'priced_uom_id' => null, 'stock_quantity' => null, 'stock_uom_id' => null, 'conversion_version' => null]];
        }

        return [
            'description' => trim((string) $description) !== '' ? trim((string) $description) : $resolved->label,
            'offered_quantity' => (string) $proven,
            'offered_uom_id' => $resolved->uomId,
            'measures' => ['priced_quantity' => (string) $proven, 'priced_uom_id' => $resolved->uomId, 'stock_quantity' => (string) $proven, 'stock_uom_id' => $resolved->uomId, 'conversion_version' => self::CONVERSION_IDENTITY],
        ];
    }
}
