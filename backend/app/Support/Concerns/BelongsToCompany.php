<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scopes a model to the current company and auto-fills company_id on create.
 * See docs/architecture/multi-tenancy.md rule 3 — the resolver behind
 * CompanyContext is the only thing that changes when multi-tenancy is enabled.
 */
// @phpstan-ignore trait.unused (no company-scoped model exists yet)
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $query->where('company_id', app(CompanyContext::class)->id());
        });

        static::creating(function (Model $model): void {
            $model->company_id ??= app(CompanyContext::class)->id();
        });
    }
}
