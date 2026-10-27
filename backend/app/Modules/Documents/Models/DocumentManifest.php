<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What one document was made from, frozen at the owner's snapshot event: the typed payload and schema version, the source generation, the template and page
 * policy exactly as resolved, the brand state, assets, fonts, renderer contract and formatting. History; the database refuses updates and deletes, and the time
 * is the database clock.
 *
 * @property int $id
 * @property int $subject_binding_id
 * @property string $purpose DRAFT_PREVIEW|ORIGINAL
 * @property string $stable_generation_identity
 * @property string $input_digest
 * @property array<string, mixed> $frozen_payload
 * @property int $schema_version
 * @property int $source_generation
 * @property string|null $source_snapshot_reference
 * @property array<string, mixed> $resolved_template
 * @property array<string, mixed> $resolved_page_policy
 * @property int|null $template_version_id
 * @property int|null $page_policy_version_id
 * @property string $brand_state
 * @property list<mixed> $asset_manifest
 * @property list<mixed> $fonts
 * @property string $renderer_contract
 * @property string $renderer_version
 * @property array<string, mixed> $locale_formatting
 * @property string $pinned_manifest_digest
 * @property int|null $initiator_id
 * @property Carbon $recorded_at
 */
class DocumentManifest extends Model
{
    use BelongsToCompany;

    public const DRAFT_PREVIEW = 'DRAFT_PREVIEW';

    public const ORIGINAL = 'ORIGINAL';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['frozen_payload' => 'array', 'resolved_template' => 'array', 'resolved_page_policy' => 'array', 'asset_manifest' => 'array', 'fonts' => 'array',
            'locale_formatting' => 'array', 'recorded_at' => 'datetime'];
    }

    /** @return BelongsTo<DocumentSubjectBinding, $this> */
    public function binding(): BelongsTo
    {
        return $this->belongsTo(DocumentSubjectBinding::class, 'subject_binding_id');
    }

    /** @return HasMany<DocumentGenerationIntent, $this> */
    public function intents(): HasMany
    {
        return $this->hasMany(DocumentGenerationIntent::class, 'manifest_id');
    }
}
