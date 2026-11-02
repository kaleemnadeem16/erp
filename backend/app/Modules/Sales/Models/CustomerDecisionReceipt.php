<?php

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The record that one customer-decision operation completed: who, under which key, for which case, with a hash of the intent and a versioned result. Append-only.
 *
 * @property int $id
 * @property int $actor_id
 * @property string $request_key
 * @property string $operation
 * @property int $case_id
 * @property string $payload_hash
 * @property Carbon $completed_at
 * @property array<string, mixed> $result
 */
class CustomerDecisionReceipt extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'sales_customer_decision_receipts';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'result' => 'array'];
    }
}
