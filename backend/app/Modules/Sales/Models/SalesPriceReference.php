<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;

/**
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property int $version_no
 * @property string $source_kind
 * @property int|null $family_id
 * @property int|null $customer_id
 * @property int|null $item_id
 * @property int|null $uom_id
 * @property string|null $manual_measure
 * @property int $currency_id
 * @property string $unit_price
 * @property string $effective_from
 * @property string|null $effective_to
 * @property int $priority
 * @property int $proposed_by
 * @property string $fingerprint
 */
class SalesPriceReference extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    public $timestamps = false;

    protected $table = 'sales_price_reference_versions';

    protected $guarded = ['id'];

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return ['family_id' => QuotationFamily::class, 'customer_id' => Party::class, 'item_id' => Item::class, 'uom_id' => Uom::class, 'proposed_by' => User::class];
    }
}
