<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesListQuery
{
    /**
     * A query string is always text, and a generated client serialises a boolean as "true" or
     * "false" — which Laravel's `boolean` rule rejects (it accepts only true/false/1/0 and "1"/"0").
     * So a filter such as `filter[active]=true` used to 422, and the list screen rendered the failed
     * request as an empty list. Every boolean filter the request itself declares is normalised
     * here, once, rather than each screen working around it (ADR 0060).
     *
     * Only keys whose own rules say `boolean` are touched: `filter[search]=true` is still a search
     * for the word "true", and anything else that is not a boolean, such as `yes`, still fails.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach ($this->rules() as $key => $rules) {
            if (! str_starts_with((string) $key, 'filter.') || ! in_array('boolean', (array) $rules, true)) {
                continue;
            }

            $value = $this->input($key);
            $boolean = is_string($value) ? match (strtolower($value)) {
                'true' => true,
                'false' => false,
                default => null,
            } : null;

            if ($boolean !== null) {
                data_set($normalised, $key, $boolean);
            }
        }

        if ($normalised !== []) {
            $this->merge(['filter' => array_merge((array) $this->input('filter', []), $normalised['filter'])]);
        }
    }

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
