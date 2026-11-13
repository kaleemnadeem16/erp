<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Queries;

use DateTimeImmutable;
use InvalidArgumentException;

/** Internal query input; no HTTP contract. Dates are inclusive UTC calendar dates. */
final readonly class JobReadFilter
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 25,
        public ?string $search = null,
        public ?string $state = null,
        public ?int $responsibleMemberId = null,
        public ?bool $hasOpenHold = null,
        public ?string $createdFrom = null,
        public ?string $createdUntil = null,
        public string $sort = 'created_at',
        public string $direction = 'desc',
    ) {
        self::pagination($page, $perPage);
        if (($search !== null && mb_strlen($search) > 200)
            || ($state !== null && ! in_array($state, ['RELEASED', 'ACTIVE', 'COMPLETED', 'CLOSED'], true))
            || ($responsibleMemberId !== null && $responsibleMemberId < 1)
            || ! in_array($sort, ['number', 'state', 'created_at', 'released_at', 'started_at'], true)
            || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Unsupported Jobs read filter or sort.');
        }
        foreach ([$createdFrom, $createdUntil] as $date) {
            $parsed = $date === null ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($date !== null && ($parsed === false || $parsed?->format('Y-m-d') !== $date)) {
                throw new InvalidArgumentException('Jobs read dates must be valid YYYY-MM-DD dates.');
            }
        }
        if ($createdFrom !== null && $createdUntil !== null && $createdFrom > $createdUntil) {
            throw new InvalidArgumentException('The creation date range is reversed.');
        }
    }

    public static function pagination(int $page, int $perPage): void
    {
        if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Jobs reads require a positive page and 1–100 rows per page.');
        }
    }
}
