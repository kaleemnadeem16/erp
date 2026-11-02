<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Family changes participate in the same barrier as security writers, including raw SQL. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TRIGGER company_access_security_barrier BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON quotation_families FOR EACH STATEMENT EXECUTE FUNCTION company_access_security_writer_barrier()');
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE company_access_generations IN ACCESS EXCLUSIVE MODE');
        if (DB::table('company_access_generations')->where('generation', '<>', 0)->exists()) {
            throw new RuntimeException('Access generations are in use; disabling the family barrier requires a separately approved backout.');
        }
        DB::statement('DROP TRIGGER IF EXISTS company_access_security_barrier ON quotation_families');
    }
};
