<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;

class StockTransferRepair extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Stock transfer repairs are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Stock transfer repairs cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['stock_transfer_detail_id' => StockTransferDetail::class];
    }

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4', 'provisional_unit_cost' => 'decimal:4',
            'actual_unit_cost' => 'decimal:4', 'unit_cost_difference' => 'decimal:4',
            'valuation_adjustment' => 'decimal:4', 'repaired_at' => 'immutable_datetime',
        ];
    }
}
