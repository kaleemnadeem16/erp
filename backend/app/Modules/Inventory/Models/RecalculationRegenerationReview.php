<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Support\Carbon;

/**
 * Immutable manual coordination record; does not approve the replacement impact.
 *
 * @property int $impact_version_id
 * @property int $invalidation_count
 * @property string $escalation
 * @property string $notes
 * @property int $reviewed_by
 * @property Carbon $reviewed_at
 */
class RecalculationRegenerationReview extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Regeneration reviews are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Regeneration reviews cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['impact_version_id' => RecalculationImpactVersion::class, 'reviewed_by' => User::class];
    }

    protected function casts(): array
    {
        return ['invalidation_count' => 'integer', 'reviewed_at' => 'immutable_datetime'];
    }
}
