<?php

namespace App\Modules\Inventory\Http\Requests\ItemPackaging;

class UpdateItemPackagingRequest extends StoreItemPackagingRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach ($rules as &$rule) {
            $rule = array_values(array_filter($rule, fn ($r) => $r !== 'required'));
            array_unshift($rule, 'sometimes');
        }

        return $rules;
    }
}
