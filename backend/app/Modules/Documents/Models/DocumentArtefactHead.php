<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The current artefact of a binding and purpose. An ORIGINAL head is written once and never moves; a DRAFT_PREVIEW head advances one generation at a time.
 *
 * @property int $id
 * @property int $subject_binding_id
 * @property string $purpose
 * @property int $artefact_id
 * @property int $generation
 * @property Carbon $updated_at
 */
class DocumentArtefactHead extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['updated_at' => 'datetime'];
    }
}
