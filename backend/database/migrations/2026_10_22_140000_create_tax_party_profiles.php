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
        Schema::create('tax_party_profile_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('party_id');
            $t->string('jurisdiction', 80);
            $t->text('jurisdiction_evidence');
            $t->text('registration_details')->nullable();
            $t->integer('version_no');
            $t->unsignedBigInteger('predecessor_id')->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'party_id', 'jurisdiction', 'version_no'], 'tax_profile_version_unique');
            $t->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('parties');
            $t->foreign(['company_id', 'predecessor_id'])->references(['company_id', 'id'])->on('tax_party_profile_versions');
            $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE tax_party_profile_versions ADD CONSTRAINT tax_profile_shape CHECK (
            length(trim(jurisdiction)) > 0 AND length(trim(jurisdiction_evidence)) > 0 AND length(trim(reason)) > 0
            AND (registration_details IS NULL OR length(trim(registration_details)) > 0)
            AND (effective_to IS NULL OR effective_to > effective_from)
            AND ((version_no = 1 AND predecessor_id IS NULL) OR (version_no > 1 AND predecessor_id IS NOT NULL)))');
        DB::statement("ALTER TABLE tax_party_profile_versions ADD CONSTRAINT tax_profile_period_exclusion EXCLUDE USING gist
            (company_id WITH =, party_id WITH =, jurisdiction WITH =, daterange(effective_from, effective_to, '[)') WITH &&)");
        Schema::create('tax_party_component_evidence', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('profile_version_id');
            $t->unsignedBigInteger('component_id');
            $t->string('disposition', 20);
            $t->text('grounds')->nullable();
            $t->text('evidence_reference')->nullable();
            $t->text('issuer')->nullable();
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'profile_version_id', 'component_id'], 'tax_profile_component_unique');
            $t->index(['company_id', 'component_id']);
            $t->foreign(['company_id', 'profile_version_id'])->references(['company_id', 'id'])->on('tax_party_profile_versions');
            $t->foreign(['company_id', 'component_id'])->references(['company_id', 'id'])->on('tax_components');
            $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE tax_party_component_evidence ADD CONSTRAINT tax_profile_component_shape CHECK (
            disposition IN ('TAXABLE', 'ZERO_RATED', 'EXEMPT', 'NOT_APPLICABLE', 'UNKNOWN')
            AND length(trim(reason)) > 0
            AND (grounds IS NULL OR length(trim(grounds)) > 0)
            AND (evidence_reference IS NULL OR length(trim(evidence_reference)) > 0)
            AND (issuer IS NULL OR length(trim(issuer)) > 0)
            AND (expires_on IS NULL OR (issued_on IS NOT NULL AND expires_on > issued_on))
            AND (disposition = 'UNKNOWN' OR grounds IS NOT NULL)
            AND (disposition <> 'EXEMPT' OR (evidence_reference IS NOT NULL AND issuer IS NOT NULL AND issued_on IS NOT NULL)))");
        Schema::create('tax_party_profile_activations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('profile_version_id');
            $t->unsignedBigInteger('activated_by');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'profile_version_id']);
            $t->foreign(['company_id', 'profile_version_id'])->references(['company_id', 'id'])->on('tax_party_profile_versions');
            $t->foreign(['company_id', 'activated_by'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE tax_party_profile_activations ADD CONSTRAINT tax_profile_activation_reason CHECK (length(trim(reason)) > 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tax_fr2b_write_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous record; profile record;
BEGIN
 INSERT INTO tax_generations(company_id, generation) VALUES (NEW.company_id, 0) ON CONFLICT DO NOTHING;
 UPDATE tax_generations SET generation = generation + 1 WHERE company_id = NEW.company_id;
 IF TG_TABLE_NAME = 'tax_party_profile_versions' THEN
   IF NEW.predecessor_id IS NOT NULL THEN
     SELECT * INTO previous FROM tax_party_profile_versions WHERE company_id = NEW.company_id AND id = NEW.predecessor_id;
     IF previous.id IS NULL OR previous.party_id <> NEW.party_id OR previous.jurisdiction <> NEW.jurisdiction
        OR previous.version_no + 1 <> NEW.version_no OR previous.effective_to IS NULL OR previous.effective_to <> NEW.effective_from THEN
       RAISE EXCEPTION 'Invalid Tax profile successor' USING ERRCODE = '23514';
     END IF;
   END IF;
 ELSE
   SELECT * INTO profile FROM tax_party_profile_versions WHERE company_id = NEW.company_id AND id = NEW.profile_version_id;
   IF profile.id IS NULL THEN
     RAISE EXCEPTION 'Invalid Tax profile reference' USING ERRCODE = '23514';
   END IF;
   IF TG_TABLE_NAME = 'tax_party_component_evidence' THEN
     IF EXISTS (SELECT 1 FROM tax_party_profile_activations WHERE company_id = NEW.company_id AND profile_version_id = NEW.profile_version_id) THEN
       RAISE EXCEPTION 'Activated Tax profile is immutable' USING ERRCODE = '23514';
     END IF;
     IF (NEW.issued_on IS NOT NULL AND NEW.issued_on > profile.effective_from)
        OR (NEW.expires_on IS NOT NULL AND (profile.effective_to IS NULL OR NEW.expires_on < profile.effective_to)) THEN
       RAISE EXCEPTION 'Evidence must cover the Tax profile period' USING ERRCODE = '23514';
     END IF;
   END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        foreach ($this->tables() as $table) {
            DB::statement("CREATE TRIGGER tax_profile_write BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION tax_fr2b_write_guard()");
            DB::statement("CREATE TRIGGER tax_profile_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION tax_fr2a_append_only_guard()");
            DB::statement("CREATE TRIGGER tax_profile_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION tax_fr2a_append_only_guard()");
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Tax profile evidence exists; rollback would erase protected history.');
            }
        }
        foreach (array_reverse($this->tables()) as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS tax_fr2b_write_guard()');
    }

    /** @return list<string> */
    private function tables(): array
    {
        return ['tax_party_profile_versions', 'tax_party_component_evidence', 'tax_party_profile_activations'];
    }
};
