<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Inventory\Support\InventoryCutoverMode;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property InventoryCutoverMode $mode
 * @property InventoryCutoverStatus $status
 * @property Carbon $cutover_at
 * @property string $source_system
 * @property array<string, mixed> $target_valuation_policy
 * @property string $control_quantity
 * @property string $control_value
 * @property string $base_control_value
 * @property int $created_by
 * @property int|null $validated_by
 * @property int|null $approved_by
 * @property int|null $finalised_by
 */
class InventoryCutover extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (InventoryCutover $cutover): void {
            $allowed = ['status', 'exception_summary', 'validated_by', 'validated_at', 'approved_by', 'approved_at', 'finalised_by', 'finalised_at', 'updated_at'];
            if (array_diff(array_keys($cutover->getDirty()), $allowed) !== []) {
                throw new DomainInvariantException('Inventory cutover definitions are immutable.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Inventory cutovers cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['created_by' => User::class, 'validated_by' => User::class, 'approved_by' => User::class, 'finalised_by' => User::class];
    }

    protected function casts(): array
    {
        return [
            'mode' => InventoryCutoverMode::class, 'status' => InventoryCutoverStatus::class,
            'cutover_at' => 'immutable_datetime', 'target_valuation_policy' => 'array', 'exception_summary' => 'array',
            'control_quantity' => 'decimal:4', 'control_value' => 'decimal:4', 'base_control_value' => 'decimal:4',
            'validated_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'finalised_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<InventoryCutoverBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(InventoryCutoverBatch::class);
    }

    /** @return HasMany<InventorySourceFact, $this> */
    public function sourceFacts(): HasMany
    {
        return $this->hasMany(InventorySourceFact::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
