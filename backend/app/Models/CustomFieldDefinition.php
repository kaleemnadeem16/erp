<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use App\Support\CustomFieldDataType;
use Illuminate\Database\Eloquent\Builder;

/**
 * A company-defined field on some entity type (e.g. 'items', 'quotations'), added
 * without a migration or deploy — see docs/architecture/extensibility.md#runtime-custom-fields.
 * Values live in the `custom_fields` json column of the entity's own table
 * (App\Support\Concerns\HasCustomFields), not here — this table only holds the
 * schema: what fields exist, their type and validation rules.
 *
 * @property string $entity_type
 * @property string $code
 * @property string $label
 * @property CustomFieldDataType $data_type
 * @property array<string, mixed>|null $options
 * @property array<string, mixed>|null $validation
 * @property bool $is_required
 * @property bool $is_searchable
 * @property int $sort_order
 */
class CustomFieldDefinition extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_type' => CustomFieldDataType::class,
            'options' => 'array',
            'validation' => 'array',
            'is_required' => 'bool',
            'is_searchable' => 'bool',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<CustomFieldDefinition>  $query
     * @return Builder<CustomFieldDefinition>
     */
    public function scopeForEntityType(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType)->orderBy('sort_order');
    }
}
