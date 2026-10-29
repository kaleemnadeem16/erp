<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Documents\Actions\GenerateDocumentArtefact;
use App\Modules\Documents\Actions\RequestDocumentGeneration;
use App\Modules\Documents\Models\DocumentGenerationIntent;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Generates (or retries) the official PDF of an issued quotation from the original manifest frozen at issue — never from the live quotation, so a later change cannot alter it.
 * Needs `sales.document.generate` and the right to see the quotation. Idempotent: an already generated document is returned as it is; a generation another worker holds is
 * reported as running; a FAILED generation gets a new retry intent (under the caller's key) and is run. A generation failure is reported as the stable status, not an error.
 */
final class RequestQuotationDocument
{
    public function __construct(
        private readonly QuotationDocumentLocator $locator,
        private readonly ReadQuotationDocumentStatus $status,
        private readonly RequestDocumentGeneration $requests,
        private readonly GenerateDocumentArtefact $generate,
    ) {}

    /** @return array{revision_id: int, state: string, error_code: string|null, artefact: array{id: int, pages: int, size_bytes: int, media_type: string, created_at: string, available: bool}|null} */
    public function __invoke(User $actor, int $revisionId, string $operationKey): array
    {
        if (ActiveActor::current($actor) === null || ! SalesAccess::mayAll($actor, PermissionVocabulary::DOCUMENT_GENERATE, PermissionVocabulary::QUOTATION_VIEW)) {
            throw new AuthorizationException('You are not permitted to generate the quotation document.');
        }
        $manifest = $this->locator->manifest($revisionId);
        if ($this->locator->artefact($manifest) !== null) {
            return $this->status->status($revisionId);
        }
        $intent = DocumentGenerationIntent::query()->where('manifest_id', $manifest->id)->orderByDesc('id')->first();
        if ($intent === null || $intent->state === 'FAILED') {
            $intent = $this->requests->execute($manifest->id, 'retry:'.substr(hash('sha256', $operationKey), 0, 32), $operationKey, $actor->id)['intent'];
        }
        try {
            $this->generate->execute($intent->id, $actor->id);
        } catch (DocumentException $e) {
            // A refusal of the owner or a generation another worker holds is a status, not a crash; anything else is recorded on the intent by the worker.
            if ($e->reasonCode === 'access_denied') {
                throw new AuthorizationException('You are not permitted to generate the quotation document.');
            }
        }

        return $this->status->status($revisionId);
    }
}
