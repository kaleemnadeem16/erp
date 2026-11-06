<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property int $version_no
 * @property int $priority
 * @property int $base_currency_id
 * @property bool $allow_higher_approver
 * @property string $operation
 * @property int|null $customer_id
 * @property int|null $unit_id
 * @property int|null $contains_item_id
 * @property string $enforcement
 * @property string $effective_from
 * @property string|null $effective_to
 * @property int $expiry_calendar_days
 * @property int $created_by
 */
class SalesDiscountPolicy extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'sales_discount_policy_versions';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['allow_higher_approver' => 'boolean'];
    }
}
