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
        Schema::create('company_access_generations', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('generation')->default(0);
            $table->bigInteger('organisation_generation')->default(0);
            $table->bigInteger('capability_generation')->default(0);
        });
        DB::statement('ALTER TABLE company_access_generations ADD CONSTRAINT company_access_generations_nonnegative CHECK (generation >= 0 AND organisation_generation >= 0 AND capability_generation >= 0)');
        Schema::table('users', function (Blueprint $table): void {
            $table->bigInteger('authorization_generation')->default(0);
        });
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_authorization_generation_nonnegative CHECK (authorization_generation >= 0)');
        DB::statement('INSERT INTO company_access_generations(company_id) SELECT id FROM companies');
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE company_access_generations, users IN ACCESS EXCLUSIVE MODE');
        if (DB::table('company_access_generations')->where('generation', '<>', 0)->orWhere('organisation_generation', '<>', 0)->orWhere('capability_generation', '<>', 0)->exists()
            || DB::table('users')->where('authorization_generation', '<>', 0)->exists()) {
            throw new RuntimeException('Access generations are in use; rolling back would erase security evidence.');
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('authorization_generation'));
        Schema::dropIfExists('company_access_generations');
    }
};
