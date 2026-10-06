<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Models\CustomFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomFieldDefinition */
class CustomFieldDefinitionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CustomFieldDefinition $field */
        $field = $this->resource;

        return ['id' => $field->id, 'entity_type' => $field->entity_type, 'code' => $field->code,
            'label' => $field->label, 'data_type' => $field->data_type->value,
            'choices' => $field->options['choices'] ?? [], 'is_required' => $field->is_required, 'sort_order' => $field->sort_order];
    }
}
