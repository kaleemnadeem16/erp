<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Modules\Sales\Models\QuotationDisposition;
use App\Modules\Sales\Models\QuotationRevision;
use Illuminate\Support\Facades\DB;

/**
 * Whether a revision can still be accepted, derived — never stored — from the revision, its disposition and the database's clock: an ISSUED revision is AVAILABLE until the
 * instant it expired, unless the seller withdrew or replaced it. No scheduler is needed: expiry blocks by comparison. A DRAFT or cancelled draft is not an offer at all.
 */
final class QuotationAvailability
{
    public const DRAFT = 'DRAFT';

    public const CANCELLED_DRAFT = 'CANCELLED_DRAFT';

    public const AVAILABLE = 'AVAILABLE';

    public const EXPIRED = 'EXPIRED';

    public const WITHDRAWN = 'WITHDRAWN';

    public const REPLACED = 'REPLACED';

    public static function of(QuotationRevision $revision): string
    {
        if (! $revision->isIssued()) {
            return $revision->isDraft() ? self::DRAFT : self::CANCELLED_DRAFT;
        }
        $disposition = QuotationDisposition::query()->where('revision_id', $revision->id)->first();
        if ($disposition !== null) {
            return $disposition->kind === QuotationDisposition::REPLACED ? self::REPLACED : self::WITHDRAWN;
        }
        $expired = (bool) DB::selectOne('select ?::timestamptz <= now() as expired', [$revision->expires_at?->toIso8601String()])->expired;

        return $expired ? self::EXPIRED : self::AVAILABLE;
    }

    public static function isOffer(QuotationRevision $revision): bool
    {
        return self::of($revision) === self::AVAILABLE;
    }
}
