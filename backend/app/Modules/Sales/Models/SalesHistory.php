<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded decision about a lead or an opportunity: who, when, why, and what changed. Append-only; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int|null $lead_id
 * @property int|null $opportunity_id
 * @property string $event
 * @property int $actor_id
 * @property Carbon $occurred_at
 * @property string|null $reason
 * @property array<string, mixed> $details
 */
class SalesHistory extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'sales_events';

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'details' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
