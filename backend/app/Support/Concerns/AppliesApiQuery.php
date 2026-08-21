<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait AppliesApiQuery
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string>  $includable
     */
    private function applyIncludes(Builder $query, Request $request, array $includable): void
    {
        $relations = $this->requestedIncludes($request, $includable);

        if ($relations !== []) {
            $query->with($relations);
        }
    }

    /**
     * @param  array<string, string>  $includable
     * @return list<string>
     */
    private function requestedIncludes(Request $request, array $includable): array
    {
        $requested = array_filter(explode(',', (string) $request->query('include', '')));

        return array_values(array_map(
            fn (string $include): string => $includable[$include],
            $requested,
        ));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string>  $sortable
     */
    private function applySort(Builder $query, Request $request, array $sortable, string $default): void
    {
        $sort = $request->string('sort', $default)->toString();
        $descending = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');

        $query->orderBy($sortable[$field], $descending ? 'desc' : 'asc');

        if ($sortable[$field] !== 'id') {
            $query->orderBy('id');
        }
    }
}
