<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Support\DocumentArtefactFileOwner;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Files\Models\FileUploadIntent;

/**
 * The reconciliation sweep over stored documents: a bounded, cursor-resumable pass that compares each artefact with the object actually stored (through the owner-gated
 * single reconcile, so the document owner's decision is asked for every one) and counts the outcomes — intact, missing, corrupt, unchanged, or refused for this actor.
 * It also REPORTS generation-staged files older than a cutoff that no artefact owns (an abandoned or crashed generation). It never deletes, never regenerates and never
 * restores: removing an orphan is Files' own reconciliation slice, and a missing or corrupt original is restored only from bytes with its recorded hash.
 * Internal: no route, grant, schedule or duty.
 */
final class ReconcileDocumentArtefacts
{
    public const MAX_LIMIT = 500;

    public function __construct(private readonly ReconcileDocumentArtefact $reconcile) {}

    /** @return array{checked: int, ok: int, missing: int, corrupt: int, unchanged: int, refused: int, next_after_id: int|null, orphan_blob_ids: list<int>} */
    public function execute(int $actorId, int $afterId = 0, int $limit = 100, int $orphanOlderThanMinutes = 60): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT || $afterId < 0 || $orphanOlderThanMinutes < 1) {
            throw new DocumentException('A sweep needs a limit from 1 to '.self::MAX_LIMIT.' and a positive cutoff.', 'limit', 'sweep_invalid');
        }
        $result = ['checked' => 0, 'ok' => 0, 'missing' => 0, 'corrupt' => 0, 'unchanged' => 0, 'refused' => 0, 'next_after_id' => null, 'orphan_blob_ids' => []];
        $ids = DocumentArtefact::query()->where('id', '>', $afterId)->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($ids as $id) {
            $result['checked']++;
            try {
                $result[$this->reconcile->execute($id, $actorId)]++;
            } catch (DocumentException) {
                $result['refused']++;
            }
        }
        $result['next_after_id'] = count($ids) === $limit ? end($ids) : null;
        $result['orphan_blob_ids'] = $this->orphans($orphanOlderThanMinutes);

        return $result;
    }

    /** @return list<int> */
    private function orphans(int $olderThanMinutes): array
    {
        return FileUploadIntent::query()->where('owner_type_key', DocumentArtefactFileOwner::OWNER)->whereNotNull('blob_id')
            ->where('created_at', '<', now()->subMinutes($olderThanMinutes))
            ->whereNotIn('blob_id', DocumentArtefact::query()->select('blob_id'))->orderBy('blob_id')->limit(self::MAX_LIMIT)->pluck('blob_id')->map(fn ($id): int => (int) $id)->all();
    }
}
