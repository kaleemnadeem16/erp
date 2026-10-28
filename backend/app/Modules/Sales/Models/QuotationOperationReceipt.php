<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The record that one governed quotation operation completed: who, under which key, for which family and revision, with a hash of the intent and a versioned
 * result reference. Append-only; the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $actor_id
 * @property string $request_key
 * @property string $operation
 * @property int $family_id
 * @property int|null $revision_id
 * @property string $payload_hash
 * @property Carbon $completed_at
 * @property array<string, mixed> $result
 */
class QuotationOperationReceipt extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'sales_quotation_operation_receipts';

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'result' => 'array'];
    }
}
