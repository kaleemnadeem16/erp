<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Contracts\RuleTransformer;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;

/**
 * Documents every `decimal:` validation rule as a JSON string.
 *
 * Invariant 6 (docs/architecture/invariants.md) — money is decimal(19,4) and
 * never a float. Read paths already satisfy this: Eloquent's `decimal:4` casts
 * emit strings. Write paths did not, because Scramble maps the `decimal` rule
 * to a number, so the generated TypeScript client typed every quantity and
 * money field as `number` and the screens cast into one. A decimal(19,4) can
 * hold more significant digits than a double represents exactly, so that cast
 * silently altered large values on the way in.
 *
 * Laravel's `decimal` rule accepts numeric strings unchanged, and its size
 * comparisons (`gt`, `min`, `max`, `between`) still compare numerically
 * because `decimal` is one of the rules that marks an attribute as numeric —
 * so only the documented type changes, never the validation.
 */
final class DecimalAsStringRuleTransformer implements RuleTransformer
{
    public function shouldHandle(NormalizedRule $rule): bool
    {
        return $rule->is('decimal');
    }

    public function toSchema(Type $previous, NormalizedRule $rule, RuleTransformerContext $context): Type
    {
        return (new StringType)->addProperties($previous);
    }
}
