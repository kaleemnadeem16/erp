<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Draft observed physical form, frozen once its receipt is posted. */
class GoodsReceiptPackageObservation extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected static function companyScopeRelation(): string
    {
        return 'goodsReceiptLine';
    }

    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }
}
