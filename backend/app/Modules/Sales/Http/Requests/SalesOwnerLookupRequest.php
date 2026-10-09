<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Foundation\Http\FormRequest;

/** Who can be made responsible: people who create, assign or manage deals and enquiries may look (SalesAccess::mayChoosePeople). */
class SalesOwnerLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return SalesAccess::mayChoosePeople($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['filter' => ['sometimes', 'array:search,id'], 'filter.search' => ['sometimes', 'string', 'max:255'], 'filter.id' => ['sometimes', 'integer', 'min:1']];
    }
}
