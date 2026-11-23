<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\MaterialPlan;

use App\Modules\Sales\Support\Quotation\QuotationRefusal;

/**
 * The seller's Material Plan request in its one canonical form (ADR 0190). Parsing is strict — an unknown key, a float where an exact decimal is required, or a malformed list is
 * refused, not ignored — and every list is sorted, so the same plan written in another order is the same request and the same evidence. Quantities travel as text.
 */
final class MaterialPlanInputs
{
    private const KEYS = ['customer_id', 'dimension_value_ids', 'planned_adjustments', 'stock_mix', 'stock_mix_notes', 'inventory_scope', 'selection_strategy'];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalise(array $raw): array
    {
        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw self::bad("[{$key}] is not a field a material plan accepts.", (string) $key);
            }
        }
        $out = [];
        if (array_key_exists('customer_id', $raw) && $raw['customer_id'] !== null) {
            $out['customer_id'] = self::id($raw['customer_id'], 'customer_id');
        }
        $dimensions = array_map(fn ($v): int => self::id($v, 'dimension_value_ids'), self::list($raw['dimension_value_ids'] ?? [], 'dimension_value_ids'));
        sort($dimensions);
        $out['dimension_value_ids'] = array_values(array_unique($dimensions));

        $adjustments = [];
        foreach (self::list($raw['planned_adjustments'] ?? [], 'planned_adjustments') as $a) {
            $a = self::object($a, 'planned_adjustments', ['item_id', 'planned_quantity', 'uom_id', 'reason_id', 'note']);
            $adjustments[] = ['item_id' => self::id($a['item_id'] ?? null, 'planned_adjustments.item_id'), 'planned_quantity' => self::decimal($a['planned_quantity'] ?? null, 'planned_adjustments.planned_quantity'),
                'uom_id' => self::id($a['uom_id'] ?? null, 'planned_adjustments.uom_id'), 'reason_id' => isset($a['reason_id']) ? self::id($a['reason_id'], 'planned_adjustments.reason_id') : null,
                'note' => self::note($a['note'] ?? null, 'planned_adjustments.note')];
        }
        usort($adjustments, fn (array $x, array $y): int => $x['item_id'] <=> $y['item_id']);
        if (count(array_unique(array_column($adjustments, 'item_id'))) !== count($adjustments)) {
            throw self::bad('An item is adjusted once.', 'planned_adjustments');
        }
        $out['planned_adjustments'] = $adjustments;

        $mix = [];
        foreach (self::list($raw['stock_mix'] ?? [], 'stock_mix') as $m) {
            $m = self::object($m, 'stock_mix', ['item_id', 'location_id', 'batch_id', 'quantity', 'uom_id']);
            $mix[] = ['item_id' => self::id($m['item_id'] ?? null, 'stock_mix.item_id'), 'location_id' => self::id($m['location_id'] ?? null, 'stock_mix.location_id'),
                'batch_id' => isset($m['batch_id']) ? self::id($m['batch_id'], 'stock_mix.batch_id') : null, 'quantity' => self::decimal($m['quantity'] ?? null, 'stock_mix.quantity'), 'uom_id' => self::id($m['uom_id'] ?? null, 'stock_mix.uom_id')];
        }
        usort($mix, fn (array $x, array $y): int => [$x['item_id'], $x['location_id'], (int) $x['batch_id']] <=> [$y['item_id'], $y['location_id'], (int) $y['batch_id']]);
        $out['stock_mix'] = $mix;

        $notes = [];
        foreach (self::list($raw['stock_mix_notes'] ?? [], 'stock_mix_notes') as $n) {
            $n = self::object($n, 'stock_mix_notes', ['item_id', 'note']);
            $notes[] = ['item_id' => self::id($n['item_id'] ?? null, 'stock_mix_notes.item_id'), 'note' => self::note($n['note'] ?? null, 'stock_mix_notes.note')];
        }
        usort($notes, fn (array $x, array $y): int => $x['item_id'] <=> $y['item_id']);
        $out['stock_mix_notes'] = $notes;

        if (isset($raw['inventory_scope'])) {
            $scope = self::object($raw['inventory_scope'], 'inventory_scope', ['warehouse_ids', 'location_ids']);
            $ids = fn (string $k): array => array_values(array_unique(array_map(fn ($v): int => self::id($v, "inventory_scope.{$k}"), self::list($scope[$k] ?? [], "inventory_scope.{$k}"))));
            $w = $ids('warehouse_ids');
            $l = $ids('location_ids');
            sort($w);
            sort($l);
            $out['inventory_scope'] = ['warehouse_ids' => $w, 'location_ids' => $l];
        }
        if (isset($raw['selection_strategy'])) {
            $out['selection_strategy'] = is_string($raw['selection_strategy']) && $raw['selection_strategy'] !== '' ? $raw['selection_strategy'] : throw self::bad('A selection strategy is a code.', 'selection_strategy');
        }

        return $out;
    }

    private static function bad(string $why, string $field): \Throwable
    {
        return QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, $why, $field);
    }

    private static function id(mixed $value, string $field): int
    {
        return is_int($value) && $value > 0 ? $value : throw self::bad('An identifier is a positive whole number.', $field);
    }

    private static function decimal(mixed $value, string $field): string
    {
        return (is_string($value) || is_int($value)) && preg_match('/^\d+(\.\d+)?$/', (string) $value) === 1 ? (string) $value : throw self::bad('A quantity is a non-negative exact decimal written as text.', $field);
    }

    private static function note(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && mb_strlen(trim($value)) <= 2000 ? (trim($value) === '' ? null : trim($value)) : throw self::bad('A note is text of at most 2000 characters.', $field);
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $field): array
    {
        return is_array($value) && array_is_list($value) ? $value : throw self::bad('This is a list.', $field);
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $field, array $allowed): array
    {
        if (! is_array($value)) {
            throw self::bad('This is an object of typed fields.', $field);
        }
        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw self::bad("[{$key}] is not accepted here.", "{$field}.{$key}");
            }
        }

        return $value;
    }
}
