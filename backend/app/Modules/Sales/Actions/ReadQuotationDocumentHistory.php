<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Documents\Actions\ReadDocumentArtefactHistory;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Support\Facades\DB;

/** The safe timeline of the official PDF (generated, handed out, lost/recovered), trimmed to an allowlist of fields per event. Needs `sales.document.view` and the right to see the quotation. */
final class ReadQuotationDocumentHistory
{
    public function __construct(private readonly QuotationDocumentLocator $locator, private readonly ReadDocumentArtefactHistory $history) {}

    /** @return list<array{at: string, event: string, detail: array<string, string|int|null>}> */
    public function __invoke(User $actor, int $revisionId): array
    {
        return DB::transaction(function () use ($actor, $revisionId): array {
            SalesAccess::require($this->locator->currentActor($actor), PermissionVocabulary::DOCUMENT_VIEW, 'see the quotation document history');
            $this->locator->authorise($actor, $revisionId, 'see the quotation document history');

            return array_map($this->trimmed(...), $this->history->execute($this->locator->artefactOrFail($revisionId)->id, $actor->id));
        });
    }

    /**
     * Sales shows only what a quotation user needs: the page count of a generated file, who handed a file out, and the stable reason a stored file was marked.
     * The renderer contract and version, the purpose, the file health states and the operator behind a file repair stay inside Documents.
     *
     * @param  array{at: string, event: string, detail: array<string, string|int|null>}  $entry
     * @return array{at: string, event: string, detail: array<string, string|int|null>}
     */
    private function trimmed(array $entry): array
    {
        $detail = $entry['detail'];
        $safe = match (true) {
            $entry['event'] === 'GENERATED' => ['pages' => isset($detail['pages']) ? (int) $detail['pages'] : null],
            str_ends_with($entry['event'], '_DELIVERED') || str_ends_with($entry['event'], '_UNAVAILABLE') => ['actor_id' => isset($detail['actor_id']) ? (int) $detail['actor_id'] : null],
            in_array($entry['event'], ['MISSING_DETECTED', 'QUARANTINED', 'RESTORED'], true) => ['reason' => in_array($detail['reason'] ?? null, ['object_missing', 'object_corrupt', 'infected', 'restored_identical_bytes'], true) ? $detail['reason'] : null],
            default => [],
        };

        return ['at' => $entry['at'], 'event' => $entry['event'], 'detail' => $safe];
    }
}
