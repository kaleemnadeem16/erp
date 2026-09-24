<?php

declare(strict_types=1);

namespace App\Rules\Support;

use Illuminate\Contracts\Validation\ImplicitRule;

/**
 * A plain closure passed directly in a rules array is skipped by Laravel's
 * validator whenever the field is absent or `nullable` and empty — the
 * same skip that lets `nullable` exempt a field from `integer`/`exists`.
 * That's wrong for a closure whose whole job is deciding *whether an empty
 * value is actually allowed* (a conditional-requiredness check) — it needs
 * to run precisely in the case a plain closure never sees.
 *
 * ImplicitRule is the documented way to opt back into running on empty
 * values (the same mechanism Laravel's own required_if uses internally),
 * but — despite being the interface the validator actually checks for —
 * it still extends the deprecated passes()/message() Rule contract, not
 * the newer closure-style ValidationRule; there is no implicit-marked
 * variant of the new interface. This class adapts this codebase's usual
 * (string $attribute, mixed $value, \Closure $fail) closure shape onto
 * that older contract so call sites don't need to know the difference.
 */
final class ImplicitClosureRule implements ImplicitRule
{
    private ?string $failureMessage = null;

    public function __construct(private readonly \Closure $callback) {}

    public function passes($attribute, $value): bool
    {
        $this->failureMessage = null;

        ($this->callback)($attribute, $value, function (string $message): void {
            $this->failureMessage = $message;
        });

        return $this->failureMessage === null;
    }

    /** @return string */
    public function message()
    {
        return $this->failureMessage ?? 'The :attribute field is invalid.';
    }
}
