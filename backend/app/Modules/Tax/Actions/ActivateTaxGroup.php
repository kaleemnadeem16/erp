<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Models\User;
use App\Modules\Tax\Models\TaxGroupActivation;
use App\Modules\Tax\Models\TaxGroupVersion;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\TaxMutation;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

final class ActivateTaxGroup
{
    public function __construct(private readonly TaxMutation $mutation) {}

    public function __invoke(User $actor, int $groupId, string $reason, string $key, int $expectedGeneration): int
    {
        TaxAccess::requireConfiguration($actor);
        $outcome = $this->mutation->run($key, $expectedGeneration, ['operation' => 'activate', 'actor' => $actor->id, 'group' => $groupId, 'reason' => $reason], static function () use ($actor, $groupId, $reason): array {
            TaxGroupVersion::query()->findOrFail($groupId);
            DB::select('SELECT tax_fr2a_validate_group(?, ?)', [app(CompanyContext::class)->id(), $groupId]);
            $row = TaxGroupActivation::create(['group_version_id' => $groupId, 'activated_by' => $actor->id, 'reason' => $reason]);

            return ['activation_id' => (int) $row->getKey()];
        });

        return $outcome['activation_id'];
    }
}
