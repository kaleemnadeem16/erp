<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\Assembly;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexAssemblyRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:search,assembly_group_id,status'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.assembly_group_id' => ['sometimes', 'integer'],
            'filter.status' => ['sometimes', 'string', 'in:DRAFT,ACTIVE,ARCHIVED'],
        ], $this->listQueryRules(['code', 'created_at'], [
            'workUom', 'assemblyGroup', 'versions', 'versions.components',
            'activeVersion', 'activeVersion.components',
        ]));
    }
}
