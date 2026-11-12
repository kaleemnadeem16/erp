<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/** @internal Resolves the accepted source of a prepared stage or release event through the preparation keys (stage -> allocation -> plan -> acceptance). Company-scoped; reads only. */
final class ReleaseLineage
{
    public static function acceptanceIdOfStage(int $stageId): ?int
    {
        $id = DB::table('handover_stages as s')
            ->join('handover_allocations as a', fn ($j) => $j->on('a.company_id', '=', 's.company_id')->on('a.id', '=', 's.allocation_id'))
            ->join('handover_plans as p', fn ($j) => $j->on('p.company_id', '=', 'a.company_id')->on('p.id', '=', 'a.plan_id'))
            ->where('s.company_id', app(CompanyContext::class)->id())->where('s.id', $stageId)->value('p.acceptance_id');

        return $id === null ? null : (int) $id;
    }
}
