<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $policy_id
 * @property int $rank
 * @property string $label
 * @property string|null $line_percent
 * @property string|null $line_base_amount
 * @property string|null $document_percent
 * @property string|null $document_base_amount
 * @property string|null $input_percent
 * @property string|null $input_base_amount
 * @property bool $exception_only
 */
class SalesDiscountBand extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'sales_discount_bands';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['exception_only' => 'boolean'];
    }
}
