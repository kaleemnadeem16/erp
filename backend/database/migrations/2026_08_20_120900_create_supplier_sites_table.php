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
        Schema::create('supplier_sites', function (Blueprint $table) {
            $table->id();
            // No company_id — scoped through supplier_account_id, see
            // docs/architecture/multi-tenancy.md "Child tables scope through their parent".
            $table->foreignId('supplier_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_site_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['supplier_account_id', 'party_site_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_sites');
    }
};
