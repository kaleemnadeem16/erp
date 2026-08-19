<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Scopes a model that has no company_id of its own by constraining through a
 * parent relation that does — directly, or itself via this same trait. See
 * docs/architecture/multi-tenancy.md "Child tables scope through their parent".
 *
 * Unlike BelongsToCompany, this never sets a company_id — there is no column
 * to set. The guarantee comes from two places instead: the read-side global
 * scope below, and the creating hook, which re-resolves the parent through
 * its own scoped query so a child can never be attached to another company's
 * parent, even if the caller passes a raw foreign key it didn't look up
 * itself.
 */
trait BelongsToCompanyThroughParent
{
    protected static function bootBelongsToCompanyThroughParent(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $query->whereHas(static::companyScopeRelation());
        });

        static::creating(function (Model $model): void {
            /** @var BelongsTo<Model, Model> $relation */
            $relation = $model->{static::companyScopeRelation()}();
            $foreignKey = $relation->getForeignKeyName();
            $parentKey = $model->getAttribute($foreignKey);

            if ($parentKey === null || ! $relation->getRelated()->newQuery()->whereKey($parentKey)->exists()) {
                throw new RuntimeException(sprintf(
                    '%s cannot be created: %s does not resolve to a %s in the current company.',
                    static::class,
                    $foreignKey,
                    $relation->getRelated()::class,
                ));
            }
        });
    }

    /**
     * The name of the belongsTo relation method that leads — directly, or
     * through further BelongsToCompanyThroughParent hops — to a company_id
     * column. Override per model.
     */
    abstract protected static function companyScopeRelation(): string;
}
