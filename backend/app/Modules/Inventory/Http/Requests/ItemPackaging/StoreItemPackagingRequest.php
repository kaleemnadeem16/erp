<?php

namespace App\Modules\Inventory\Http\Requests\ItemPackaging;

use App\Modules\Inventory\Actions\MaintainItemPackaging;
use Illuminate\Foundation\Http\FormRequest;

class StoreItemPackagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.item.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return MaintainItemPackaging::rules();
    }
}
