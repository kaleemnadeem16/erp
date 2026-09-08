<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesListQuery
{
    /**
     * @param  list<string>  $sortable
     * @param  list<string>  $includable
     * @return array<string, mixed>
     */
    protected function listQueryRules(array $sortable, array $includable): array
    {
        $sortValues = array_merge($sortable, array_map(fn (string $field): string => "-{$field}", $sortable));

        return [
            'sort' => ['sometimes', 'string', Rule::in($sortValues)],
            'include' => [
                'sometimes',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($includable): void {
                    $requested = array_filter(explode(',', (string) $value));

                    if ($requested !== array_values(array_unique($requested))
                        || array_diff($requested, $includable) !== []) {
                        $fail("The {$attribute} field contains an unsupported or duplicate relation.");
                    }
                },
            ],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            // Laravel's paginate() already reads this from the query string regardless of
            // whether it's declared here — this is purely so it's validated, documented in
            // the OpenAPI spec and therefore typed in the generated client, matching
            // docs/architecture/api-conventions.md's "every endpoint must produce a correct
            // OpenAPI entry."
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
