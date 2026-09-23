<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_dimension_values', function (Blueprint $table) {
            $table->id();
            // No company_id — scoped through rate_dimension_id, see
            // docs/architecture/multi-tenancy.md "Child tables scope through their parent".
            // No cascadeOnDelete: a dimension with values must have its values removed
            // first, same as Category blocking deletion while it still has children.
            $table->foreignId('rate_dimension_id')->constrained();
            $table->string('code');
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['rate_dimension_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_dimension_values');
    }
};
