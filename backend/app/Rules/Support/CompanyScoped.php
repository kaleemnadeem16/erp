<?php

declare(strict_types=1);

namespace App\Rules\Support;

use App\Support\CompanyContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Company-scoped exists()/unique() rules for FormRequests, so a foreign key
 * that exists but belongs to another company fails validation with a normal
 * 422 instead of only being caught later by a model guard (see
 * App\Exceptions\DomainInvariantException, the backstop for the same check).
 * Rule::exists()/unique() alone don't know about BelongsToCompany's global
 * scope — they build a plain query, not an Eloquent one.
 */
final class CompanyScoped
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where('company_id', app(CompanyContext::class)->id());
    }

    public static function unique(string $table, string $column, mixed $ignoreId = null): Unique
    {
        $rule = Rule::unique($table, $column)
            ->where('company_id', app(CompanyContext::class)->id());

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }
}
