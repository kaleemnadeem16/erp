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
        Schema::table('activity_log', function (Blueprint $table) {
            // The original migration deferred this until a real company-scoped model
            // started logging — see its comment and invariants.md #7. Party crossed that
            // line. Stamped via App\Models\Concerns\LogsActivityWithDefaults::tapActivity(),
            // not BelongsToCompany — see multi-tenancy.md "Polymorphic logs tag via
            // tapActivity". No read-side global scope yet: querying Activity directly still
            // returns every company until the audit-log API is designed.
            $table->foreignId('company_id')->default(1)->after('id')->constrained();
            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['company_id']);
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
