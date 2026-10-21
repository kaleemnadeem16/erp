<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * That an operation key was applied with this intent digest and resolved to this binding. History; the database refuses updates and deletes.
 *
 * @property int $id
 * @property string $operation
 * @property string $operation_key
 * @property string $intent_digest
 * @property int $binding_id
 * @property int|null $actor_id
 * @property Carbon $recorded_at
 */
class DocumentOperationReceipt extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime'];

    /** @return BelongsTo<DocumentSubjectBinding, $this> */
    public function binding(): BelongsTo
    {
        return $this->belongsTo(DocumentSubjectBinding::class, 'binding_id');
    }
}
