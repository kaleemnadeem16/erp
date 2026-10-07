<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PackageStock;

use Illuminate\Foundation\Http\FormRequest;

class ShowItemPackageTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyPermission(['inventory.package-state.view', 'inventory.package-state.activate', 'inventory.package-state.issue', 'purchasing.goods-receipt.create', 'inventory.physical_count.create']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
