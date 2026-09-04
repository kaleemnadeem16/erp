<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_layer_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('stock_move_id');
            $table->unsignedBigInteger('stock_layer_id');
            $table->decimal('qty_consumed', 19, 4);
            $table->decimal('unit_cost', 19, 4);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'stock_move_id', 'stock_layer_id']);
            $table->index(['company_id', 'stock_layer_id']);
        });

        DB::statement('ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_move_company_fk FOREIGN KEY (company_id, stock_move_id) REFERENCES stock_moves (company_id, id)');
        DB::statement('ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_layer_company_fk FOREIGN KEY (company_id, stock_layer_id) REFERENCES stock_layers (company_id, id)');
        DB::statement('ALTER TABLE stock_layer_consumptions ADD CONSTRAINT slc_qty_check CHECK (qty_consumed > 0 AND unit_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_layer_consumptions');
    }
};
