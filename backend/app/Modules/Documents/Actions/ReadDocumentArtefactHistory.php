<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentAccessEvent;
use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Support\DocumentAccessGate;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Files\Models\FileBlobEvent;
use App\Support\CompanyContext;

/**
 * The safe timeline of one stored document for its owner's audience: generated, handed out (download/print, and refusals because the object was unavailable) and the
 * health changes of its file (missing, quarantined, restored). It carries no storage key, path, hash, payload or customer data, and nothing in it says the customer received
 * or accepted anything. The document owner decides (`history`, under its own duty and scope). Internal: no route yet.
 */
final class ReadDocumentArtefactHistory
{
    public function __construct(private readonly DocumentTypeRegistry $registry, private readonly CompanyContext $company) {}

    /** @return list<array{at: string, event: string, detail: array<string, string|int|null>}> */
    public function execute(int $artefactId, ?int $actorId): array
    {
        $artefact = DocumentArtefact::query()->find($artefactId) ?? throw new DocumentException('The document was not found.', 'artefact_id', 'artefact_not_found');
        $manifest = $artefact->manifest;
        $binding = $manifest->binding()->firstOrFail();
        $adapter = $this->registry->get($binding->type_key, $binding->schema_version);
        if ($actorId === null || ! DocumentAccessGate::allows($adapter, new DocumentOperationContext($this->company->id(), $actorId, 'history', $binding->type_key, $binding->audience,
            $binding->owner_binding_identity, $manifest->purpose, $manifest->source_generation, $manifest->source_snapshot_reference, $manifest->stable_generation_identity))) {
            throw new DocumentException("The owner of '{$binding->type_key}' did not authorise this operation.", null, 'access_denied');
        }

        $rows = [['at' => $artefact->created_at, 'event' => 'GENERATED', 'detail' => ['purpose' => $artefact->purpose, 'pages' => $artefact->page_count, 'renderer' => $artefact->renderer_contract.' '.$artefact->renderer_version]]];
        foreach (DocumentAccessEvent::query()->where('artefact_id', $artefact->id)->orderBy('id')->get() as $e) {
            $rows[] = ['at' => $e->occurred_at, 'event' => $e->access_kind.'_'.$e->outcome, 'detail' => ['actor_id' => $e->actor_id]];
        }
        foreach (FileBlobEvent::query()->where('blob_id', $artefact->blob_id)->whereIn('event', ['MISSING_DETECTED', 'QUARANTINED', 'RESTORED'])->orderBy('id')->get() as $e) {
            $rows[] = ['at' => $e->occurred_at, 'event' => $e->event, 'detail' => ['from' => $e->from_health, 'to' => $e->to_health, 'reason' => $e->reason, 'actor_id' => $e->actor_id]];
        }
        usort($rows, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_map(fn (array $r): array => ['at' => $r['at']->toIso8601String(), 'event' => $r['event'], 'detail' => $r['detail']], $rows);
    }
}
