<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One document identity: registered type and version, audience, the owner's binding identity and source generation. History; the database refuses updates
 * and deletes. The subject FK lives in the owning module's binding table.
 *
 * @property int $id
 * @property string $type_key
 * @property int $schema_version
 * @property string $audience
 * @property string $owner_binding_identity
 * @property int $source_generation
 * @property int|null $bound_by
 * @property Carbon $bound_at
 */
class DocumentSubjectBinding extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['bound_at' => 'datetime'];
}
