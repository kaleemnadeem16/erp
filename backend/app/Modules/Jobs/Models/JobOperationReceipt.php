<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * Records that an actor's request key produced a lifecycle result event, so a replay resolves to the original event. Safe references only: no response payload is cached. History.
 *
 * @property int $id
 * @property int $job_id
 * @property int $actor_id
 * @property string $request_key
 * @property string $operation
 * @property string $intent_hash
 * @property int $result_event_id
 * @property int $contract_version
 * @property Carbon $recorded_at
 */
class JobOperationReceipt extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_operation_receipts';

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime'];
}
