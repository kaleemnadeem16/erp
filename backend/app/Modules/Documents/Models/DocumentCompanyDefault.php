<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The active template and page policy a Company chose for one document type ('*' = every type). Moves only forward, one generation at a time, and always
 * equals its activation event; the database enforces both.
 *
 * @property int $id
 * @property string $type_key
 * @property int $generation
 * @property int|null $active_template_version_id
 * @property int|null $active_page_policy_version_id
 * @property Carbon $updated_at
 */
class DocumentCompanyDefault extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['updated_at' => 'datetime'];
}
