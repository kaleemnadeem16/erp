<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One reasoned correction of the current enquiry details (ADR 0113): who, when, why, and the exact old and new value of each changed detail.
 * Append-only; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $lead_id
 * @property int $actor_id
 * @property Carbon $recorded_at
 * @property string $reason
 * @property int $previous_version
 * @property int $resulting_version
 */
class LeadEnquiryCorrection extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'sales_lead_enquiry_corrections';

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    /** @return HasMany<LeadEnquiryCorrectionField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(LeadEnquiryCorrectionField::class, 'correction_id')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
