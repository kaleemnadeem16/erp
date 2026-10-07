<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Recalculation;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Inventory\Support\RecalculationReadAccess;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;

class ShowRecalculationRequestRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return RecalculationReadAccess::allows($this->user())
            && WarehouseScope::unrestricted($this->user(), RecalculationReadAccess::PERMISSIONS);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['item', 'location', 'destinationLocation', 'batch']);
    }
}
