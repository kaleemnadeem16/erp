<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

/**
 * The strict header that carries the material/subcontractor classification INSIDE the customer-evidenced agreed text (P1 review finding). A completion basis that states a
 * scope is recorded from dedicated customer-agreement evidence whose factual description IS the agreed text, so the classification is whatever that record says — the
 * stored scope columns are DERIVED from this header, never chosen by the operator.
 *
 * Fixed grammar, nothing is interpreted: exactly
 *     MATERIALS: <EXCLUDED|REQUIRED|UNSTATED>\nSUBCONTRACTORS: <EXCLUDED|REQUIRED|UNSTATED>
 * as the first two lines (LF only), optionally followed by "\n" and free text. Free text is never read; a further `MATERIALS:`/`SUBCONTRACTORS:` line in it makes the text
 * ambiguous and is refused. The database guard enforces the same grammar (service_completion_basis_header_scopes), so raw SQL cannot bypass it.
 */
final class ScopeHeader
{
    public const PATTERN = '/\AMATERIALS: (EXCLUDED|REQUIRED|UNSTATED)\nSUBCONTRACTORS: (EXCLUDED|REQUIRED|UNSTATED)(\n[\s\S]*)?\z/';

    /** @return array{materials:string,subcontract:string}|null null when the header is missing, malformed, reordered or ambiguous */
    public static function parse(string $text): ?array
    {
        if (preg_match(self::PATTERN, $text, $m) !== 1) {
            return null;
        }
        if (isset($m[3]) && preg_match('/\n(MATERIALS|SUBCONTRACTORS):/', $m[3]) === 1) {
            return null;
        }

        return ['materials' => $m[1], 'subcontract' => $m[2]];
    }

    public static function compose(string $materials, string $subcontract, string $body = ''): string
    {
        return "MATERIALS: {$materials}\nSUBCONTRACTORS: {$subcontract}".($body === '' ? '' : "\n{$body}");
    }
}
