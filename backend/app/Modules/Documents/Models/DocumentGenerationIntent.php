<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The durable record that a manifest is to be generated. A mutable projection with a guarded state machine and a monotone fencing generation: DF2 only creates
 * it PENDING; the renderer slice owns every later transition. A retry is another intent under another operation identity.
 *
 * @property int $id
 * @property int $manifest_id
 * @property string $operation_identity
 * @property string $request_key
 * @property string $intent_digest
 * @property string $state PENDING|RUNNING|FAILED|VERIFIED
 * @property Carbon|null $lease_expiry
 * @property int $fencing_generation
 * @property string|null $error_code
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DocumentGenerationIntent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['lease_expiry' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    /** @return BelongsTo<DocumentManifest, $this> */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(DocumentManifest::class, 'manifest_id');
    }
}
