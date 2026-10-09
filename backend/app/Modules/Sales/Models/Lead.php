<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Support\LeadStatus;
use App\Support\Concerns\BelongsToCompany;
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
}
