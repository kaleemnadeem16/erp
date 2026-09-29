<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether stock in a location counts as usable for Estimating's availability. NULL (the default for
 * every existing and new location) follows the location's type — `LocationType::holdsUsableStock()`,
 * the original fixed rule — so nothing changes until a company deliberately marks a location.
 * TRUE/FALSE overrides the type either way. Only STRUCTURED-mode companies can set it, and every
 * change is a governed inventory policy change with a reason (`inventory_policy_changes`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('counts_as_usable')->nullable()->after('allows_stock');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('counts_as_usable');
        });
    }
};
