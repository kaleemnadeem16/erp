<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A generated document: a verified file in the Files store, tied to the manifest and generation intent that produced it, with its hash, size and page count. History; the
 * database refuses updates and deletes, and it only accepts an artefact from the worker that holds the running intent at that fence.
 *
 * @property int $id
 * @property int $manifest_id
 * @property int $intent_id
 * @property int $fence
 * @property int $blob_id
 * @property string $purpose
 * @property string $media_type
 * @property string $sha256
 * @property int $size_bytes
 * @property int $page_count
 * @property string $renderer_contract
 * @property string $renderer_version
 * @property Carbon $created_at
 */
class DocumentArtefact extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<DocumentManifest, $this> */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(DocumentManifest::class, 'manifest_id');
    }
}
