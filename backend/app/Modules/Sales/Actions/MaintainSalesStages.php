<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\SalesStage;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Support\Facades\DB;

/**
 * Configure the OPEN stages (ADR 0107). A stage is archived, never deleted, and what history recorded about it (its name then) is not touched by a rename or an archive.
 * At least one stage stays active so a deal can always be created.
 */
final class MaintainSalesStages
{
    /** @var list<array{code: string, name: string}> */
    public const SUGGESTED = [['code' => 'QUALIFICATION', 'name' => 'Qualification'], ['code' => 'PROPOSAL', 'name' => 'Proposal'], ['code' => 'NEGOTIATION', 'name' => 'Negotiation']];

    /** @param array{code: string, name: string, sort_order?: int} $input */
    public function create(array $input, User $actor): SalesStage
    {
        SalesAccess::require($actor, PermissionVocabulary::STAGE_MANAGE, 'configure pipeline stages');
        $code = strtoupper(trim($input['code']));
        $name = trim($input['name']);
        if ($code === '' || $name === '') {
            throw new DomainInvariantException('A stage needs a code and a name.', 'name');
        }

        return DB::transaction(function () use ($code, $name, $input): SalesStage {
            if (SalesStage::query()->where('code', $code)->exists()) {
                throw new DomainInvariantException('A stage with that code already exists.', 'code', 'STAGE_CODE_IN_USE');
            }
            $order = $input['sort_order'] ?? ((int) SalesStage::query()->max('sort_order') + 10);

            return SalesStage::create(['code' => $code, 'name' => $name, 'sort_order' => $order, 'status' => SalesStage::ACTIVE])->refresh();
        });
    }

    /** @param array{name?: string, sort_order?: int, status?: string} $input */
    public function update(SalesStage $stage, array $input, User $actor): SalesStage
    {
        SalesAccess::require($actor, PermissionVocabulary::STAGE_MANAGE, 'configure pipeline stages');

        return DB::transaction(function () use ($stage, $input): SalesStage {
            $locked = SalesStage::query()->whereKey($stage->id)->lockForUpdate()->firstOrFail();
            $changes = [];
            if (isset($input['name'])) {
                $name = trim($input['name']);
                if ($name === '') {
                    throw new DomainInvariantException('A stage needs a name.', 'name');
                }
                $changes['name'] = $name;
            }
            if (isset($input['sort_order'])) {
                $changes['sort_order'] = (int) $input['sort_order'];
            }
            if (isset($input['status']) && $input['status'] !== $locked->status) {
                if ($input['status'] === SalesStage::ARCHIVED && SalesStage::query()->where('status', SalesStage::ACTIVE)->whereKeyNot($locked->id)->doesntExist()) {
                    throw new DomainInvariantException('Keep at least one active stage.', 'status', 'LAST_ACTIVE_STAGE');
                }
                $changes['status'] = $input['status'];
            }
            $locked->update($changes);

            return $locked->refresh();
        });
    }

    /**
     * Start from the suggested Qualification → Proposal → Negotiation, only when nothing is configured yet. Idempotent.
     *
     * @return array<int, SalesStage>
     */
    public function seedSuggested(User $actor): array
    {
        SalesAccess::require($actor, PermissionVocabulary::STAGE_MANAGE, 'configure pipeline stages');

        return DB::transaction(function () use ($actor): array {
            if (SalesStage::query()->exists()) {
                return SalesStage::query()->orderBy('sort_order')->get()->all();
            }
            $created = [];
            foreach (self::SUGGESTED as $i => $stage) {
                $created[] = $this->create($stage + ['sort_order' => ($i + 1) * 10], $actor);
            }

            return $created;
        });
    }
}
