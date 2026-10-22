<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Models\User;
use App\Modules\Tax\Models\TaxComponentVersion;
use App\Modules\Tax\Models\TaxGroupVersion;
use App\Modules\Tax\Models\TaxRateVersion;
use App\Modules\Tax\Models\TaxRuleVersion;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\TaxMutation;
use App\Modules\Tax\Support\TaxRefusal;
use App\Modules\Tax\Support\TaxVersionKind;

/** Creates a first version or exact successor. All rows are immutable, including unused drafts. */
final class CreateTaxVersionSuccessor
{
    public function __construct(private readonly TaxMutation $mutation) {}

    /** @param array<string, int|string|null> $definition */
    public function __invoke(User $actor, TaxVersionKind $kind, string $code, ?int $predecessorId, EffectivePeriod $period, array $definition, string $reason, string $operationKey, int $expectedGeneration): int
    {
        TaxAccess::requireConfiguration($actor);
        $allowed = match ($kind) {
            TaxVersionKind::Component => ['component_id', 'name', 'role'],
            TaxVersionKind::Rate => ['component_id', 'percentage', 'amount', 'currency_id'],
            TaxVersionKind::Rule => ['component_id', 'component_version_id', 'rate_version_id', 'method', 'base', 'dependency_stage', 'unit_basis', 'required_mode', 'nature', 'classification', 'jurisdiction'],
            TaxVersionKind::Group => ['required_mode', 'currency_id'],
        };
        if (array_diff(array_keys($definition), $allowed) !== []) {
            throw TaxRefusal::because('TAX_FIELDS_INVALID', 'Unexpected version fields.');
        }
        $model = match ($kind) {
            TaxVersionKind::Component => TaxComponentVersion::class,
            TaxVersionKind::Rate => TaxRateVersion::class,
            TaxVersionKind::Rule => TaxRuleVersion::class,
            TaxVersionKind::Group => TaxGroupVersion::class,
        };
        ksort($definition);
        $intent = ['operation' => 'create_version', 'actor' => $actor->id, 'kind' => $kind->value, 'code' => $code, 'predecessor' => $predecessorId, 'from' => $period->from, 'to' => $period->endExclusive, 'definition' => $definition, 'reason' => $reason];
        $outcome = $this->mutation->run($operationKey, $expectedGeneration, $intent, function () use ($actor, $model, $code, $predecessorId, $period, $definition, $reason): array {
            $predecessor = $predecessorId === null ? null : $model::query()->findOrFail($predecessorId);
            $row = $model::create($definition + [
                'code' => $code, 'version_no' => $predecessor === null ? 1 : (int) $predecessor->getAttribute('version_no') + 1,
                'predecessor_id' => $predecessorId, 'effective_from' => $period->from, 'effective_to' => $period->endExclusive,
                'created_by' => $actor->id, 'reason' => $reason,
            ]);

            return ['version_id' => (int) $row->getKey()];
        });

        return $outcome['version_id'];
    }
}
