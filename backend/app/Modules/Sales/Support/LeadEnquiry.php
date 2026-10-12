<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

/** The six details of an enquiry that are corrected, never replaced: their names, bounds and the one normalisation every writer uses. */
final class LeadEnquiry
{
    /** @var list<string> */
    public const FIELDS = ['enquirer_name', 'enquirer_organisation', 'enquirer_email', 'enquirer_phone', 'description', 'source'];

    /** Trimmed; empty text is "not given" (null), exactly as an enquiry has always been recorded. */
    public static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
