<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * Immutable, versioned, customer-agreed completion conditions of one accepted issued line: exact text, the consent evidence it cites, who recorded it and when.
 * Append-only; a correction is a new version whose predecessor is the previous head. Never backfilled from a local checkbox.
 *
 * @property int $issued_line_id
 * @property int $version
 * @property int|null $predecessor_id
 * @property string $agreed_text
 * @property int $evidence_id
 * @property string $attestation_policy
 * @property string $terms_source ISSUED_LINE (the line description verbatim) or EVIDENCE_TEXT (its own later customer-agreement evidence)
 * @property string $materials_scope EXCLUDED|REQUIRED|UNSTATED; only EVIDENCE_TEXT may state it
 * @property string $subcontract_scope EXCLUDED|REQUIRED|UNSTATED; only EVIDENCE_TEXT may state it
 * @property string $intent_digest
 * @property int $recorded_by
 * @property Carbon $recorded_at
 */
class ServiceCompletionBasis extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'service_completion_bases';

    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
