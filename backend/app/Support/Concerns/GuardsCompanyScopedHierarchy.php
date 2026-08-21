<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Exceptions\DomainInvariantException;
use Illuminate\Database\Eloquent\Model;

/**
 * Guards a self-referencing parent_id hierarchy on a model that carries its own
 * company_id directly (BelongsToCompany) against two things a plain foreign key
 * can't stop on its own: pointing at another company's row, and reparenting under
 * its own descendant — which would infinite-loop any resolve*() walk up parent().
 * Runs on both create and update (Eloquent's saving event covers both).
 *
 * Not for child tables without their own company_id — those use
 * BelongsToCompanyThroughParent instead. First used by
 * App\Modules\Inventory\Models\Category; extracted once
 * App\Modules\Accounting\Models\ChartOfAccount needed the identical guarantee.
 */
trait GuardsCompanyScopedHierarchy
{
    protected static function bootGuardsCompanyScopedHierarchy(): void
    {
        static::saving(function (Model $model): void {
            // guardHierarchyParent() isn't declared on the base Model — it's this trait's
            // own private method, only present on whichever class actually uses it.
            // @phpstan-ignore method.notFound
            $model->guardHierarchyParent();
        });
    }

    /**
     * The column name of the self-referencing foreign key. Override if a model
     * doesn't use the default 'parent_id'.
     */
    protected static function hierarchyParentColumn(): string
    {
        return 'parent_id';
    }

    private function guardHierarchyParent(): void
    {
        $column = static::hierarchyParentColumn();

        if (! $this->isDirty($column) || $this->{$column} === null) {
            return;
        }

        $label = class_basename(static::class);

        if (! static::query()->whereKey($this->{$column})->exists()) {
            throw new DomainInvariantException(sprintf(
                '%s cannot be saved: %s %s does not resolve to a %s in the current company.',
                $label,
                $column,
                $this->{$column},
                $label,
            ), field: $column);
        }

        $ancestorId = $this->{$column};

        while ($ancestorId !== null) {
            if ($ancestorId === $this->id) {
                throw new DomainInvariantException(sprintf(
                    '%s %s cannot be reparented under %s: that would create a cycle.',
                    $label,
                    $this->id,
                    $this->{$column},
                ), field: $column);
            }

            $ancestorId = static::query()->whereKey($ancestorId)->value($column);
        }
    }
}
