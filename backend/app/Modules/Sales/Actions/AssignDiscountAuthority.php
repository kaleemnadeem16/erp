<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\SalesDiscountAssignment;
use App\Modules\Sales\Models\SalesDiscountBand;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityOperation;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityRefusal;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class AssignDiscountAuthority
{
    /** @param array<string, mixed> $input */
    public function __invoke(User $actor, string $key, int $expectedGeneration, array $input): SalesDiscountAssignment
    {
        SalesAccess::require(ActiveActor::require($actor), PermissionVocabulary::FINANCIAL_CONFIGURE);
        $result = app(AuthorityOperation::class)->run($actor, PermissionVocabulary::FINANCIAL_CONFIGURE, $key, $expectedGeneration, ['operation' => 'assignment.create', 'input' => $input], function () use ($actor, $input): array {
            $data = Validator::make($input, [
                'user_id' => ['required', 'integer', 'min:1'], 'band_id' => ['required', 'integer', 'min:1'], 'role_id' => ['nullable', 'integer', 'min:1'], 'unit_id' => ['nullable', 'integer', 'min:1'],
                'purpose' => ['required', Rule::in(['ROUTINE', 'APPROVE', 'BOTH'])], 'effective_from' => ['required', 'date_format:Y-m-d'],
                'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'], 'reason' => ['required', 'string', 'max:1000'],
            ])->validate();
            $company = app(CompanyContext::class)->id();
            User::query()->where('company_id', $company)->findOrFail($data['user_id']);
            $band = SalesDiscountBand::findOrFail($data['band_id']);
            if (($data['role_id'] ?? null) !== null && ! DB::table('roles')->where('team_id', $company)->where('id', $data['role_id'])->exists()) {
                throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'That role is not in this company.', 'role_id');
            }
            if (($data['unit_id'] ?? null) !== null && ! DB::table('organisation_units')->where('company_id', $company)->where('id', $data['unit_id'])->exists()) {
                throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'That unit is not in this company.', 'unit_id');
            }
            if ($band->exception_only && $data['purpose'] !== 'APPROVE') {
                throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'An exception-only band cannot grant routine authority.', 'purpose');
            }
            $overlap = SalesDiscountAssignment::query()->where('user_id', $data['user_id'])
                ->whereIn('band_id', SalesDiscountBand::query()->where('policy_id', $band->policy_id)->select('id'))
                ->where(fn ($q) => $q->whereNull('unit_id')->when(($data['unit_id'] ?? null) !== null, fn ($q) => $q->orWhere('unit_id', $data['unit_id']), fn ($q) => $q->orWhereNotNull('unit_id')))
                ->where(fn ($q) => $q->where('purpose', 'BOTH')->when($data['purpose'] !== 'BOTH', fn ($q) => $q->orWhere('purpose', $data['purpose']), fn ($q) => $q->orWhereIn('purpose', ['ROUTINE', 'APPROVE'])))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $data['effective_from']))
                ->when(($data['effective_to'] ?? null) !== null, fn ($q) => $q->where('effective_from', '<', $data['effective_to']))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sales_discount_revocations as r')->whereColumn('r.assignment_id', 'sales_discount_assignments.id')->whereColumn('r.company_id', 'sales_discount_assignments.company_id'))
                ->exists();
            if ($overlap) {
                throw AuthorityRefusal::make('AUTHORITY_ASSIGNMENT_AMBIGUOUS', 'This employee already has overlapping authority for this policy and unit. Revoke or end it before assigning another band.');
            }
            $data['assigned_by'] = $actor->id;

            return ['id' => SalesDiscountAssignment::create($data)->id];
        });

        return SalesDiscountAssignment::findOrFail($result['id']);
    }
}
