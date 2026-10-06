<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PhysicalCount;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Inventory\Support\PhysicalCountReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class ShowPhysicalCountRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return PhysicalCountReadAccess::allows($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['warehouse', 'lines', 'lines.item', 'lines.location', 'lines.entries']);
    }
}
