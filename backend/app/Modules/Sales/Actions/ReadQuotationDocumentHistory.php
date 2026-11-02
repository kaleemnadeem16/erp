<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Documents\Actions\ReadDocumentArtefactHistory;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Auth\Access\AuthorizationException;

/** The safe timeline of the official PDF (generated, handed out, lost/recovered). Needs `sales.document.view` and the right to see the quotation. */
final class ReadQuotationDocumentHistory
{
    public function __construct(private readonly QuotationDocumentLocator $locator, private readonly ReadDocumentArtefactHistory $history) {}

    /** @return list<array{at: string, event: string, detail: array<string, string|int|null>}> */
    public function __invoke(User $actor, int $revisionId): array
    {
        if (ActiveActor::current($actor) === null || ! SalesAccess::mayAll($actor, PermissionVocabulary::DOCUMENT_VIEW, PermissionVocabulary::QUOTATION_VIEW)) {
            throw new AuthorizationException('You are not permitted to see the quotation document history.');
        }

        return $this->history->execute($this->locator->artefactOrFail($revisionId)->id, $actor->id);
    }
}
