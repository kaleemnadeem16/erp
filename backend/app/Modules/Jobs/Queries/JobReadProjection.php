<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use stdClass;

/**
 * Materialised internal DTO, never a model or a deferred builder. Explicit allowlists at every
 * depth exclude hashes, commercial values, consent, source snapshots and request receipts.
 * This pure mapper is internal to ReadJobs; it loads no data and confers no authority.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class JobReadProjection implements Arrayable, JsonSerializable
{
    /** @param array<string, mixed> $fields */
    private function __construct(private array $fields) {}

    public static function summary(stdClass $row): self
    {
        return new self([
            'id' => (int) $row->id,
            'number' => (string) $row->number,
            'state' => (string) $row->state,
            'description' => $row->description,
            'responsible_member' => $row->responsible_id === null ? null : ['id' => (int) $row->responsible_id, 'name' => $row->responsible_name],
            'has_open_hold' => (bool) $row->has_open_hold,
            'created_at' => self::time($row->created_at),
            'released_at' => self::time($row->released_at),
            'started_at' => self::time($row->started_at),
        ]);
    }

    /** @param array{family_id:int,revision_id:int}|null $origin */
    public static function detail(stdClass $row, ?array $origin): self
    {
        $fields = self::summary($row)->toArray();
        $baseline = self::json($row->baseline);
        $assignment = self::json($row->assignment);
        $activity = self::json($row->activity);
        $fields['baseline'] = $baseline === null ? null : [
            'version' => (int) $baseline['version'], 'scope_description' => $baseline['scope_description'],
            'deliverable' => $baseline['deliverable'], 'agreed_conditions' => $baseline['agreed_conditions'],
            'recorded_at' => self::time($baseline['recorded_at']),
        ];
        $fields['assignment'] = $assignment === null ? null : [
            'member_id' => (int) $assignment['member_id'], 'member_name' => $assignment['member_name'],
            'intended_from' => self::time($assignment['intended_from']), 'intended_until' => self::time($assignment['intended_until']),
            'recorded_at' => self::time($assignment['recorded_at']),
        ];
        $fields['open_holds'] = array_map(static fn (array $hold): array => [
            'id' => (int) $hold['id'], 'reason' => $hold['reason'],
            'effective_from' => self::time($hold['effective_from']), 'recorded_at' => self::time($hold['recorded_at']),
        ], self::json($row->open_holds) ?? []);
        $fields['default_activity'] = $activity === null ? null : ['id' => (int) $activity['id'], 'description' => $activity['description']];
        if ($origin !== null) {
            $fields['sales_origin'] = $origin;
        }

        return new self($fields);
    }

    public static function event(stdClass $row): self
    {
        return new self([
            'id' => (int) $row->id, 'sequence' => (int) $row->sequence, 'operation' => $row->operation,
            'from_state' => $row->from_state, 'to_state' => $row->to_state,
            'recorded_at' => self::time($row->recorded_at), 'claimed_occurred_at' => self::time($row->occurred_at),
            'occurrence_precision' => $row->occurrence_precision,
            'actor' => ['id' => (int) $row->actor_id, 'name' => $row->actor_name], 'reason' => $row->reason,
            'responsible_member_id' => $row->responsible_user_id === null ? null : (int) $row->responsible_user_id,
            'hold_id' => $row->hold_id === null ? null : (int) $row->hold_id,
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->fields;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->fields;
    }

    private static function time(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->toISOString();
    }

    /** @return array<array-key, mixed>|null */
    private static function json(?string $value): ?array
    {
        return $value === null ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
