<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Exceptions\DomainInvariantException;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scopes a model to the current company, owns company_id from CompanyContext on
 * create, and makes that ownership immutable. Callers must switch context rather
 * than supplying or moving a row to another tenant through mass assignment.
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
            $companyId = app(CompanyContext::class)->id();

            $givenCompanyId = $model->getAttribute('company_id');

            if ($givenCompanyId !== null && (int) $givenCompanyId !== $companyId) {
                throw new DomainInvariantException(sprintf(
                    '%s cannot be created for company %s while company %s is active.',
                    class_basename($model),
                    $givenCompanyId,
                    $companyId,
                ), field: 'company_id');
            }

            $model->setAttribute('company_id', $companyId);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id')) {
                throw new DomainInvariantException(sprintf(
                    '%s company_id is immutable.',
                    class_basename($model),
                ), field: 'company_id');
            }
        });
    }
}
