<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * An intended deliverable, stable across revisions and never an Item or Assembly id (R2 H): the same Item twice is two scopes, and an alternative or a
 * superseding scope shares an overlap anchor with what it competes with. Append-only. `effectiveAnchorId()` is the anchor, or itself when it has none.
 *
 * @property int $id
 * @property int $family_id
 * @property string $intent_ref
 * @property int|null $overlap_anchor_id
 */
class QuotationLogicalScope extends Model
{
    use BelongsToCompany;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $table = 'quotation_logical_scopes';

    protected $guarded = ['id'];

    public function effectiveAnchorId(): int
    {
        return $this->overlap_anchor_id ?? $this->id;
    }
}
