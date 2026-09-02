<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockValuationEntry extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Stock valuation entries are append-only.'));
        static::deleting(fn () => throw new DomainInvariantException('Stock valuation entries are append-only.'));
    }

    protected static function companyScopeRelation(): string
    {
        return 'stockMove';
    }

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'amount' => 'decimal:4', 'base_amount' => 'decimal:4'];
    }

    /** @return BelongsTo<StockMove, $this> */
    public function stockMove(): BelongsTo
    {
        return $this->belongsTo(StockMove::class);
    }
}
