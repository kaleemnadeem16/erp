<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_rate_dimensions', function (Blueprint $table) {
            $table->id();
            // No company_id — scoped through application_rate_id.
            // Owned entirely by its application rate: deleting the rate removes its
            // own dimension tags with it.
            $table->foreignId('application_rate_id')->constrained()->cascadeOnDelete();
            // Not owned by the value: a value in use by a live rate must be detached
            // from the rate (or the rate deleted) before the value itself can go —
            // silently cascading here would change an existing rate's meaning.
            $table->foreignId('rate_dimension_value_id')->constrained();
            $table->timestamps();

            $table->unique(['application_rate_id', 'rate_dimension_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_rate_dimensions');
    }
};
