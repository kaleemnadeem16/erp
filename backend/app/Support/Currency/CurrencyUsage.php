<?php

declare(strict_types=1);

namespace App\Support\Currency;

use Illuminate\Support\Facades\DB;

/**
 * Which currencies are in use (ADR 0143): any row in any table with a foreign key to `currencies` marks its currency used. The tables are discovered from
 * the database's own foreign-key metadata, so a module added later cannot forget to register a probe, and rows of every company count (a currency is global).
 */
final class CurrencyUsage
{
    /**
     * @return array<int, list<string>> currency id => the `table.column` places that reference it
     */
    public function usedBy(): array
    {
        $references = DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS table_name, a.attname AS column_name
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND c.confrelid = 'currencies'::regclass AND array_length(c.conkey, 1) = 1
            SQL);

        $used = [];
        foreach ($references as $reference) {
            $table = str_replace('"', '', $reference->table_name);
            $column = $reference->column_name;
            foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $currencyId) {
                $used[(int) $currencyId][] = "{$table}.{$column}";
            }
        }

        return $used;
    }
}
