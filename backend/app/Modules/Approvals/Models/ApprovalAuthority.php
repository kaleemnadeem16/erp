<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A person's amount ceiling for one subject type, held as data rather than as a permission (ADR 0070 §5).
 *
 * @property int $user_id
 * @property string $subject_type
 * @property bool $unlimited
 * @property string|null $ceiling_base_amount
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property string $reason
 * @property int $changed_by
 * @property Carbon|null $revoked_at
 */
class ApprovalAuthority extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return ['user_id' => User::class, 'changed_by' => User::class];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unlimited' => 'bool',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    /** @param  Builder<ApprovalAuthority>  $query */
    public function scopeInForce(Builder $query, ?Carbon $on = null): void
    {
        $day = ($on ?? Carbon::today())->toDateString();
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $day));
    }
}
