<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Support\DocumentArtefactFileOwner;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Files\Models\FileUploadIntent;
use App\Modules\Files\Support\FileOperationContext;
use App\Support\CompanyContext;

/**
 * The reconciliation sweep over stored documents: a bounded, cursor-resumable pass that compares each artefact with the object actually stored (through the owner-gated
 * single reconcile, so the document owner's decision is asked for every one) and counts the outcomes — intact, missing, corrupt, unchanged — for the artefacts this actor may repair.
 * It also counts generation-staged files older than a cutoff that no artefact owns (an abandoned or crashed generation) for documents this actor may repair. It never deletes, never regenerates and never
 * restores: removing an orphan is Files' own reconciliation slice, and a missing or corrupt original is restored only from bytes with its recorded hash.
 * Internal: no route, grant, schedule or duty.
 */
final class ReconcileDocumentArtefacts
{
    public const MAX_LIMIT = 500;

    public function __construct(private readonly ReconcileDocumentArtefact $reconcile, private readonly CompanyContext $company) {}

    /**
     * Only a currently active member of this company who the document owner authorises for the exact operation (`reconcile_artefact`) learns anything: the object of an
     * artefact the actor is not authorised for is never examined, never counted and never named. If the actor is authorised for none of the artefacts in the window the
     * answer is a plain refusal with no counts. The result carries counts and an opaque position only — no file, blob or storage identity.
     *
     * @return array{checked: int, ok: int, missing: int, corrupt: int, unchanged: int, next_after_id: int|null, orphans: int}
     */
    public function execute(int $actorId, int $afterId = 0, int $limit = 100, int $orphanOlderThanMinutes = 60): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT || $afterId < 0 || $orphanOlderThanMinutes < 1) {
            throw new DocumentException('A sweep needs a limit from 1 to '.self::MAX_LIMIT.' and a positive cutoff.', 'limit', 'sweep_invalid');
        }
        $member = User::query()->where('company_id', $this->company->id())->whereKey($actorId)->first();
        if ($member === null || $member->status !== MemberStatus::Active) {
            throw new DocumentException('You are not permitted to run this check.', null, 'access_denied');
        }
        $result = ['checked' => 0, 'ok' => 0, 'missing' => 0, 'corrupt' => 0, 'unchanged' => 0, 'next_after_id' => null, 'orphans' => 0];
        $ids = DocumentArtefact::query()->where('id', '>', $afterId)->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $refused = 0;
        foreach ($ids as $id) {
            try {
                $result[$this->reconcile->execute($id, $actorId)]++;
                $result['checked']++;
            } catch (DocumentException) {
                $refused++;
            }
        }
        if ($ids !== [] && $refused === count($ids)) {
            throw new DocumentException('You are not permitted to run this check.', null, 'access_denied');
        }
        $result['next_after_id'] = count($ids) === $limit ? end($ids) : null;
        $result['orphans'] = $this->orphans($actorId, $orphanOlderThanMinutes);

        return $result;
    }

    /** Abandoned generations, counted only for documents the owner lets this actor repair. */
    private function orphans(int $actorId, int $olderThanMinutes): int
    {
        $owner = new DocumentArtefactFileOwner;
        $count = 0;
        $identities = FileUploadIntent::query()->where('owner_type_key', DocumentArtefactFileOwner::OWNER)->whereNotNull('blob_id')
            ->where('created_at', '<', now()->subMinutes($olderThanMinutes))
            ->whereNotIn('blob_id', DocumentArtefact::query()->select('blob_id'))->orderBy('blob_id')->limit(self::MAX_LIMIT)->pluck('owner_binding_identity');
        foreach ($identities as $identity) {
            if ($owner->authorise(new FileOperationContext($this->company->id(), $actorId, 'reconcile', DocumentArtefactFileOwner::OWNER, DocumentArtefactFileOwner::PURPOSE, (string) $identity))) {
                $count++;
            }
        }

        return $count;
    }
}
