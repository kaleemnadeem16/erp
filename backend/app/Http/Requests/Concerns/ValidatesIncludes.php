<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

trait ValidatesIncludes
{
    /**
     * @param  list<string>  $includable
     * @return array<string, mixed>
     */
    protected function includeRules(array $includable): array
    {
        return [
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
        ];
    }
}
