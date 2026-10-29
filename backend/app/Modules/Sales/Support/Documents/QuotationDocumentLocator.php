<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Documents;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Models\DocumentArtefactHead;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Models\DocumentSubjectBinding;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;

/**
 * Finds the official document of an issued quotation revision: its subject binding (by Sales' owner identity), the one ORIGINAL manifest frozen at issue and, once
 * generated, its current artefact. A revision that does not exist, belongs to another company or was never issued is the same "not found" — nothing about it is disclosed.
 */
final class QuotationDocumentLocator
{
    /** A currently active member who holds the duty AND may see quotations; each judged by its own scope, none standing in for another. */
    public static function mayUse(User $actor, string $duty): bool
    {
        return ActiveActor::current($actor) !== null && SalesAccess::mayAll($actor, $duty, PermissionVocabulary::QUOTATION_VIEW);
    }

    public function notFound(): DomainInvariantException
    {
        return new DomainInvariantException('The quotation document was not found.', 'revision_id', 'DOCUMENT_NOT_FOUND');
    }

    public function manifest(int $revisionId): DocumentManifest
    {
        $issued = QuotationRevision::query()->whereKey($revisionId)->where('state', 'ISSUED')->exists();
        $binding = $issued ? DocumentSubjectBinding::query()->where([
            'type_key' => QuotationCustomerDocumentAdapter::KEY, 'audience' => QuotationCustomerDocumentAdapter::AUDIENCE,
            'owner_binding_identity' => QuotationCustomerProjection::ownerBindingIdentity($revisionId),
        ])->first() : null;

        return ($binding === null ? null : DocumentManifest::query()->where(['subject_binding_id' => $binding->id, 'purpose' => DocumentManifest::ORIGINAL])->first()) ?? throw $this->notFound();
    }

    public function artefact(DocumentManifest $manifest): ?DocumentArtefact
    {
        $head = DocumentArtefactHead::query()->where(['subject_binding_id' => $manifest->subject_binding_id, 'purpose' => DocumentManifest::ORIGINAL])->first();

        return $head === null ? null : DocumentArtefact::query()->find($head->artefact_id);
    }

    public function artefactOrFail(int $revisionId): DocumentArtefact
    {
        return $this->artefact($this->manifest($revisionId)) ?? throw new DomainInvariantException('The quotation document is not generated yet.', 'revision_id', 'DOCUMENT_NOT_READY');
    }
}
