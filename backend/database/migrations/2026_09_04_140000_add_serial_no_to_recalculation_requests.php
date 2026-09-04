<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SPECIFIC-item request needs to record which serial the proposed receipt
 * was for — DecideRecalculationApproval re-derives it from the stored
 * request to re-run LayerCounterfactualReplayer at revalidation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recalculation_requests', function (Blueprint $table) {
            $table->string('serial_no')->nullable()->after('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('recalculation_requests', function (Blueprint $table) {
            $table->dropColumn('serial_no');
        });
    }
};
