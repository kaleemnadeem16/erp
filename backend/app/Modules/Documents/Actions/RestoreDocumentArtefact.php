<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Support\DocumentArtefactFileOwner;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Files\Actions\RestoreFileBlob;
use App\Modules\Files\Support\FileException;

/**
 * Brings a MISSING or QUARANTINED artefact back from a verified copy of its bytes. The bytes must be exactly the recorded hash and size — the original is evidence and is
 * never replaced by a regenerated file. Internal.
 */
final class RestoreDocumentArtefact
{
    public function __construct(private readonly RestoreFileBlob $restore) {}

    /** @param resource $stream */
    public function execute(int $artefactId, $stream, string $reason, ?int $actorId = null): DocumentArtefact
    {
        $artefact = DocumentArtefact::query()->find($artefactId) ?? throw new DocumentException('The document was not found.', 'artefact_id', 'artefact_not_found');
        try {
            $this->restore->execute($artefact->blob_id, $stream, DocumentArtefactFileOwner::OWNER, DocumentArtefactFileOwner::PURPOSE, 'manifest:'.$artefact->manifest_id, $reason, $actorId);
        } catch (FileException $e) {
            throw new DocumentException($e->getMessage(), $e->field, 'restore_'.$e->reasonCode);
        }

        return $artefact;
    }
}
