<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Models\User;
use App\Modules\Tax\Models\TaxDependencyVersion;
use App\Modules\Tax\Models\TaxGroupMember;
use App\Modules\Tax\Models\TaxGroupVersion;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\TaxMutation;
use App\Modules\Tax\Support\TaxRefusal;

/** Writes the complete immutable graph in one transaction; incremental Action writes are deliberately unsupported. */
final class DefineTaxGroupGraph
{
    public function __construct(private readonly TaxMutation $mutation) {}

    /** @param list<array{component_id: int, rule_version_id: int, predecessors: list<int>}> $members */
    public function __invoke(User $actor, int $groupId, array $members, string $key, int $expectedGeneration): void
    {
        TaxAccess::requireConfiguration($actor);
        $this->mutation->run($key, $expectedGeneration, ['operation' => 'define_graph', 'actor' => $actor->id, 'group' => $groupId, 'members' => $members], static function () use ($groupId, $members): array {
            TaxGroupVersion::query()->findOrFail($groupId);
            if (TaxGroupMember::query()->where('group_version_id', $groupId)->exists()) {
                throw TaxRefusal::because('TAX_GRAPH_ALREADY_DEFINED', 'Create a successor to change an existing graph.');
            }
            foreach ($members as $member) {
                TaxGroupMember::create(['group_version_id' => $groupId, 'component_id' => $member['component_id'], 'rule_version_id' => $member['rule_version_id']]);
            }
            foreach ($members as $member) {
                foreach ($member['predecessors'] as $predecessor) {
                    TaxDependencyVersion::create(['group_version_id' => $groupId, 'component_id' => $member['component_id'], 'predecessor_component_id' => $predecessor]);
                }
            }

            return ['group_version_id' => $groupId];
        });
    }
}
