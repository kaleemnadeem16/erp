<?php

declare(strict_types=1);

namespace App\Modules\Imports\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Imports\Models\ImportProfile;
use App\Modules\Imports\Support\ImportPurpose;
use App\Modules\Imports\Support\ImportTargetRegistry;
use Illuminate\Support\Facades\DB;

final class CreateImportProfile
{
    public function __construct(private readonly ImportTargetRegistry $targets) {}

    /**
     * @param  array<string, string>  $mappings  source column => target field
     * @param  array<string, array<string, mixed>>  $transformations  target field => approved transform
     */
    public function __invoke(
        string $name,
        ImportPurpose $purpose,
        string $entityType,
        array $mappings,
        array $transformations = [],
        ?User $approvedBy = null,
    ): ImportProfile {
        $target = $this->targets->get($purpose, $entityType);
        $knownFields = array_keys($target->rules());
        if (trim($name) === '') {
            throw new DomainInvariantException('A profile name is required.', 'name');
        }
        if ($mappings === [] || count(array_unique(array_values($mappings))) !== count($mappings)) {
            throw new DomainInvariantException('A profile requires mappings with unique target fields.', 'mappings');
        }
        foreach ($mappings as $source => $field) {
            if (trim((string) $source) === '' || ! in_array($field, $knownFields, true)) {
                throw new DomainInvariantException('The mapping contains an unknown source or target field.', 'mappings');
            }
        }

        $storedTransformations = [];
        if ($transformations !== []) {
            if ($purpose !== ImportPurpose::InventoryCutover || ! in_array($entityType, ['OPENING_STATE', 'OPEN_PURCHASE_ORDER'], true)) {
                throw new DomainInvariantException('Approved defaults are currently supported only for registered cutover staging targets.', 'transformations');
            }
            if ($approvedBy === null || ! $approvedBy->can('inventory.cutover.approve')) {
                throw new DomainInvariantException('Inventory cutover defaults require an authorised approver.', 'transformations');
            }
            $nonDefaultable = [
                'source_record_id', 'item_code', 'warehouse_code', 'source_valuation_method',
                'source_avco_scope', 'source_pool_identity', 'quantity', 'uom_code',
                'amount', 'base_amount', 'batch_no', 'serial_no',
                'source_purchase_order_id', 'source_line_id', 'supplier_code', 'currency_code',
                'fx_rate', 'fx_rate_date', 'order_date', 'line_number', 'remaining_quantity',
                'purchase_uom_code', 'unit_price',
            ];
            foreach ($transformations as $targetField => $transformation) {
                $type = $transformation['type'] ?? null;
                $reason = trim((string) ($transformation['reason'] ?? ''));
                if (! in_array($targetField, $mappings, true) || $type !== 'DEFAULT_IF_MISSING'
                    || ! array_key_exists('value', $transformation) || ! is_scalar($transformation['value'])
                    || $reason === '' || in_array($targetField, $nonDefaultable, true)) {
                    throw new DomainInvariantException('The transformation is unsupported or attempts to default a critical inventory fact.', 'transformations');
                }
                $storedTransformations[$targetField] = [
                    'type' => $type,
                    'value' => $transformation['value'],
                    'reason' => $reason,
                    'approved_by' => $approvedBy->id,
                    'approved_at' => now()->toIso8601String(),
                ];
            }
        }

        return DB::transaction(function () use ($name, $target, $mappings, $storedTransformations): ImportProfile {
            $profile = ImportProfile::query()->create(['name' => trim($name), 'entity_type' => $target->entityType()]);
            foreach ($mappings as $source => $field) {
                $profile->mappings()->create([
                    'source_column' => trim((string) $source),
                    'target_field' => $field,
                    'transform' => $storedTransformations[$field] ?? null,
                ]);
            }

            return $profile->load('mappings');
        });
    }
}
