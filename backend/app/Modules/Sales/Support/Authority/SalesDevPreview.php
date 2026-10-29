<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Authority;

use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Support\Facades\Log;

/**
 * The one way the staged quotation duties become usable on a developer's machine, and only there. The shipped catalogue is untouched (the duties stay STAGED in
 * `SalesDutyCatalogue`, and `PermissionVocabulary::all()` still reads the shipped manifest); this only chooses which manifest the container hands out. It needs BOTH
 * `SALES_DEV_QUOTATION_DUTIES=true` and `APP_ENV=local` — any other environment (production, staging, testing) ignores the flag, logs a critical warning and keeps
 * the shipped STAGED defaults, so a stray variable can never switch authority on. Turning the duties ACTIVE grants nobody anything: a person still needs an explicit
 * grant (`sales:dev-grant-quotation`), and the duties stay outside every automatic grant.
 */
final class SalesDevPreview
{
    /** @var list<string> */
    public const DUTIES = [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE];

    /**
     * The quotation document feature, so the dev admin can generate, download and record delivery of an issued quotation's PDF on a developer's machine. Never the
     * sensitive operator duty `sales.document.repair`, and never anything that issues, assigns or sends.
     *
     * @var list<string>
     */
    public const DOCUMENT_DUTIES = [
        PermissionVocabulary::DOCUMENT_GENERATE, PermissionVocabulary::DOCUMENT_VIEW, PermissionVocabulary::DOCUMENT_DOWNLOAD,
        PermissionVocabulary::DELIVERY_VIEW, PermissionVocabulary::DELIVERY_RECORD,
    ];

    /** @return list<string> every duty the local preview turns on */
    public static function all(): array
    {
        return [...self::DUTIES, ...self::DOCUMENT_DUTIES];
    }

    public static function requested(): bool
    {
        return (bool) config('sales.dev_preview.quotation_duties');
    }

    /** True only when the flag is set and this is a local environment. */
    public static function enabled(): bool
    {
        return self::requested() && app()->environment('local');
    }

    public static function manifest(): SalesDutyManifest
    {
        $shipped = SalesDutyManifest::shipped();
        if (! self::requested()) {
            return $shipped;
        }
        if (! app()->environment('local')) {
            Log::critical('SALES_DEV_QUOTATION_DUTIES is set outside a local environment and was ignored; the quotation duties stay STAGED.', ['environment' => app()->environment()]);

            return $shipped;
        }

        return $shipped->withActivated(self::all());
    }
}
