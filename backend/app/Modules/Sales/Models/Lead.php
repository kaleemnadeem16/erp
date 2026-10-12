<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Support\LeadStatus;
use App\Modules\Sales\Support\SalesEvent;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An optional enquiry (ADR 0098/0108). It keeps the enquiry as received; qualifying, disqualifying and converting add history and never overwrite it.
 *
 * @property int $id
 * @property string|null $number
 * @property LeadStatus $status
 * @property string $enquirer_name
 * @property string|null $enquirer_organisation
 * @property string|null $enquirer_email
 * @property string|null $enquirer_phone
 * @property string|null $description
 * @property string|null $source
 * @property Carbon $received_at
 * @property int|null $party_id
 * @property int|null $assigned_to
 * @property Carbon|null $qualified_at
 * @property Carbon|null $disqualified_at
 * @property string|null $disqualification_reason
 * @property int $created_by
 */
class Lead extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_leads';

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['status' => LeadStatus::class, 'received_at' => 'datetime', 'qualified_at' => 'datetime', 'disqualified_at' => 'datetime'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<Opportunity, $this> */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'lead_id');
    }

    /** @return HasMany<SalesNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(SalesNote::class, 'lead_id');
    }

    /** @return HasMany<SalesHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(SalesHistory::class, 'lead_id');
    }

    /**
     * The newest qualification, disqualification or revival of this enquiry: the marker a decision is confirmed against (null when none was
     * ever recorded). Read it after the row is locked so that the answer cannot move underneath the caller.
     */
    public function latestDecisionEventId(): ?int
    {
        $id = SalesHistory::query()->where('lead_id', $this->id)
            ->whereIn('event', [SalesEvent::LEAD_QUALIFIED, SalesEvent::LEAD_DISQUALIFIED, SalesEvent::LEAD_REVIVED])->max('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Adds the decision marker to each row of a list, in the same query, so a list never asks once per lead.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeWithDecisionMarker(Builder $query): void
    {
        $query->addSelect(['sales_leads.*', 'decision_event_id' => SalesHistory::query()->selectRaw('max(id)')
            ->whereColumn('sales_events.lead_id', 'sales_leads.id')
            ->whereIn('event', [SalesEvent::LEAD_QUALIFIED, SalesEvent::LEAD_DISQUALIFIED, SalesEvent::LEAD_REVIVED])]);
    }
}
