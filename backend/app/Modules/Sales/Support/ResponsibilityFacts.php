<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\OpportunityAttributionCorrection;
use App\Modules\Sales\Models\SalesHistory;

/**
 * The recorded owner facts of an opportunity — who it was created for, and who it was reassigned to — each with what the record currently says after any
 * corrections. The recorded fact is never replaced: the corrected view is an overlay and both are returned (ADR 0112). Names come from the company's own
 * members, including colleagues who have since left.
 */
final class ResponsibilityFacts
{
    /** @return list<array{event_id: int, target_field: string, occurred_at: string, recorded_owner_id: int, recorded_owner_name: string|null, effective_owner_id: int, effective_owner_name: string|null, corrected: bool, head_id: int|null}> */
    public static function for(Opportunity $opportunity): array
    {
        $events = SalesHistory::query()->where('opportunity_id', $opportunity->id)->whereIn('event', [SalesEvent::OPPORTUNITY_CREATED, SalesEvent::OWNER_CHANGED])->orderBy('id')->get();
        /** @var array<string, OpportunityAttributionCorrection> $heads */
        $heads = [];
        foreach (OpportunityAttributionCorrection::query()->where('opportunity_id', $opportunity->id)->orderBy('id')->get() as $correction) {
            $heads[$correction->target_event_id.':'.$correction->target_field] = $correction;
        }
        /** @var list<array{event: SalesHistory, field: string, recorded: int, head: OpportunityAttributionCorrection|null}> $rows */
        $rows = [];
        $ids = [];
        foreach ($events as $event) {
            $field = $event->event === SalesEvent::OPPORTUNITY_CREATED ? OpportunityAttributionCorrection::OWNER_ID : OpportunityAttributionCorrection::TO_OWNER_ID;
            $recorded = $event->details[$field === OpportunityAttributionCorrection::OWNER_ID ? 'owner_id' : 'to_owner_id'] ?? null;
            if ($recorded === null) {
                continue;
            }
            /** @var OpportunityAttributionCorrection|null $head */
            $head = $heads[$event->id.':'.$field] ?? null;
            $rows[] = ['event' => $event, 'field' => $field, 'recorded' => (int) $recorded, 'head' => $head];
            $ids[] = (int) $recorded;
            if ($head !== null) {
                $ids[] = $head->asserted_owner_id;
            }
        }
        $names = User::query()->whereIn('id', array_values(array_unique($ids)))->pluck('name', 'id');

        $facts = [];
        foreach ($rows as $row) {
            $effective = $row['head'] === null ? $row['recorded'] : $row['head']->asserted_owner_id;
            $facts[] = [
                'event_id' => (int) $row['event']->id,
                'target_field' => $row['field'],
                'occurred_at' => $row['event']->occurred_at->toIso8601String(),
                'recorded_owner_id' => (int) $row['recorded'],
                'recorded_owner_name' => $names[$row['recorded']] ?? null,
                'effective_owner_id' => (int) $effective,
                'effective_owner_name' => $names[(int) $effective] ?? null,
                'corrected' => $row['head'] !== null,
                'head_id' => $row['head'] === null ? null : (int) $row['head']->id,
            ];
        }

        return $facts;
    }
}
