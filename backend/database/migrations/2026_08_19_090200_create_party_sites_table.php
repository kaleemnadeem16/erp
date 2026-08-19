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
        Schema::create('party_sites', function (Blueprint $table) {
            $table->id();
            // No company_id — scoped through party_id, see
            // docs/architecture/multi-tenancy.md "Child tables scope through their parent".
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            // ISO 3166-1 alpha-2, uppercase. No countries table exists yet — see
            // docs/domain/parties.md "Country as a code, not a reference".
            $table->char('country_code', 2)->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['party_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('party_sites');
    }
};
