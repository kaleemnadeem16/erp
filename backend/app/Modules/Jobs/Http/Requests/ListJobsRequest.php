<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Jobs\Queries\JobReadFilter;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Validation\Rule;

#[IgnoreParam('include', in: 'query')]
class ListJobsRequest extends ShowJobRequest
{
    use ValidatesListQuery;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge($this->listQueryRules(['number', 'state', 'created_at', 'released_at', 'started_at'], []), [
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'include' => ['prohibited'],
            'filter' => ['sometimes', 'array:search,state,responsible_member_id,has_open_hold,created_from,created_until'],
            'filter.search' => ['sometimes', 'string', 'max:200'],
            'filter.state' => ['sometimes', Rule::in(['RELEASED', 'ACTIVE', 'COMPLETED', 'CLOSED'])],
            'filter.responsible_member_id' => ['sometimes', 'integer', 'min:1'],
            'filter.has_open_hold' => ['sometimes', 'boolean'],
            'filter.created_from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.created_until' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('filter.created_from'), ['after_or_equal:filter.created_from'])],
        ]);
    }

    public function readFilter(): JobReadFilter
    {
        $sort = $this->string('sort', '-created_at')->toString();

        return new JobReadFilter(
            page: $this->integer('page', 1), perPage: $this->integer('per_page', 25),
            search: $this->input('filter.search'), state: $this->input('filter.state'),
            responsibleMemberId: $this->has('filter.responsible_member_id') ? $this->integer('filter.responsible_member_id') : null,
            hasOpenHold: $this->has('filter.has_open_hold') ? $this->boolean('filter.has_open_hold') : null,
            createdFrom: $this->input('filter.created_from'), createdUntil: $this->input('filter.created_until'),
            sort: ltrim($sort, '-'), direction: str_starts_with($sort, '-') ? 'desc' : 'asc',
        );
    }
}
