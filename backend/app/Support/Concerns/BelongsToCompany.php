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
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $query->where('company_id', app(CompanyContext::class)->id());
        });

        static::creating(function (Model $model): void {
            // company_id isn't declared on the base Model — it's an Eloquent dynamic
            // attribute provided by whichever migration backs the model using this trait.
            // @phpstan-ignore property.notFound
            $model->company_id ??= app(CompanyContext::class)->id();
        });
    }
}
