<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryCutover;

use App\Modules\Inventory\Support\CutoverReadAccess;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;

class PreviewInventoryCutoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CutoverReadAccess::allows($this->user())
            && WarehouseScope::unrestricted($this->user(), CutoverReadAccess::PERMISSIONS);
    }
}
