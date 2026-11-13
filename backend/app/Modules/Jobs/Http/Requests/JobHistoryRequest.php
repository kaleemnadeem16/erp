<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

use Dedoc\Scramble\Attributes\IgnoreParam;

#[IgnoreParam('include', in: 'query')]
#[IgnoreParam('filter', in: 'query')]
#[IgnoreParam('sort', in: 'query')]
class JobHistoryRequest extends ShowJobRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'filter' => ['prohibited'], 'sort' => ['prohibited'], 'include' => ['prohibited'],
        ];
    }
}
