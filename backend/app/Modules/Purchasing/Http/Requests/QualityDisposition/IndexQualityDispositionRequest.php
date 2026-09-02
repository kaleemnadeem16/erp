<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\QualityDisposition;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexQualityDispositionRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->listQueryRules(['disposed_at', 'qty'], []);
    }
}
