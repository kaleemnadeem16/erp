<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One hand-off of a stored document's bytes (or the refusal of one because the object was unavailable). History, on the database clock. Never receipt or consent.
 *
 * @property int $id
 * @property int $artefact_id
 * @property int $actor_id
 * @property string $access_kind DOWNLOAD|PRINT
 * @property string $outcome DELIVERED|UNAVAILABLE
 * @property Carbon $occurred_at
 */
class DocumentAccessEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
