<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Support\DocumentArtefactFileOwner;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Files\Actions\ReconcileFileBlob;

/** Checks one stored artefact against its object: missing → the file becomes MISSING, corrupt → QUARANTINED. Never regenerates and never deletes. Internal. */
final class ReconcileDocumentArtefact
{
    public function __construct(private readonly ReconcileFileBlob $reconcile) {}

    /** @return 'ok'|'missing'|'corrupt'|'unchanged' */
    public function execute(int $artefactId, ?int $actorId = null): string
    {
        $artefact = DocumentArtefact::query()->find($artefactId) ?? throw new DocumentException('The document was not found.', 'artefact_id', 'artefact_not_found');

        return $this->reconcile->execute($artefact->blob_id, DocumentArtefactFileOwner::OWNER, DocumentArtefactFileOwner::PURPOSE, 'artefact:'.$artefact->id, $actorId);
    }
}
