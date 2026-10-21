<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * Append-only record of one change of a Company default: what it moved to and from, whether it was a downgrade (which needs a reason), who and when.
 *
 * @property int $id
 * @property string $type_key
 * @property int $generation
 * @property int|null $template_version_id
 * @property int|null $page_policy_version_id
 * @property int|null $previous_template_version_id
 * @property int|null $previous_page_policy_version_id
 * @property bool $is_downgrade
 * @property int|null $actor_id
 * @property string|null $reason
 * @property Carbon $activated_at
 */
class DocumentDefaultActivation extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['is_downgrade' => 'boolean', 'activated_at' => 'datetime'];
}
