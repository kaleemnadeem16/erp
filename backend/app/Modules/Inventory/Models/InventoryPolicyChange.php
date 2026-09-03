<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryPolicyChange extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Inventory policy audit records are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Inventory policy audit records cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['changed_by' => User::class];
    }

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'changed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
