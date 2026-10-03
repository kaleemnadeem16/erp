<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_package_operations', function (Blueprint $table): void {
            // Existing immutable checkpoints remain unknown; never infer a historical watermark.
            $table->unsignedBigInteger('stock_move_watermark')->nullable();
            $table->foreign(['company_id', 'stock_move_watermark'])->references(['company_id', 'id'])->on('stock_moves');
        });
        DB::statement('ALTER TABLE inventory_package_operations ADD CHECK (stock_move_watermark IS NULL OR stock_move_watermark > 0)');
    }

    public function down(): void
    {
        Schema::table('inventory_package_operations', function (Blueprint $table): void {
            $table->dropForeign(['company_id', 'stock_move_watermark']);
            $table->dropColumn('stock_move_watermark');
        });
    }
};
