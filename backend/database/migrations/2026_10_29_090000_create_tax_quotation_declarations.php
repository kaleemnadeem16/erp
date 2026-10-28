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
        Schema::create('tax_quotation_declaration_versions', function (Blueprint $t): void {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->string('code', 80);
            $t->integer('version_no'); $t->unsignedBigInteger('predecessor_id')->nullable();
            $t->string('jurisdiction', 80); $t->date('effective_from'); $t->date('effective_to')->nullable();
            $t->string('tax_mode', 20); $t->string('calculation_version', 20);
            $t->string('settlement_basis', 30); $t->string('rounding_mode', 20);
            $t->unsignedBigInteger('created_by'); $t->text('reason'); $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id'], 'tax_qd_tenant_id');
            $t->unique(['company_id', 'code', 'version_no'], 'tax_qd_series');
            $t->foreign(['company_id', 'predecessor_id'], 'tax_qd_predecessor')->references(['company_id', 'id'])->on('tax_quotation_declaration_versions');
            $t->foreign(['company_id', 'created_by'], 'tax_qd_creator')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE tax_quotation_declaration_versions ADD CONSTRAINT tax_qd_shape CHECK (
            code ~ '^[a-z0-9][a-z0-9_.-]*$' AND length(trim(jurisdiction)) > 0 AND length(trim(reason)) > 0
            AND (effective_to IS NULL OR effective_to > effective_from)
            AND ((version_no = 1 AND predecessor_id IS NULL) OR (version_no > 1 AND predecessor_id IS NOT NULL))
            AND tax_mode = 'BASIC' AND calculation_version = 'FR3.1' AND settlement_basis = 'LINE_COMPONENT'
            AND rounding_mode IN ('HALF_UP','HALF_EVEN','HALF_DOWN','UP','DOWN'))");
        // Different codes cannot compete for the same jurisdiction/date, even while drafts.
        DB::statement("ALTER TABLE tax_quotation_declaration_versions ADD CONSTRAINT tax_qd_period EXCLUDE USING gist
            (company_id WITH =, jurisdiction WITH =, daterange(effective_from,effective_to,'[)') WITH &&)");
        Schema::create('tax_quotation_declaration_rules', function (Blueprint $t): void {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->unsignedBigInteger('declaration_version_id');
            $t->string('nature', 10); $t->string('classification', 80);
            $t->unsignedBigInteger('group_version_id'); $t->unsignedBigInteger('component_id'); $t->unsignedBigInteger('rule_version_id');
            $t->string('disposition', 20); $t->boolean('requires_party_profile'); $t->text('grounds')->nullable();
            $t->unsignedBigInteger('created_by'); $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id'], 'tax_qdr_tenant_id');
            $t->unique(['company_id','declaration_version_id','nature','classification','component_id'], 'tax_qdr_identity');
            $t->foreign(['company_id','declaration_version_id'], 'tax_qdr_declaration')->references(['company_id','id'])->on('tax_quotation_declaration_versions');
            $t->foreign(['company_id','group_version_id','component_id'], 'tax_qdr_member')->references(['company_id','group_version_id','component_id'])->on('tax_group_members');
            $t->foreign(['company_id','rule_version_id','component_id'], 'tax_qdr_rule')->references(['company_id','id','component_id'])->on('tax_rule_versions');
            $t->foreign(['company_id','created_by'], 'tax_qdr_creator')->references(['company_id','id'])->on('users');
        });
        DB::statement("ALTER TABLE tax_quotation_declaration_rules ADD CONSTRAINT tax_qdr_shape CHECK (
            nature IN ('GOODS','SERVICES') AND length(trim(classification)) > 0
            AND disposition IN ('TAXABLE','ZERO_RATED','EXEMPT','NOT_APPLICABLE','UNKNOWN')
            AND (grounds IS NULL OR length(trim(grounds)) > 0)
            AND (disposition = 'UNKNOWN' OR grounds IS NOT NULL)
            AND (NOT requires_party_profile OR disposition = 'UNKNOWN')
            AND (disposition <> 'EXEMPT' OR requires_party_profile))");
        // EXEMPT can only come from the existing protected, activated FR2b evidence,
        // never an unaudited blanket declaration or a seller-provided certificate.
        Schema::create('tax_quotation_declaration_activations', function (Blueprint $t): void {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->unsignedBigInteger('declaration_version_id');
            $t->unsignedBigInteger('activated_by'); $t->text('reason'); $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','id'], 'tax_qda_tenant_id');
            $t->unique(['company_id','declaration_version_id'], 'tax_qda_identity');
            $t->foreign(['company_id','declaration_version_id'], 'tax_qda_declaration')->references(['company_id','id'])->on('tax_quotation_declaration_versions');
            $t->foreign(['company_id','activated_by'], 'tax_qda_actor')->references(['company_id','id'])->on('users');
        });
        DB::statement('ALTER TABLE tax_quotation_declaration_activations ADD CONSTRAINT tax_qda_reason CHECK (length(trim(reason)) > 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tax_fr3_validate_declaration(cid bigint, did bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE declaration record;
BEGIN
 SELECT * INTO STRICT declaration FROM tax_quotation_declaration_versions WHERE company_id=cid AND id=did;
 IF NOT EXISTS (SELECT 1 FROM tax_quotation_declaration_rules WHERE company_id=cid AND declaration_version_id=did) THEN
   RAISE EXCEPTION 'Incomplete quotation tax declaration' USING ERRCODE='23514';
 END IF;
 IF EXISTS (
   SELECT 1 FROM tax_quotation_declaration_rules q
   JOIN tax_group_versions g ON (g.company_id,g.id)=(q.company_id,q.group_version_id)
   JOIN tax_rule_versions r ON (r.company_id,r.id)=(q.company_id,q.rule_version_id)
   JOIN tax_rate_versions v ON (v.company_id,v.id)=(r.company_id,r.rate_version_id)
   JOIN tax_component_versions c ON (c.company_id,c.id)=(r.company_id,r.component_version_id)
   WHERE q.company_id=cid AND q.declaration_version_id=did AND (
      g.required_mode <> 'BASIC' OR r.required_mode <> 'BASIC' OR r.method <> 'PERCENT' OR r.base <> 'NET'
      OR c.role <> 'OUTPUT_COLLECTED' OR v.percentage IS NULL
      OR r.jurisdiction <> declaration.jurisdiction OR r.classification <> q.classification OR r.nature <> q.nature
      OR NOT daterange(g.effective_from,g.effective_to,'[)') @> daterange(declaration.effective_from,declaration.effective_to,'[)')
      OR NOT EXISTS (SELECT 1 FROM tax_group_activations a WHERE a.company_id=cid AND a.group_version_id=g.id)
      OR NOT EXISTS (SELECT 1 FROM tax_group_members m WHERE m.company_id=cid AND m.group_version_id=g.id AND m.component_id=q.component_id AND m.rule_version_id=q.rule_version_id)
      OR (SELECT count(*) FROM tax_group_members m WHERE m.company_id=cid AND m.group_version_id=g.id) <> 1
      OR EXISTS (SELECT 1 FROM tax_dependency_versions d WHERE d.company_id=cid AND d.group_version_id=g.id)
   )
 ) THEN RAISE EXCEPTION 'Unsupported or incomplete quotation tax coverage' USING ERRCODE='23514'; END IF;
 IF EXISTS (SELECT 1 FROM tax_quotation_declaration_rules WHERE company_id=cid AND declaration_version_id=did
    GROUP BY nature,classification HAVING count(*) <> 1) THEN
   RAISE EXCEPTION 'BASIC determination requires exactly one component per covered classification' USING ERRCODE='23514';
 END IF;
END $$;
CREATE OR REPLACE FUNCTION tax_fr3_write_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous record;
BEGIN
 INSERT INTO tax_generations(company_id,generation) VALUES (NEW.company_id,0) ON CONFLICT DO NOTHING;
 UPDATE tax_generations SET generation=generation+1 WHERE company_id=NEW.company_id;
 IF TG_TABLE_NAME='tax_quotation_declaration_versions' THEN
   IF NEW.predecessor_id IS NOT NULL THEN
     SELECT * INTO previous FROM tax_quotation_declaration_versions WHERE company_id=NEW.company_id AND id=NEW.predecessor_id;
     IF previous.id IS NULL OR previous.code <> NEW.code OR previous.jurisdiction <> NEW.jurisdiction
        OR previous.version_no+1 <> NEW.version_no OR previous.effective_to IS NULL OR previous.effective_to <> NEW.effective_from THEN
       RAISE EXCEPTION 'Invalid quotation tax successor' USING ERRCODE='23514';
     END IF;
   END IF;
 ELSIF TG_TABLE_NAME='tax_quotation_declaration_rules' THEN
   IF EXISTS (SELECT 1 FROM tax_quotation_declaration_activations WHERE company_id=NEW.company_id AND declaration_version_id=NEW.declaration_version_id) THEN
     RAISE EXCEPTION 'Activated quotation tax declaration is immutable' USING ERRCODE='23514';
   END IF;
 ELSIF TG_TABLE_NAME='tax_quotation_declaration_activations' THEN
   PERFORM tax_fr3_validate_declaration(NEW.company_id,NEW.declaration_version_id);
 END IF;
 RETURN NEW;
END $$;
SQL);
        foreach ($this->tables() as $table) {
            DB::statement("CREATE TRIGGER tax_fr3_write BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION tax_fr3_write_guard()");
            DB::statement("CREATE TRIGGER tax_fr3_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION tax_fr2a_append_only_guard()");
            DB::statement("CREATE TRIGGER tax_fr3_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION tax_fr2a_append_only_guard()");
        }
    }

    public function down(): void
    {
        // Take locks before checking, so a concurrent writer cannot add history after the check.
        foreach ($this->tables() as $table) {
            if (Schema::hasTable($table)) DB::statement("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");
        }
        foreach ($this->tables() as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) throw new RuntimeException('Quotation tax evidence exists; rollback would erase history.');
        }
        foreach (array_reverse($this->tables()) as $table) Schema::dropIfExists($table);
        DB::statement('DROP FUNCTION IF EXISTS tax_fr3_write_guard()');
        DB::statement('DROP FUNCTION IF EXISTS tax_fr3_validate_declaration(bigint,bigint)');
    }

    /** @return list<string> */
    private function tables(): array
    {
        return ['tax_quotation_declaration_versions','tax_quotation_declaration_rules','tax_quotation_declaration_activations'];
    }
};
