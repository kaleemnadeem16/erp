<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Documents\Actions\ReconcileDocumentArtefact;
use App\Modules\Documents\Actions\RestoreDocumentArtefact;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Operator recovery of the stored official PDF (`sales.document.repair`, a sensitive duty, plus the right to see the quotation): `check` compares the stored object with its
 * recorded hash (missing / corrupt are marked, nothing is deleted or regenerated); `restore` brings it back only from bytes with exactly the recorded hash — a regenerated
 * PDF is not a recovery of an original.
 */
final class RepairQuotationDocument
{
    public function __construct(private readonly QuotationDocumentLocator $locator, private readonly ReconcileDocumentArtefact $reconcile, private readonly RestoreDocumentArtefact $restore) {}

    /** @return 'ok'|'missing'|'corrupt'|'unchanged' */
    public function check(User $actor, int $revisionId): string
    {
        return $this->reconcile->execute($this->guarded($actor, $revisionId), $actor->id);
    }

    /** @param resource $bytes */
    public function restore(User $actor, int $revisionId, $bytes, string $reason): void
    {
        $this->restore->execute($this->guarded($actor, $revisionId), $bytes, $reason, $actor->id);
    }

    private function guarded(User $actor, int $revisionId): int
    {
        if (! QuotationDocumentLocator::mayUse($actor, PermissionVocabulary::DOCUMENT_REPAIR)) {
            throw new AuthorizationException('You are not permitted to repair the quotation document.');
        }

        return $this->locator->artefactOrFail($revisionId)->id;
    }
}
