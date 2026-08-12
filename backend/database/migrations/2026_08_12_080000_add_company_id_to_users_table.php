<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->default(1)->after('id')->constrained();

            // The original migration's unique('email') is exactly the anti-pattern
            // docs/architecture/invariants.md #2 forbids once a table is company-scoped —
            // see tests/Architecture/CompanyScopedUniqueIndexTest.php.
            $table->dropUnique('users_email_unique');
            $table->unique(['company_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'email']);
            $table->unique('email');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
