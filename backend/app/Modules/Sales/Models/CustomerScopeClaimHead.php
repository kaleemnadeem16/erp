<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * FULL_ONLY customer acceptance storage. Inactive until the owner approves duty holders.
 *
 * @property int $family_id
 * @property int|null $scope_anchor_id
 * @property int|null $choice_group_id
 * @property int $generation
 * @property int $capacity
 * @property int $consumed
 * @property int|null $selected_scope_id
 * @property string $baseline_label
 */
class CustomerScopeClaimHead extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'customer_scope_claim_heads';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }
}
