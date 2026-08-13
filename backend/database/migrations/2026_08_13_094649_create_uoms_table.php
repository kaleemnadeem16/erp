<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('uoms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('code');
            $table->string('name');
            $table->string('dimension');
            $table->decimal('factor_to_base', 19, 9);
            $table->boolean('is_base')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        // Exactly one base unit per dimension per company — docs/domain/units-of-measure.md.
        // Laravel's schema builder has no fluent partial-index support, so this is raw SQL.
        DB::statement(
            'create unique index uoms_one_base_per_dimension on uoms (company_id, dimension) where is_base'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uoms');
    }
};
