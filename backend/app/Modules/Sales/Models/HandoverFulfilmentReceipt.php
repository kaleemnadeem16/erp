<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The immutable Sales record that the exact customer-agreed deliverable and conditions of a released service were fulfilled, written in the same transaction as the
 * Job's COMPLETE event. It names the release, the Job's completion event, the agreed-conditions identity and the digest of the agreed deliverable text; it records no money
 * and changes no acceptance claim, allocation or accepted capacity. History; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $job_id
 * @property int $event_id
 * @property int $release_event_id
 * @property int $issued_line_id
 * @property int $basis_id
 * @property string $condition_identity
 * @property string $deliverable_digest
 * @property string|null $applicability_pin
 * @property int $completed_by
 * @property Carbon $recorded_at
 */
class HandoverFulfilmentReceipt extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'handover_fulfilment_receipts';

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime'];
}
