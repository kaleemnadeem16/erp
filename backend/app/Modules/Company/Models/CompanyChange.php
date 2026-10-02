<?php

declare(strict_types=1);

namespace App\Modules\Company\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded change to the company's profile or company-wide settings: which section, the old and new values of the fields that
 * changed, who, when and — for governed fields — why. Append-only here and in the database.
 *
 * @property string $section
 * @property array<string, mixed> $old_values
 * @property array<string, mixed> $new_values
 * @property string|null $reason
 * @property int $changed_by
 * @property Carbon $changed_at
 */
class CompanyChange extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('A company change record is history and cannot be edited.'));
        static::deleting(fn () => throw new DomainInvariantException('A company change record is history and cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'changed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
