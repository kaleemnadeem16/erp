<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\SupplierAccount;

use App\Http\Requests\Concerns\ValidatesIncludes;
use Illuminate\Foundation\Http\FormRequest;

class ShowSupplierAccountRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['party']);
    }
}
