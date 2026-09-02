<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Model;
use App\Modules\Purchasing\Support\OverReceiptMode;
use App\Support\Concerns\BelongsToCompany;

/**
 * @property int $company_id
 * @property OverReceiptMode $over_receipt_mode
 * @property string $over_receipt_tolerance_percent
 * @property bool $purchase_order_required
 */
class PurchasingSetting extends Model
{
    use BelongsToCompany;

    protected $primaryKey = 'company_id';

    public $incrementing = false;

    protected $guarded = ['company_id'];

    protected function casts(): array
    {
        return [
            'over_receipt_mode' => OverReceiptMode::class,
            'over_receipt_tolerance_percent' => 'decimal:4',
            'purchase_order_required' => 'bool',
        ];
    }
}
