<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Currency;
use App\Models\Model;
use App\Modules\Accounting\Support\AccountType;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsCompanyScopedHierarchy;
use App\Support\Status;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The company's chart of accounts — see docs/domain/general-ledger.md. Carries no
 * posting logic yet (Phase 6); this is the structure item/category GL mapping
 * (docs/domain/items.md) and the customer/supplier role accounts
 * (docs/domain/parties.md) reference. Hierarchy guarded identically to
 * App\Modules\Inventory\Models\Category via GuardsCompanyScopedHierarchy.
 *
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property int|null $parent_id
 * @property bool $is_postable
 * @property int|null $currency_id
 * @property Status $status
 */
class ChartOfAccount extends Model
{
    use BelongsToCompany;
    use GuardsCompanyScopedHierarchy;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_postable' => 'bool',
            'status' => Status::class,
        ];
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    /**
     * @return HasMany<ChartOfAccount, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
