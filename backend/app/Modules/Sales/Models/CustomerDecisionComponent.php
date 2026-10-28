<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The unit a customer decision will later be about: a case's intent identity tied to an ORIGINAL logical quotation scope (CA0a, disabled storage). The scope is context only —
 * it never proves that the scope was officially offered. Append-only.
 *
 * @property int $case_id
 * @property string $intent_identity
 * @property int $family_id
 * @property int $original_scope_anchor_id
 * @property int $recorded_by
 * @property Carbon $recorded_at
 */
class CustomerDecisionComponent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'customer_decision_components';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
