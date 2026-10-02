<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/**
 * Picks its series for a document whose routing attributes satisfy every condition (ADR 0079 §10). Conditions:
 * [{"attribute": "ORDER_TYPE", "operator": "EQ"|"IN", "values": ["IMPORT"]}]. Lower priority numbers are tried first.
 *
 * @property int $series_id
 * @property int $priority
 * @property list<array{attribute: string, operator: string, values: list<string>}> $conditions
 * @property string $status
 */
class NumberingSeriesRule extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['conditions' => 'array'];
    }

    /** @param  array<string, string>  $routing */
    public function matches(array $routing): bool
    {
        foreach ($this->conditions as $condition) {
            $actual = $routing[$condition['attribute']] ?? null;
            $ok = match ($condition['operator']) {
                'EQ' => $actual !== null && $actual === ($condition['values'][0] ?? null),
                'IN' => $actual !== null && in_array($actual, $condition['values'], true),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return $this->conditions !== [];
    }
}
