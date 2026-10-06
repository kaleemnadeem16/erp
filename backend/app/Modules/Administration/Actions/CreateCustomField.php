<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Models\CustomFieldDefinition;
use App\Models\User;
use App\Modules\Administration\Http\Requests\StoreCustomFieldRequest;
use App\Support\ActionAuthorization;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

final class CreateCustomField
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): CustomFieldDefinition
    {
        ActionAuthorization::require($actor, 'administration.custom-field.manage', 'define custom fields');
        // Non-HTTP callers follow the same entity/type/tenant-safe uniqueness contract.
        $request = new StoreCustomFieldRequest($attributes);
        $attributes = Validator::make($attributes, $request->rules())->validate();

        return CustomFieldDefinition::query()->create(Arr::only($attributes, ['entity_type', 'code', 'label', 'data_type', 'options', 'is_required', 'sort_order']));
    }
}
