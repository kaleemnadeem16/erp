<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The record that one governed CRM operation completed: who, under which key, for which single subject, with a hash of the intent and a versioned
 * result reference. Append-only; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $actor_id
 * @property string $request_key
 * @property string $operation
 * @property int|null $lead_id
 * @property int|null $opportunity_id
 * @property string $payload_hash
 * @property Carbon $completed_at
 * @property array<string, mixed> $result
 */
class OperationReceipt extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'sales_crm_operation_receipts';

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'result' => 'array'];
    }
}
