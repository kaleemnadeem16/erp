<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0142: an issued purchase-order line keeps the Item stock increment in force when it was issued, so the over-receipt ceiling no longer reads the Item's
 * live setting. The value is written when the order is issued (while the lines are still editable); it is null while the order is a draft and null for an
 * item with no increment.
 *
 * Baseline for orders issued before this migration: no earlier value was recorded, so the only truthful pin is the increment the Item has today. This is a
 * labelled baseline, not a reconstruction — it keeps the ceiling exactly as it behaved the moment before this change. The line-immutability triggers are
 * switched off for that one statement only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->decimal('stock_increment', 19, 9)->nullable();
        });

        DB::statement('ALTER TABLE purchase_order_lines DISABLE TRIGGER USER');
        try {
            DB::statement(<<<'SQL'
                UPDATE purchase_order_lines l SET stock_increment = i.quantity_precision
                FROM purchase_orders po, items i
                WHERE po.id = l.purchase_order_id AND i.id = l.item_id AND po.status <> 'DRAFT' AND i.quantity_precision IS NOT NULL
                SQL);
        } finally {
            DB::statement('ALTER TABLE purchase_order_lines ENABLE TRIGGER USER');
        }
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropColumn('stock_increment');
        });
    }
};
