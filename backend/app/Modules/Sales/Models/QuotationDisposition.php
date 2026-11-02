<?php

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * How an issued revision stopped being available: withdrawn by the seller, or replaced by a later issued revision. Appended once, never changed.
 *
 * @property int $id
 * @property int $family_id
 * @property int $revision_id
 * @property string $kind
 * @property string $reason
 * @property int|null $replacement_revision_id
 * @property int $actor_id
 * @property Carbon $recorded_at
 */
class QuotationDisposition extends Model
{
    use BelongsToCompany;

    public const WITHDRAWN = 'WITHDRAWN';

    public const REPLACED = 'REPLACED';

    public $timestamps = false;

    protected $table = 'quotation_dispositions';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
