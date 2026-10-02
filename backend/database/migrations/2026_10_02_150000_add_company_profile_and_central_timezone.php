<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company profile and company-wide settings (ADR 0081).
 *
 * - `accounting_timezone` becomes `timezone`: it was always the company's own time zone (its only readers are the backdating
 *   floor and document numbering) and it no longer belongs to the Inventory policy. The value is kept; old Inventory audit rows
 *   keep their original key.
 * - Identity and contact columns: a stable short `code` (unique; document numbers use it, never the editable name), a legal
 *   name, country, address, email, phone and website. All optional; the existing `name` stays the display/trading name.
 * - `company_changes`: an append-only record of every change to profile or company-wide settings, with who, when and — for the
 *   settings that need one — why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('accounting_timezone', 'timezone');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->string('code', 10)->nullable();
            $table->string('legal_name')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website')->nullable();
        });
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_code_format_check CHECK (code IS NULL OR code ~ '^[A-Z0-9]{2,10}$')");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_country_code_format_check CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$')");
        // `companies` is the tenant root (it has no company_id), so a plain unique index is the right shape: a code identifies one company.
        DB::statement('CREATE UNIQUE INDEX companies_code_unique ON companies (code) WHERE code IS NOT NULL');

        Schema::create('company_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('section', 20);
            $table->jsonb('old_values');
            $table->jsonb('new_values');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('changed_by');
            $table->timestampTz('changed_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'changed_at']);
        });
        DB::statement("ALTER TABLE company_changes ADD CONSTRAINT company_changes_section_check CHECK (section IN ('GENERAL', 'LOCALIZATION', 'CONTACT'))");
        DB::statement('ALTER TABLE company_changes ADD CONSTRAINT company_changes_actor_company_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_company_change_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'company change records are immutable' USING ERRCODE = '23514';
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER company_changes_immutable
            BEFORE UPDATE OR DELETE ON company_changes
            FOR EACH ROW EXECUTE FUNCTION prevent_company_change_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS prevent_company_change_mutation() CASCADE');
        Schema::dropIfExists('company_changes');
        DB::statement('DROP INDEX IF EXISTS companies_code_unique');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_country_code_format_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_code_format_check');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['code', 'legal_name', 'country_code', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'email', 'phone', 'website']);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->renameColumn('timezone', 'accounting_timezone');
        });
    }
};
