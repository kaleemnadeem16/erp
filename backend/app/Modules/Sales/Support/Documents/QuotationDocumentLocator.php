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
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Finds the official document of an issued quotation revision: its subject binding (by Sales' owner identity), the one ORIGINAL manifest frozen at issue and, once
 * generated, its current artefact. A revision that does not exist, belongs to another company or was never issued is the same "not found" — nothing about it is disclosed.
 */
final class QuotationDocumentLocator
{
    /** Exact document duties must use a fresh actor under the same fence as family visibility. */
    public function currentActor(User $actor): User
    {
        app(CompanyAccessFence::class)->lock();

        return ActiveActor::require($actor, 'access quotation documents');
    }

    /**
     * The record half of every quotation document Action's authority. The Action first requires its exact document duty itself
     * (SalesAccess::require: company-wide, because document duties have no family scope, so a limited grant is refused, never widened).
     * Then, inside the Action's transaction and before anything about the revision is read: a current ACTIVE member, and the #77
     * quotation-family policy for the independent view (OWN = current responsible owner, ALL = the company) under the company access fence,
     * held until the caller's transaction ends. A revision that is unknown, in another company or outside the caller's family scope is the
     * same DOCUMENT_NOT_FOUND (404) as an unissued one, so nothing discloses existence.
     */
    public function authorise(User $actor, int $revisionId, string $doing): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Quotation document authority must be decided inside the transaction that reads the result.');
        }
        if (ActiveActor::current($actor) === null) {
            throw new AuthorizationException("You are not permitted to {$doing}.");
        }
        try {
            app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW]);
        } catch (DomainInvariantException $refusal) {
            // Hidden, foreign and unknown revisions are one answer here: the same "not found" as an unissued one.
            throw $refusal->reasonCode === QuotationRefusal::QUOTATION_NOT_FOUND ? $this->notFound() : $refusal;
        }
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
