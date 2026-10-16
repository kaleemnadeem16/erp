<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * A family-level identity for a set of competing alternatives. Its member lines are CHOOSE_ONE lines of a revision, one of which is the baseline; the group
 * contributes that baseline once and its alternatives are never summed. Append-only.
 *
 * @property int $id
 * @property int $family_id
 * @property string $intent_ref
 */
class QuotationChoiceGroup extends Model
{
    use BelongsToCompany;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $table = 'quotation_choice_groups';

    protected $guarded = ['id'];
}
