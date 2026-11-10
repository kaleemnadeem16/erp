<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0155 follow-up: the append-only bootstrap receipt names the member who initiated the bootstrap, so the provenance says who did it and not only what was done. Additive: one nullable
 * column and a composite company foreign key to users. Receipts written before this change keep NULL (the initiator was not recorded then and is never guessed). The receipt stays
 * append-only; adding a column does not touch a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE demo_access_operator_bootstraps ADD COLUMN initiated_by_user_id bigint NULL');
        DB::statement('ALTER TABLE demo_access_operator_bootstraps ADD CONSTRAINT demo_access_operator_bootstraps_initiator_fk FOREIGN KEY (company_id, initiated_by_user_id) REFERENCES users (company_id, id)');
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE demo_access_operator_bootstraps IN ACCESS EXCLUSIVE MODE');
        if (DB::table('demo_access_operator_bootstraps')->whereNotNull('initiated_by_user_id')->exists()) {
            throw new RuntimeException('A bootstrap receipt records its initiating actor; rolling back would erase that evidence.');
        }
        DB::statement('ALTER TABLE demo_access_operator_bootstraps DROP CONSTRAINT IF EXISTS demo_access_operator_bootstraps_initiator_fk');
        DB::statement('ALTER TABLE demo_access_operator_bootstraps DROP COLUMN IF EXISTS initiated_by_user_id');
    }
};
