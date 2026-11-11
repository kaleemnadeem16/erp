<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One shared intake of what a customer sent, for one customer Party (CA0a, disabled storage). Its identity is immutable and it is never deleted; `generation` is disabled
 * metadata until CA1 gives it real head/event semantics. The intake identity is contextual — never a PO text alone and never authority to purchase.
 *
 * @property int $party_id
 * @property string $intake_identity
 * @property int $generation
 * @property int $recorded_by
 * @property Carbon $recorded_at
 */
class CustomerDecisionCase extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'customer_decision_cases';

    protected $guarded = ['id'];

    /** @return HasMany<CustomerDecisionComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(CustomerDecisionComponent::class, 'case_id');
    }

    /** @return HasMany<CustomerDecisionEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(CustomerDecisionEvidence::class, 'case_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
