<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $warehouse_id
 * @property string|null $reference
 * @property PhysicalCountStatus $status
 * @property int $created_by
 * @property int|null $posted_by
 * @property int|null $cancelled_by
 */
class PhysicalCount extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (PhysicalCount $count): void {
            $allowed = ['status', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'updated_at'];
            if (array_diff(array_keys($count->getDirty()), $allowed) !== []) {
                throw new DomainInvariantException('Physical count header is immutable.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Physical counts cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['warehouse_id' => Warehouse::class, 'created_by' => User::class, 'posted_by' => User::class, 'cancelled_by' => User::class];
    }

    protected function casts(): array
    {
        return [
            'status' => PhysicalCountStatus::class,
            'posted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<PhysicalCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PhysicalCountLine::class);
    }
}
