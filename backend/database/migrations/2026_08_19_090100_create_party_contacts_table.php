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
        Schema::create('party_contacts', function (Blueprint $table) {
            $table->id();
            // No company_id — scoped through party_id, see
            // docs/architecture/multi-tenancy.md "Child tables scope through their parent".
            // cascadeOnDelete: a genuine ownership relation (unlike company_id, which is a
            // scoping reference, never cascaded), so a hard-deleted party takes its contacts
            // with it rather than leaving them orphaned.
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('title')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('party_contacts');
    }
};
