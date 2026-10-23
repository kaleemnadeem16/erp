<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The future receiver receipt: one per Job, one per (purpose, business identity). History; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $job_id
 * @property string $source_contract_version
 * @property string $target_purpose
 * @property string $business_identity
 * @property string $intent_hash
 * @property Carbon $recorded_at
 */
class JobHandoverOrigin extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'job_handover_origins';

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime'];
}
