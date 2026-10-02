<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Actions;

use App\Models\User;
use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Models\NumberingSeriesRule;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\NumberingRegistry;

/**
 * Adds a routing rule that sends matching documents to a series (ADR 0079 §10). A rule's conditions are over the routing
 * attributes the document type exposes; they must all hold. Lower priority numbers are tried first.
 */
final class CreateSeriesRule
{
    public function __construct(private readonly NumberingRegistry $registry) {}

    /** @param  list<array{attribute: string, operator: string, values: list<string>}>  $conditions */
    public function __invoke(NumberingSeries $series, int $priority, array $conditions, User $actor): NumberingSeriesRule
    {
        $type = $this->registry->get($series->doc_type);
        if (! $actor->can($type->managePermission())) {
            throw new NumberingException("You are not permitted to change {$type->label()} numbering.", 'actor');
        }
        if ($conditions === []) {
            throw new NumberingException('A rule needs at least one condition.', 'conditions');
        }
        foreach ($conditions as $condition) {
            if (! in_array($condition['attribute'], $type->routingAttributes(), true)) {
                throw new NumberingException("{$condition['attribute']} is not something {$type->label()} documents can be routed by.", 'conditions');
            }
            if (! in_array($condition['operator'], ['EQ', 'IN'], true) || $condition['values'] === [] || ($condition['operator'] === 'EQ' && count($condition['values']) !== 1)) {
                throw new NumberingException('Each condition is EQ with one value, or IN with a list of values.', 'conditions');
            }
        }

        return NumberingSeriesRule::query()->create(['series_id' => $series->id, 'priority' => $priority, 'conditions' => $conditions, 'status' => 'ACTIVE']);
    }
}
