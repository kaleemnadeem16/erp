<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

/**
 * The strict SCOPE DECLARATION that states the material/subcontractor classification of ONE exact customer-agreed deliverable (review P1, #122). Nothing is interpreted.
 *
 * A declaration is the WHOLE agreed text and nothing else — exactly four LF-separated lines:
 *     MATERIALS: <EXCLUDED|REQUIRED|UNSTATED>
 *     SUBCONTRACTORS: <EXCLUDED|REQUIRED|UNSTATED>
 *     LINE: <issued line id>
 *     DELIVERABLE_SHA256: <sha256 (lowercase hex) of the issued line's description, trimmed of " \t\n\r\v">
 * It is recorded from dedicated customer-agreement evidence whose factual description IS this text, so the customer-evidenced record states the scope for THAT exact
 * deliverable text, and there is no free-text field in which the classification could be contradicted. The stored scope columns are DERIVED from it, never chosen.
 *
 * A text that starts like a declaration (a keyword line first) but is not exactly this shape is AMBIGUOUS and refused; a conditions-only text may not contain any keyword line
 * anywhere. The issued line description itself is customer-agreed text the system cannot interpret: the declaration binds the scope to its exact digest and nothing more.
 * The database guard (service_completion_basis_scope_declaration) mirrors this grammar, so raw SQL cannot bypass it.
 */
final class ScopeHeader
{
    public const DECLARATION = '/\AMATERIALS: (EXCLUDED|REQUIRED|UNSTATED)\nSUBCONTRACTORS: (EXCLUDED|REQUIRED|UNSTATED)\nLINE: ([1-9][0-9]*)\nDELIVERABLE_SHA256: ([0-9a-f]{64})\z/';

    private const STARTS_AS_DECLARATION = '/\A(MATERIALS|SUBCONTRACTORS|LINE|DELIVERABLE_SHA256):/';

    private const ANY_KEYWORD_LINE = '/(^|\n)(MATERIALS|SUBCONTRACTORS|LINE|DELIVERABLE_SHA256):/';

    /** The characters trimmed from a description before hashing: identical in PHP and in the SQL guard. */
    public const TRIM = " \t\n\r\x0B";

    /** True when the text begins with a keyword line, i.e. it claims to be a declaration. */
    public static function claimsDeclaration(string $text): bool
    {
        return preg_match(self::STARTS_AS_DECLARATION, $text) === 1;
    }

    /** True when ANY line of the text starts with a keyword — never allowed in conditions-only text. */
    public static function hasKeywordLine(string $text): bool
    {
        return preg_match(self::ANY_KEYWORD_LINE, $text) === 1;
    }

    /** @return array{materials:string,subcontract:string,line:int,digest:string}|null null unless the text is EXACTLY the four-line declaration */
    public static function parse(string $text): ?array
    {
        if (preg_match(self::DECLARATION, $text, $m) !== 1) {
            return null;
        }

        return ['materials' => $m[1], 'subcontract' => $m[2], 'line' => (int) $m[3], 'digest' => $m[4]];
    }

    public static function digest(string $description): string
    {
        return hash('sha256', trim($description, self::TRIM));
    }

    public static function compose(string $materials, string $subcontract, int $lineId, string $description): string
    {
        return "MATERIALS: {$materials}\nSUBCONTRACTORS: {$subcontract}\nLINE: {$lineId}\nDELIVERABLE_SHA256: ".self::digest($description);
    }
}
