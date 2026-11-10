<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Models\User;
use App\Modules\Sales\Models\QuotationOperationReceipt;

/**
 * Lets a demo seeder be re-run on any later day without resubmitting a changed payload (create-only: a rerun adds nothing).
 *
 * The demo quotation seeders give each scenario fixed request keys, but some payloads carry dates derived from "today" (pricing date, valid-through, delivery date). Sales
 * rightly refuses the same key with different content, so on a later day such a rerun would be refused. Before building a scenario, the seeder asks here whether the first
 * operation of that scenario (its create key) already has a recorded receipt for that actor; if it does, the whole scenario was built by an earlier run (each seeder runs in one
 * transaction, so a recorded first step means a complete scenario) and is skipped. Sales' own idempotency check is not touched or bypassed: nothing is resubmitted.
 */
final class DemoQuotationReplay
{
    /** True when $actor already completed a quotation operation under $requestKey in the current company. */
    public static function done(User $actor, string $requestKey): bool
    {
        return self::receipt($actor, $requestKey) !== null;
    }

    /** The receipt of an earlier run (its `family_id`, `revision_id` and `result` identify what it built), or null. */
    public static function receipt(User $actor, string $requestKey): ?QuotationOperationReceipt
    {
        return QuotationOperationReceipt::query()->where('actor_id', $actor->id)->where('request_key', $requestKey)->first();
    }
}
