<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * Someone's record that the official quotation PDF was handed to the customer outside the system. A statement by a person — never receipt, reading or acceptance by the
 * customer. Insert-only; a mistake is corrected by a new row that names the one it corrects.
 *
 * @property int $id
 * @property int $revision_id
 * @property int $artefact_id
 * @property int $recorded_by
 * @property string $channel HAND|COURIER|POST|OTHER
 * @property Carbon $delivered_on
 * @property string $recipient_name
 * @property string|null $recipient_detail
 * @property string|null $note
 * @property int|null $corrects_id
 * @property string $operation_key
 * @property string $intent_digest
 * @property Carbon $recorded_at
 */
class QuotationDocumentDelivery extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['delivered_on' => 'date', 'recorded_at' => 'datetime'];
    }
}
