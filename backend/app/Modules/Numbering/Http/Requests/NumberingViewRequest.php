<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Http\Requests;

use App\Modules\Numbering\Support\NumberingAccess;
use Illuminate\Foundation\Http\FormRequest;

/** Reading numbering configuration and using the tester. */
class NumberingViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(NumberingAccess::class)->canView($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
