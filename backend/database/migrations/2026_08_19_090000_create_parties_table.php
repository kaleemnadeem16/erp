<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('code');
            $table->string('name');
            $table->string('registration_no')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('website')->nullable();
            // Nullable, defaulting to '{}' — see App\Support\Concerns\HasCustomFields.
            $table->jsonb('custom_fields')->nullable()->default('{}');
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
