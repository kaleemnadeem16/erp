<?php

declare(strict_types=1);

namespace App\Modules\Company\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Company\Models\CompanyChange;
use App\Modules\Company\Support\CompanyFields;
use App\Support\ActionAuthorization;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Changes the current company's profile and company-wide settings. Only the current company's row is ever touched; the lifecycle
 * locks (base currency, fiscal month) are enforced by the Company model itself, so they hold for every caller. Each section that
 * changed writes one immutable CompanyChange row; a change to a governed field needs an actor and a reason (ADR 0081, ADR 0071).
 */
final class UpdateCompany
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param array<string, mixed> $attributes only keys known to CompanyFields */
    public function __invoke(array $attributes, User $actor, ?string $reason = null): Company
    {
        // Each field is changed with the capability its registry entry names, whoever calls this (the request asks the same).
        foreach (CompanyFields::all() as $field) {
            if (array_key_exists($field->key, $attributes)) {
                ActionAuthorization::require($actor, $field->permission, 'change the company\'s '.$field->label);
            }
        }
        $actorId = $actor->id;

        return DB::transaction(function () use ($attributes, $actorId, $reason): Company {
            $company = Company::query()->lockForUpdate()->findOrFail($this->companyContext->id());

            $fields = collect(CompanyFields::all())->keyBy('key');
            $attributes = array_intersect_key($attributes, $fields->all());

            $old = [];
            foreach (array_keys($attributes) as $key) {
                $old[$key] = $company->getRawOriginal($key);
            }

            $company->fill($attributes);
            $changed = array_keys($company->getDirty());
            if ($changed === []) {
                return $company;
            }

            $governed = array_filter($changed, fn (string $key): bool => $fields[$key]->governed);
            if ($governed !== [] && trim((string) $reason) === '') {
                throw new DomainInvariantException('A reason is required to change '.implode(', ', array_map(fn (string $key): string => $fields[$key]->label, $governed)).'.', 'reason');
            }

            $company->save();
            $company->refresh();

            foreach (collect($changed)->groupBy(fn (string $key): string => $fields[$key]->section) as $section => $keys) {
                CompanyChange::query()->create([
                    'section' => $section,
                    'old_values' => collect($keys)->mapWithKeys(fn (string $key): array => [$key => $old[$key]])->all(),
                    'new_values' => collect($keys)->mapWithKeys(fn (string $key): array => [$key => $company->getRawOriginal($key)])->all(),
                    'reason' => trim((string) $reason) === '' ? null : trim((string) $reason),
                    'changed_by' => $actorId,
                    'changed_at' => now(),
                ]);
            }

            return $company;
        });
    }
}
