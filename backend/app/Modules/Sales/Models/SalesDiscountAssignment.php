<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property int $band_id
 * @property int|null $role_id
 * @property int|null $unit_id
 * @property string $purpose
 * @property string $effective_from
 * @property string|null $effective_to
 */
class SalesDiscountAssignment extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'sales_discount_assignments';

    protected $guarded = ['id'];
}
