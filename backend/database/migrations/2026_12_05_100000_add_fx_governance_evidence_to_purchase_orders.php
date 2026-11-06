<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #90 (audit F3, ADR 0165): a purchase order's FX rate is reconciled with the company's governed exchange rate when it is entered. The governed rate and
 * the date of the row it was read from are kept beside the order's own rate so a reader can see what it was checked against, and a contractually fixed rate that
 * departs from it carries the reason and the person who declared it. All columns are nullable and additive: existing orders, including migrated cutover orders,
 * were never checked this way and stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->decimal('fx_governed_rate', 19, 9)->nullable();
            $table->date('fx_governed_rate_date')->nullable();
            $table->text('fx_override_reason')->nullable();
            $table->foreignId('fx_override_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fx_override_by');
            $table->dropColumn(['fx_governed_rate', 'fx_governed_rate_date', 'fx_override_reason']);
        });
    }
};
