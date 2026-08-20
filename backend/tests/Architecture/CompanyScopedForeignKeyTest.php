<?php

namespace Tests\Architecture;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards tenant ownership structurally: when both sides of a foreign key carry
 * company_id, that ownership column must participate in the relationship. A
 * plain business-id FK proves existence but permits a cross-company reference.
 */
class CompanyScopedForeignKeyTest extends TestCase
{
    public function test_foreign_keys_between_company_scoped_tables_include_company_id(): void
    {
        $this->assertSame([], $this->tenantForeignKeyViolations());
    }

    public function test_it_catches_a_deliberately_bare_company_scoped_foreign_key(): void
    {
        Schema::create('architecture_fk_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
        });
        Schema::create('architecture_fk_offenders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('target_id')->constrained('architecture_fk_targets');
        });

        try {
            $violations = $this->tenantForeignKeyViolations();
        } finally {
            Schema::dropIfExists('architecture_fk_offenders');
            Schema::dropIfExists('architecture_fk_targets');
        }

        $this->assertSame([
            [
                'source_table' => 'architecture_fk_offenders',
                'constraint' => 'architecture_fk_offenders_target_id_foreign',
                'source_columns' => ['target_id'],
                'target_table' => 'architecture_fk_targets',
                'target_columns' => ['id'],
            ],
        ], $violations);
    }

    /**
     * @return list<array{source_table: string, constraint: string, source_columns: list<string>, target_table: string, target_columns: list<string>}>
     */
    private function tenantForeignKeyViolations(): array
    {
        $companyScopedTables = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'company_id')
            ->pluck('table_name')
            ->all();

        $foreignKeys = DB::select(<<<'SQL'
            select
                source.relname as source_table,
                con.conname as constraint_name,
                target.relname as target_table,
                array(
                    select attribute.attname
                    from unnest(con.conkey) with ordinality as key(attnum, position)
                    join pg_attribute attribute
                        on attribute.attrelid = con.conrelid
                        and attribute.attnum = key.attnum
                    order by key.position
                )::text as source_columns,
                array(
                    select attribute.attname
                    from unnest(con.confkey) with ordinality as key(attnum, position)
                    join pg_attribute attribute
                        on attribute.attrelid = con.confrelid
                        and attribute.attnum = key.attnum
                    order by key.position
                )::text as target_columns
            from pg_constraint con
            join pg_class source on source.oid = con.conrelid
            join pg_class target on target.oid = con.confrelid
            join pg_namespace namespace on namespace.oid = source.relnamespace
            where con.contype = 'f'
                and namespace.nspname = 'public'
            order by source.relname, con.conname
            SQL);

        $violations = [];

        foreach ($foreignKeys as $foreignKey) {
            if (! in_array($foreignKey->source_table, $companyScopedTables, true)
                || ! in_array($foreignKey->target_table, $companyScopedTables, true)) {
                continue;
            }

            $sourceColumns = $this->parsePostgresArray($foreignKey->source_columns);
            $targetColumns = $this->parsePostgresArray($foreignKey->target_columns);
            $companyPosition = array_search('company_id', $sourceColumns, true);

            if ($companyPosition !== false && ($targetColumns[$companyPosition] ?? null) === 'company_id') {
                continue;
            }

            $violations[] = [
                'source_table' => $foreignKey->source_table,
                'constraint' => $foreignKey->constraint_name,
                'source_columns' => $sourceColumns,
                'target_table' => $foreignKey->target_table,
                'target_columns' => $targetColumns,
            ];
        }

        return $violations;
    }

    /** @return list<string> */
    private function parsePostgresArray(string $value): array
    {
        $value = trim($value, '{}');

        return $value === '' ? [] : explode(',', $value);
    }
}
