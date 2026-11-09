<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0155 follow-up: the create-once receipt is per designated company and operator identity (the e-mail), not per user row, so renaming and re-creating the user cannot earn a
 * second bootstrap. The identity is compared case-insensitively. Additive: one unique index, no data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX demo_access_operator_bootstraps_identity_once ON demo_access_operator_bootstraps (company_id, lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS demo_access_operator_bootstraps_identity_once');
    }
};
