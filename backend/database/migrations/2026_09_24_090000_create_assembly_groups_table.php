<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assembly_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('code');
            $table->string('name');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            // assemblies.assembly_group_id needs this for its own composite FK,
            // added in the same slice — see docs/decisions/0049-assembly-group-overhead-scope.md.
            $table->unique(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assembly_groups');
    }
};
