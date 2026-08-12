<?php

namespace Tests\Architecture;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards docs/architecture/invariants.md #2 and #7: every unique constraint on a
 * company-scoped table (one with a company_id column) must include company_id, never
 * a single bare column. Retrofitting this once live data contains legitimate
 * cross-company duplicates cannot be done safely — see
 * docs/architecture/multi-tenancy.md rule 2.
 */
class CompanyScopedUniqueIndexTest extends TestCase
{
    public function test_no_company_scoped_table_has_a_single_column_unique_constraint(): void
    {
        $this->assertSame([], $this->singleColumnUniqueViolations());
    }

    public function test_it_catches_a_deliberately_added_single_column_unique_index(): void
    {
        Schema::create('architecture_test_offenders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('code')->unique();
            $table->timestamps();
        });

        try {
            $violations = $this->singleColumnUniqueViolations();
        } finally {
            Schema::dropIfExists('architecture_test_offenders');
        }

        $this->assertSame([
            [
                'table' => 'architecture_test_offenders',
                'constraint' => 'architecture_test_offenders_code_unique',
                'columns' => ['code'],
            ],
        ], $violations);
    }

    /**
     * @return list<array{table: string, constraint: string, columns: list<string>}>
     */
    private function singleColumnUniqueViolations(): array
    {
        $companyScopedTables = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'company_id')
            ->pluck('table_name')
            ->all();

        if ($companyScopedTables === []) {
            return [];
        }

        $constraints = DB::select(<<<'SQL'
            select tc.table_name, tc.constraint_name, array_agg(kcu.column_name) as columns
            from information_schema.table_constraints tc
            join information_schema.key_column_usage kcu
                on tc.constraint_name = kcu.constraint_name
                and tc.table_schema = kcu.table_schema
            where tc.constraint_type = 'UNIQUE'
                and tc.table_schema = 'public'
            group by tc.table_name, tc.constraint_name
            SQL);

        $violations = [];

        foreach ($constraints as $row) {
            if (! in_array($row->table_name, $companyScopedTables, true)) {
                continue;
            }

            $columns = $this->parsePostgresArray($row->columns);

            if (count($columns) === 1 && $columns[0] !== 'company_id') {
                $violations[] = [
                    'table' => $row->table_name,
                    'constraint' => $row->constraint_name,
                    'columns' => $columns,
                ];
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function parsePostgresArray(string $value): array
    {
        $value = trim($value, '{}');

        return $value === '' ? [] : explode(',', $value);
    }
}
