<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0105: a Party is an organisation or an individual contracting identity. Every existing Party is an organisation (the only kind the earlier
 * model described), so the column defaults to it and nothing is classified by guesswork.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('kind')->default('ORGANISATION')->after('name');
        });
        DB::statement("ALTER TABLE parties ADD CONSTRAINT parties_kind_check CHECK (kind IN ('ORGANISATION', 'INDIVIDUAL'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE parties DROP CONSTRAINT IF EXISTS parties_kind_check');
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
