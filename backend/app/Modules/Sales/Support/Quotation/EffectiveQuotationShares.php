<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Models\User;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** A correlated EXISTS, shared by lists and single-record checks; no per-family queries or client clock. */
final class EffectiveQuotationShares
{
    public function query(int $recipientId, string $duty): Builder
    {
        $manifest = app(SalesDutyManifest::class);
        $query = DB::table('quotation_access_grants as share')
            ->selectRaw('1')
            ->join('users as issuer', function ($join): void {
                $join->on('issuer.id', '=', 'share.issuer_id')->on('issuer.company_id', '=', 'share.company_id');
            })
            ->join('users as recipient', function ($join): void {
                $join->on('recipient.id', '=', 'share.recipient_id')->on('recipient.company_id', '=', 'share.company_id');
            })
            ->join('company_access_generations as epochs', 'epochs.company_id', '=', 'share.company_id')
            ->whereColumn('share.company_id', 'quotation_families.company_id')
            ->whereColumn('share.family_id', 'quotation_families.id')
            ->where('share.recipient_id', $recipientId)
            ->where('issuer.status', 'ACTIVE')->where('recipient.status', 'ACTIVE')
            ->whereColumn('share.issuer_authorization_generation', 'issuer.authorization_generation')
            ->whereColumn('share.recipient_authorization_generation', 'recipient.authorization_generation')
            ->whereColumn('share.organisation_generation', 'epochs.organisation_generation')
            ->whereColumn('share.capability_generation', 'epochs.capability_generation')
            ->whereColumn('share.ownership_generation', 'quotation_families.ownership_generation')
            ->whereRaw('share.starts_at <= statement_timestamp()')
            ->where(fn (Builder $end) => $end->whereNull('share.ends_at')->orWhereRaw('statement_timestamp() < share.ends_at'))
            ->whereNotExists(fn (Builder $revoked) => $revoked->selectRaw('1')->from('quotation_access_revocations as revoked')
                ->whereColumn('revoked.company_id', 'share.company_id')->whereColumn('revoked.grant_id', 'share.id'));
        if (! $manifest->isActive(Duty::QUOTATION_ACCESS_DELEGATE) || ! $manifest->isActive(Duty::QUOTATION_VIEW)
            || ! in_array($duty, [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE], true)) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereExists($this->permission('issuer', Duty::QUOTATION_ACCESS_DELEGATE))
            ->whereExists($this->permission('issuer', Duty::QUOTATION_VIEW))
            ->whereExists($this->permission('recipient', Duty::QUOTATION_VIEW));
        if ($duty === Duty::QUOTATION_PREPARE) {
            $query->where('share.level', 'PREPARE');
        }

        return $query->where(function (Builder $level) use ($manifest): void {
            $level->where('share.level', 'VIEW');
            if ($manifest->isActive(Duty::QUOTATION_PREPARE)) {
                $level->orWhere(fn (Builder $prepare) => $prepare->where('share.level', 'PREPARE')
                    ->whereExists($this->permission('issuer', Duty::QUOTATION_PREPARE))
                    ->whereExists($this->permission('recipient', Duty::QUOTATION_PREPARE)));
            }
        });
    }

    /** Epoch pins permanently invalidate changed scope; these predicates independently verify current capability paths. */
    private function permission(string $userAlias, string $duty): Builder
    {
        $morph = (new User)->getMorphClass();

        return DB::table('permissions as share_permission')->selectRaw('1')->where('share_permission.name', $duty)->where('share_permission.guard_name', 'web')
            ->where(function (Builder $paths) use ($userAlias, $morph): void {
                $paths->whereExists(fn (Builder $direct) => $direct->selectRaw('1')->from('model_has_permissions as share_direct')
                    ->whereColumn('share_direct.permission_id', 'share_permission.id')
                    ->whereColumn('share_direct.model_id', $userAlias.'.id')->whereColumn('share_direct.team_id', $userAlias.'.company_id')
                    ->where('share_direct.model_type', $morph))
                    ->orWhereExists(fn (Builder $roles) => $roles->selectRaw('1')->from('model_has_roles as share_member_role')
                        ->join('roles as share_role', 'share_role.id', '=', 'share_member_role.role_id')
                        ->join('role_has_permissions as share_role_permission', 'share_role_permission.role_id', '=', 'share_role.id')
                        ->whereColumn('share_role_permission.permission_id', 'share_permission.id')
                        ->whereColumn('share_member_role.model_id', $userAlias.'.id')->whereColumn('share_member_role.team_id', $userAlias.'.company_id')
                        ->whereColumn('share_role.team_id', $userAlias.'.company_id')->where('share_member_role.model_type', $morph));
            });
    }
}
