<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Support\OpportunityOutcome;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A deal being pursued (ADR 0098/0106/0107/0108): one accountable owner, an OPEN stage and a stable outcome.
 *
 * @property int $id
 * @property string|null $number
 * @property string $title
 * @property string|null $description
 * @property int $party_id
 * @property int|null $party_contact_id
 * @property int|null $lead_id
 * @property string|null $source
 * @property int $owner_id
 * @property int $stage_id
 * @property OpportunityOutcome $outcome
 * @property string|null $expected_amount
 * @property int|null $expected_currency_id
 * @property Carbon|null $expected_close_on
 * @property string|null $next_action
 * @property Carbon|null $next_action_on
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property string|null $closure_reason
 * @property int $created_by
 */
class Opportunity extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_opportunities';

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['outcome' => OpportunityOutcome::class, 'expected_close_on' => 'date', 'next_action_on' => 'date', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<SalesStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(SalesStage::class, 'stage_id');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /** @return HasMany<SalesNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(SalesNote::class, 'opportunity_id');
    }

    /** @return HasMany<SalesHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(SalesHistory::class, 'opportunity_id');
    }
}
