<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Recalculation;

use Illuminate\Foundation\Http\FormRequest;

class ResumeRecalculationRegenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['required', 'string'],
        ];
    }
}
