<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * FULL_ONLY customer acceptance storage. Inactive until the owner approves duty holders.
 *
 * @property int $acceptance_id
 * @property int $claim_head_id
 * @property int $sequence
 * @property int $quantity_delta
 */
class CustomerClaimEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'customer_claim_events';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }
}
