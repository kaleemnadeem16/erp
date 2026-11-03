<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Raw writers also have a finite wait, including callers outside Laravel. No grant or activation. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER FUNCTION company_access_security_writer_barrier() SET lock_timeout = '1s'");
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE company_access_generations IN ACCESS EXCLUSIVE MODE');
        if (DB::table('company_access_generations')->where('generation', '<>', 0)->exists()) {
            throw new RuntimeException('Access generations are in use; removing the bounded barrier wait requires a separately approved backout.');
        }
        DB::statement('ALTER FUNCTION company_access_security_writer_barrier() RESET lock_timeout');
    }
};
