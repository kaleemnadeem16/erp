<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Models\CustomFieldDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Gives a model a `custom_fields` json column backed by App\Models\CustomFieldDefinition
 * — see docs/architecture/extensibility.md#runtime-custom-fields. The host table must
 * have a `custom_fields` jsonb column (nullable, defaulting to '{}'); this trait does
 * not add one, since it applies to whichever entity tables opt in.
 *
 * No model uses this yet — entity tables arrive in Phase 1 (see docs/STATUS.md).
 * Remove the ignore below once the first one does.
 */
// @phpstan-ignore trait.unused
trait HasCustomFields
{
    /**
     * Laravel calls initialize{Trait} once per instance (unlike boot{Trait}, which
     * runs once per class) — mergeCasts() is an instance method, so this is the hook
     * that can call it.
     */
    protected function initializeHasCustomFields(): void
    {
        $this->mergeCasts(['custom_fields' => 'array']);
    }

    /**
     * The identifier definitions are filed under in custom_field_definitions.
     * Defaults to the table name; override if a model's table doesn't match its
     * domain-facing entity name.
     */
    public static function customFieldEntityType(): string
    {
        return (new static)->getTable();
    }

    /**
     * @return Collection<int, CustomFieldDefinition>
     */
    public static function customFieldDefinitions(): Collection
    {
        return CustomFieldDefinition::query()
            ->forEntityType(static::customFieldEntityType())
            ->get();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereCustomField(Builder $query, string $code, mixed $value): Builder
    {
        return $query->where("custom_fields->{$code}", $value);
    }
}
