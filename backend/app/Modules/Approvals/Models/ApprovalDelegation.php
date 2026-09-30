<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Models;

use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dated cover: the delegate acts *as* the delegator, within the delegator's authority and no further (ADR 0070 §6).
 *
 * @property int $delegator_id
 * @property int $delegate_id
 * @property string|null $subject_type null = every subject type
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property string $reason
 * @property int $created_by
 * @property Carbon|null $revoked_at
 */
class ApprovalDelegation extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    /** @return array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    protected static function companyScopedForeignKeys(): array
    {
        return ['delegator_id' => User::class, 'delegate_id' => User::class, 'created_by' => User::class];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    /** @param  Builder<ApprovalDelegation>  $query */
    public function scopeInForce(Builder $query, ?Carbon $on = null): void
    {
        $day = ($on ?? Carbon::today())->toDateString();
        $query->whereNull('revoked_at')->where('valid_from', '<=', $day)->where('valid_to', '>=', $day);
    }
}
