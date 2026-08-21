<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Exceptions\DomainInvariantException;
use Illuminate\Database\Eloquent\Model;

/**
 * Guards a plain foreign key on a model that carries its own company_id
 * (BelongsToCompany) against pointing at another company's row on the related
 * side — e.g. customer_accounts.party_id must resolve to a Party in the current
 * company. The related model's own BelongsToCompany global scope does the actual
 * filtering; this only turns "silently invisible" into "loudly rejected".
 *
 * Not for self-referencing hierarchies (parent_id) — use
 * GuardsCompanyScopedHierarchy for those; this trait has no cycle logic.
 */
trait GuardsRelatedCompanyScope
{
    protected static function bootGuardsRelatedCompanyScope(): void
    {
        static::saving(function (Model $model): void {
            // guardRelatedCompanyScope() isn't declared on the base Model — it's this
            // trait's own private method, only present on whichever class uses it.
            // @phpstan-ignore method.notFound
            $model->guardRelatedCompanyScope();
        });
    }

    /**
     * Column => related model class to guard. E.g. ['party_id' => Party::class].
     *
     * @return array<string, class-string<Model>>
     */
    abstract protected static function companyScopedForeignKeys(): array;

    private function guardRelatedCompanyScope(): void
    {
        foreach (static::companyScopedForeignKeys() as $column => $relatedClass) {
            if (! $this->isDirty($column) || $this->{$column} === null) {
                continue;
            }

            if (! $relatedClass::query()->whereKey($this->{$column})->exists()) {
                throw new DomainInvariantException(sprintf(
                    '%s cannot be saved: %s %s does not resolve to a %s in the current company.',
                    class_basename(static::class),
                    $column,
                    $this->{$column},
                    class_basename($relatedClass),
                ), field: $column);
            }
        }
    }
}
