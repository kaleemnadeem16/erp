<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * One frozen, settled line of an issued quotation: what was offered, at what price, and the settled net, tax and gross at the moment of issue. Append-only. It carries the
 * opaque Tax evidence reference but never a rate, a profile fact or a cost.
 *
 * @property int $issue_id
 * @property int $revision_id
 * @property int $family_id
 * @property int $source_line_id
 * @property int $logical_scope_id
 * @property int $sort_order
 * @property string|null $section_title
 * @property string $description
 * @property string $membership
 * @property int|null $choice_group_id
 * @property bool $is_baseline
 * @property bool $counts_in_total
 * @property string $source_kind
 * @property int|null $item_id
 * @property string|null $manual_measure
 * @property string $offered_quantity
 * @property int|null $offered_uom_id
 * @property string $unit_price
 * @property int $price_currency_id
 * @property string|null $fx_fingerprint
 * @property string $net
 * @property string|null $tax
 * @property string|null $gross
 * @property string|null $tax_disposition
 * @property string|null $tax_evidence_reference
 */
class QuotationIssuedLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'quotation_issued_lines';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_baseline' => 'boolean', 'counts_in_total' => 'boolean', 'offered_quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'net' => 'decimal:4', 'tax' => 'decimal:4', 'gross' => 'decimal:4'];
    }
}
