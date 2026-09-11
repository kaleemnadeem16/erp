<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A free-text field for the order as a whole — distinct from a line's own
 * `description` (what was bought) and from `cancellation_reason` (a fixed,
 * action-specific audit fact). Buyer/requester remarks that don't belong to
 * any one line: delivery instructions, a reference PO number from the
 * requester's own system, a note for whoever issues the order next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('expected_delivery_date');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
