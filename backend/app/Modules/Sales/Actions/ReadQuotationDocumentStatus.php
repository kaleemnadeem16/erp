<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Documents\Models\DocumentGenerationIntent;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The safe status of one issued quotation's official PDF: not generated yet / generating / failed (with a stable code) / ready, and for a ready file whether it can be handed
 * out right now. No storage key, path, hash, payload or owner identity ever appears. Needs `sales.document.view` and the right to see the quotation.
 */
final class ReadQuotationDocumentStatus
{
    public function __construct(private readonly QuotationDocumentLocator $locator) {}

    /** @return array{revision_id: int, state: string, error_code: string|null, artefact: array{id: int, pages: int, size_bytes: int, media_type: string, created_at: string, available: bool}|null} */
    public function __invoke(User $actor, int $revisionId): array
    {
        if (! QuotationDocumentLocator::mayUse($actor, PermissionVocabulary::DOCUMENT_VIEW)) {
            throw new AuthorizationException('You are not permitted to see the quotation document.');
        }

        return $this->status($revisionId);
    }

    /** @return array{revision_id: int, state: string, error_code: string|null, artefact: array{id: int, pages: int, size_bytes: int, media_type: string, created_at: string, available: bool}|null} */
    public function status(int $revisionId): array
    {
        $manifest = $this->locator->manifest($revisionId);
        $artefact = $this->locator->artefact($manifest);
        if ($artefact !== null) {
            $available = FileBlob::query()->whereKey($artefact->blob_id)->where('health', 'VERIFIED')->exists();

            return ['revision_id' => $revisionId, 'state' => 'READY', 'error_code' => null, 'artefact' => ['id' => $artefact->id, 'pages' => $artefact->page_count, 'size_bytes' => $artefact->size_bytes,
                'media_type' => $artefact->media_type, 'created_at' => $artefact->created_at->toIso8601String(), 'available' => $available]];
        }
        $intent = DocumentGenerationIntent::query()->where('manifest_id', $manifest->id)->orderByDesc('id')->first();

        return ['revision_id' => $revisionId, 'state' => $intent === null ? 'PENDING' : $intent->state, 'error_code' => $intent === null ? null : $intent->error_code, 'artefact' => null];
    }
}
