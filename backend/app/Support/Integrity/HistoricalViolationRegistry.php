<?php

declare(strict_types=1);

namespace App\Support\Integrity;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ADR 0180 addendum: the integrity rules that were added to tables which may already hold rows, and how to find the rows that pre-date them.
 *
 * A rule is either a NOT VALID constraint (it guards new writes, and can be validated once the old rows are clean) or an insert guard (a trigger; it never re-judges old rows, so there
 * is nothing to validate). Every entry counts its violating rows with one read-only query and can list their ids; nothing here ever changes, repairs or deletes a row.
 */
final class HistoricalViolationRegistry
{
    private const BRAND_LOGO_RULE = '(t.logo_blob_id IS NULL AND t.logo_sha256 IS NULL AND t.logo_media_type IS NULL AND t.logo_width IS NULL AND t.logo_height IS NULL AND t.logo_size_bytes IS NULL)
        OR (t.logo_blob_id IS NOT NULL AND t.logo_sha256 IS NOT NULL AND t.logo_media_type IS NOT NULL AND t.logo_width IS NOT NULL AND t.logo_height IS NOT NULL AND t.logo_size_bytes IS NOT NULL)';

    /** @return array<string, array{table: string, constraint: ?string, rule: string, source: string}> keyed by a stable rule key; `source` is the "FROM ... WHERE ..." that selects violating rows as alias t */
    public static function rules(): array
    {
        return [
            'quotation_tax_declarations_family_scope_fk' => [
                'table' => 'quotation_draft_tax_declarations',
                'constraint' => 'quotation_tax_declarations_family_scope_fk',
                'rule' => "A declaration's logical scope belongs to its revision's quotation family",
                'source' => 'FROM quotation_draft_tax_declarations t WHERE NOT EXISTS (SELECT 1 FROM quotation_logical_scopes s WHERE s.company_id = t.company_id AND s.family_id = t.family_id AND s.id = t.logical_scope_id)',
            ],
            'company_brand_versions_logo_complete' => [
                'table' => 'company_brand_versions',
                'constraint' => 'company_brand_versions_logo_complete',
                'rule' => 'A logo reference carries every pinned detail together with the blob id',
                'source' => 'FROM company_brand_versions t WHERE NOT ('.self::BRAND_LOGO_RULE.')',
            ],
            'sales_customer_decision_receipt_case' => [
                'table' => 'sales_customer_decision_receipts',
                'constraint' => null,
                'rule' => 'A customer decision receipt names an existing case of its own company (insert guard)',
                'source' => 'FROM sales_customer_decision_receipts t WHERE NOT EXISTS (SELECT 1 FROM customer_decision_cases c WHERE c.company_id = t.company_id AND c.id = t.case_id)',
            ],
            'tax_quote_correction_chain' => [
                'table' => 'tax_quotation_source_corrections',
                'constraint' => null,
                'rule' => 'A tax source correction supersedes a correction of the same fact, exactly one version earlier (insert guard)',
                'source' => 'FROM tax_quotation_source_corrections t JOIN tax_quotation_source_corrections p ON p.company_id = t.company_id AND p.id = t.predecessor_id
                    WHERE p.jurisdiction_fact_id IS DISTINCT FROM t.jurisdiction_fact_id OR p.classification_fact_id IS DISTINCT FROM t.classification_fact_id OR t.version_no <> p.version_no + 1',
            ],
        ];
    }

    /** @return array{table: string, constraint: ?string, rule: string, source: string} */
    public static function rule(string $key): array
    {
        return self::rules()[$key] ?? throw new InvalidArgumentException("Unknown integrity rule '{$key}'.");
    }

    public static function count(string $key): int
    {
        return (int) DB::selectOne('SELECT count(*) AS n '.self::rule($key)['source'])->n;
    }

    /** @return list<int> row ids only, lowest first */
    public static function ids(string $key, int $limit): array
    {
        return array_map(fn (object $r): int => (int) $r->id, DB::select('SELECT t.id '.self::rule($key)['source'].' ORDER BY t.id LIMIT '.max(1, $limit)));
    }

    /** True when the rule is a constraint that exists and has not been validated yet. */
    public static function isNotValidated(string $key): bool
    {
        $name = self::rule($key)['constraint'];

        return $name !== null && DB::table('pg_constraint')->where('conname', $name)->where('convalidated', false)->exists();
    }
}
