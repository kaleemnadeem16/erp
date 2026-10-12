<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One append-only correction of who the record says was responsible, for one recorded owner fact of a closed opportunity (ADR 0112). The first
 * correction of a fact has no predecessor; each later one points to the current head. The source event and the opportunity are never changed.
 *
 * @property int $id
 * @property int $opportunity_id
 * @property int $target_event_id
 * @property string $target_field
 * @property int|null $predecessor_id
 * @property int $recorded_owner_id
 * @property int $asserted_owner_id
 * @property int $actor_id
 * @property Carbon $recorded_at
 * @property string $reason
 */
class OpportunityAttributionCorrection extends Model
{
    use BelongsToCompany;

    public const OWNER_ID = 'OWNER_ID';

    public const TO_OWNER_ID = 'TO_OWNER_ID';

    public $timestamps = false;

    protected $table = 'sales_opportunity_attribution_corrections';

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assertedOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asserted_owner_id');
    }
}
