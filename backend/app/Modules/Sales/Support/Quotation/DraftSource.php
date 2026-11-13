<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Models\User;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Pricing\PricingInput;
use App\Modules\Sales\Support\Pricing\PricingSource;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Pricing\PricingSourceRegistry;
use App\Support\Quantity\QuantityContract;
use App\Support\Quantity\QuantitySign;
use Brick\Math\RoundingMode;

/**
 * Proves a draft line's source through the PR0 adapters and derives the measures from what they proved. Direct Items, service Items, Assembly work and governed
 * manual scope are authorised. The Item's own stock unit is the only selling unit the kernel supports, so offered, priced and stock measures coincide and the
 * conversion version is 1 (identity); a package or alternate unit refuses in the kernel as `SELLING_UNIT_UNSUPPORTED` rather than guessing a factor.
 *
 * Assembly work is proven WITHOUT reading its cost, so a seller without the estimating cost duty can prepare it. It names one Assembly version, pinned by id: the line
 * keeps that version after a newer one activates, and a pinned version that is no longer ACTIVE refuses here rather than being silently replaced. The work quantity is
 * judged at the six-place technical scale first and then at the four places an offer holds; a quantity that does not fit either refuses, it is never rounded. An
 * Assembly line has no stock measure, so it carries no priced/stock measure columns. No cost is read into or stored on the line.
 */
final class DraftSource
{
    public const CONVERSION_IDENTITY = 1;

    public function __construct(private readonly PricingSourceRegistry $sources) {}

    /**
     * @return array{description: string, offered_quantity: string, offered_uom_id: int|null, measures: array<string, mixed>}
     */
    public function resolve(User $actor, PricingSourceKind $kind, ?int $itemId, ?string $description, ?string $manualMeasure, mixed $quantity, ?int $uomId, int $currencyId, ?int $partyId, ?int $assemblyVersionId = null): array
    {
        $source = match ($kind) {
            PricingSourceKind::DirectItem => PricingSource::directItem($itemId ?? throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A direct Item line names its Item.', 'item_id')),
            PricingSourceKind::ServiceItem => PricingSource::serviceItem($itemId ?? throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A service Item line names its Item.', 'item_id')),
            PricingSourceKind::AssemblyWork => PricingSource::assemblyVersion($assemblyVersionId ?? throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'An Assembly line names its Assembly version.', 'assembly_version_id')),
            PricingSourceKind::Manual => PricingSource::manual((string) $description, (string) $manualMeasure),
        };
        if ($kind === PricingSourceKind::AssemblyWork && ($itemId !== null || ($manualMeasure !== null && trim($manualMeasure) !== ''))) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'An Assembly line is identified by its Assembly version alone.', $itemId !== null ? 'item_id' : 'manual_measure');
        }
        if ($kind !== PricingSourceKind::AssemblyWork && $assemblyVersionId !== null) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'Only an Assembly line names an Assembly version.', 'assembly_version_id');
        }
        // An Item is sold in its own stock unit; naming it is optional, and naming any other unit is the kernel's SELLING_UNIT_UNSUPPORTED refusal.
        if (in_array($kind, [PricingSourceKind::DirectItem, PricingSourceKind::ServiceItem], true) && $uomId === null) {
            $uomId = Item::query()->whereKey($itemId)->value('stock_uom_id');
        }
        // Assembly work is sold in the Assembly's own work unit; naming it is optional and naming any other refuses in the adapter. A missing version falls through to SOURCE_NOT_FOUND.
        if ($kind === PricingSourceKind::AssemblyWork && $uomId === null) {
            $uomId = AssemblyVersion::query()->with('assembly')->find($assemblyVersionId)?->assembly->work_uom_id;
        }
        // The adapters prove the source and quantity; they never settle or read the Party, so a draft without a recipient can still be checked. Preview verifies the Party.
        $rule = new SettlementRule($currencyId, new CurrencyQuantum(StorageAmount::SCALE), RoundingMode::HALF_UP);
        $resolved = $this->sources->quotationAdapterFor($kind)->resolve(new PricingInput(SalesActorContext::forUser($actor, 'quotation.draft'), $partyId ?? 0, $source, $quantity, $uomId, $rule));
        if ($kind === PricingSourceKind::AssemblyWork) {
            // Technical (six places) was proven by the adapter; an offer holds four. A finer quantity is refused with the shared stable code, never rounded to fit.
            $offered = QuantityContract::commercialLine(4)->represent($resolved->quantity, QuantitySign::Positive, 'offered_quantity');

            return [
                'description' => trim((string) $description) !== '' ? trim((string) $description) : $resolved->label,
                'offered_quantity' => (string) $offered, 'offered_uom_id' => $resolved->uomId,
                'measures' => ['priced_quantity' => null, 'priced_uom_id' => null, 'stock_quantity' => null, 'stock_uom_id' => null, 'conversion_version' => null],
            ];
        }
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
