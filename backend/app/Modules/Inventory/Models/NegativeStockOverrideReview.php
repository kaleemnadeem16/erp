<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Inventory\Support\NegativeStockReviewOutcome;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Support\Carbon;

/**
 * @property NegativeStockReviewOutcome $outcome
 * @property int $reviewed_by
 * @property Carbon $reviewed_at
 * @property string $notes
 */
class NegativeStockOverrideReview extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Override reviews are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Override reviews cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['request_id' => NegativeStockOverrideRequest::class, 'reviewed_by' => User::class];
    }

    protected function casts(): array
    {
        return ['outcome' => NegativeStockReviewOutcome::class, 'reviewed_at' => 'immutable_datetime'];
    }
}
