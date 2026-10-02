<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The company's legal registration and tax identifiers (ADR 0081): plain typed columns, shown on the Company screen. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('registration_number', 50)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->string('sales_tax_number', 50)->nullable();
        });

        foreach (['registration_number', 'tax_number', 'sales_tax_number'] as $column) {
            DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_{$column}_format_check CHECK ({$column} IS NULL OR {$column} ~ '^[A-Za-z0-9][A-Za-z0-9 ./-]{0,49}$')");
        }

        DB::statement('ALTER TABLE company_changes DROP CONSTRAINT company_changes_section_check');
        DB::statement("ALTER TABLE company_changes ADD CONSTRAINT company_changes_section_check CHECK (section IN ('GENERAL', 'LOCALIZATION', 'REGISTRATION', 'CONTACT'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE company_changes DROP CONSTRAINT company_changes_section_check');
        DB::statement("DELETE FROM company_changes WHERE section = 'REGISTRATION'");
        DB::statement("ALTER TABLE company_changes ADD CONSTRAINT company_changes_section_check CHECK (section IN ('GENERAL', 'LOCALIZATION', 'CONTACT'))");

        foreach (['registration_number', 'tax_number', 'sales_tax_number'] as $column) {
            DB::statement("ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_{$column}_format_check");
        }
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['registration_number', 'tax_number', 'sales_tax_number']);
        });
    }
};
