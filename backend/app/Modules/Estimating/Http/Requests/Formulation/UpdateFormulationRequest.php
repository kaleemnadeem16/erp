<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\Formulation;

use App\Modules\Estimating\Models\Formulation;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFormulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Formulation|null $formulation */
        $formulation = $this->route('formulation');

        return [
            // output_item_id/customer_id are deliberately absent — a formulation
            // cannot be repointed, see Formulation::booted().
            'code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('formulations', 'code', $formulation?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'output_qty' => ['sometimes', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'output_uom_id' => ['sometimes', 'integer', CompanyScoped::exists('uoms')],
        ];
    }
}
