<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Modules\Sales\Support\Pricing\PricingSource;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Quotation\LineMembership;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Quantity\QuantityContract;
use App\Support\Quantity\QuantitySign;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer-facing selling line of a draft revision, typed by its source and kept apart from estimating evidence. It holds the offered quantity exactly as
 * offered (four places; a finer or non-decimal quantity is refused by the model hook, never rounded). An entered unit price exists only together with its
 * dual-money evidence (currency, pinned FX, base amount); there is no cost, tax or approved price here — those arrive with the slices that can supply evidence for them.
 * Written only while its revision is a draft; its revision and logical scope never change.
 *
 * @property int $id
 * @property int $family_id
 * @property int $revision_id
 * @property int $logical_scope_id
 * @property int $sort_order
 * @property string|null $section_title
 * @property string $description
 * @property string $membership
 * @property int|null $choice_group_id
 * @property bool $is_baseline
 * @property string $source_kind
 * @property int|null $item_id
 * @property int|null $assembly_version_id
 * @property string|null $manual_measure
 * @property string $offered_quantity
 * @property int|null $offered_uom_id
 * @property string|null $unit_price the seller's entered unit price in the quotation currency; null is "not entered", never zero
 * @property int|null $price_currency_id
 * @property int|null $base_currency_id
 * @property string|null $base_unit_price
 * @property array<string, mixed>|null $fx_evidence
 * @property string|null $fx_fingerprint
 * @property string|null $priced_quantity
 * @property int|null $priced_uom_id
 * @property string|null $stock_quantity
 * @property int|null $stock_uom_id
 * @property int|null $conversion_version
 */
class QuotationDraftLine extends Model
{
    use BelongsToCompany;

    protected $table = 'quotation_draft_lines';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // The column would silently round a finer quantity, so every writer — Action, import, seeder — is held to the shared quantity contract here (ADR 0142).
        static::saving(function (self $line): void {
            if (! $line->exists || $line->isDirty('offered_quantity')) {
                QuantityContract::commercialLine(4)->represent($line->getAttributes()['offered_quantity'] ?? null, QuantitySign::Positive, 'offered_quantity');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_baseline' => 'boolean', 'offered_quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'base_unit_price' => 'decimal:4', 'fx_evidence' => 'array',
            'priced_quantity' => 'decimal:4', 'stock_quantity' => 'decimal:4',
        ];
    }

    public function membership(): LineMembership
    {
        return LineMembership::from($this->membership);
    }

    public function sourceKind(): PricingSourceKind
    {
        return PricingSourceKind::from($this->source_kind);
    }

    /** The same source expressed for the pricing kernel, so a draft line prices through PR0 exactly as any other typed source does. */
    public function pricingSource(): PricingSource
    {
        return match ($this->sourceKind()) {
            PricingSourceKind::DirectItem => PricingSource::directItem((int) $this->item_id),
            PricingSourceKind::ServiceItem => PricingSource::serviceItem((int) $this->item_id),
            PricingSourceKind::AssemblyWork => PricingSource::assemblyVersion((int) $this->assembly_version_id),
            PricingSourceKind::Manual => PricingSource::manual($this->description, (string) $this->manual_measure),
        };
    }

    /** @return BelongsTo<QuotationRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(QuotationRevision::class, 'revision_id');
    }

    /** @return BelongsTo<QuotationLogicalScope, $this> */
    public function logicalScope(): BelongsTo
    {
        return $this->belongsTo(QuotationLogicalScope::class, 'logical_scope_id');
    }

    /** @return BelongsTo<QuotationChoiceGroup, $this> */
    public function choiceGroup(): BelongsTo
    {
        return $this->belongsTo(QuotationChoiceGroup::class, 'choice_group_id');
    }
}
