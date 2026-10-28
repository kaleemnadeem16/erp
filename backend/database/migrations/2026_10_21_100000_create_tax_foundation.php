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
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        Schema::create('tax_generations', function (Blueprint $t): void {
            $t->foreignId('company_id')->primary()->constrained();
            $t->bigInteger('generation')->default(0);
        });
        DB::statement('ALTER TABLE tax_generations ADD CONSTRAINT tax_generation_nonnegative CHECK (generation >= 0)');
        Schema::create('tax_components', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('code', 80);
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'code']);
        });
        DB::statement("ALTER TABLE tax_components ADD CONSTRAINT tax_component_code CHECK (code ~ '^[a-z0-9][a-z0-9_.-]*$')");
        foreach (['component', 'rate', 'rule', 'group'] as $kind) {
            $table = "tax_{$kind}_versions";
            Schema::create($table, function (Blueprint $t) use ($kind, $table): void {
                $t->id();
                $t->foreignId('company_id')->constrained();
                $t->string('code', 80);
                $t->integer('version_no');
                $t->unsignedBigInteger('predecessor_id')->nullable();
                $t->date('effective_from');
                $t->date('effective_to')->nullable();
                $t->text('reason');
                $t->unsignedBigInteger('created_by');
                $t->timestampTz('created_at')->useCurrent();
                $t->unique(['company_id', 'id']);
                $t->unique(['company_id', 'code', 'version_no']);
                $t->foreign(['company_id', 'predecessor_id'])->references(['company_id', 'id'])->on($table);
                $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users');
                if ($kind !== 'group') {
                    $t->unsignedBigInteger('component_id');
                    $t->foreign(['company_id', 'component_id'])->references(['company_id', 'id'])->on('tax_components');
                    $t->unique(['company_id', 'id', 'component_id'], "tax_{$kind}_version_component_unique");
                }
                if ($kind === 'component') {
                    $t->string('name', 160);
                    $t->string('role', 30)->default('OUTPUT_COLLECTED');
                }
                if ($kind === 'rate') {
                    $t->decimal('percentage', 12, 9)->nullable();
                    $t->decimal('amount', 19, 4)->nullable();
                    $t->foreignId('currency_id')->nullable()->constrained('currencies');
                }
                if ($kind === 'rule') {
                    $t->unsignedBigInteger('component_version_id');
                    $t->unsignedBigInteger('rate_version_id');
                    $t->string('method', 30);
                    $t->string('base', 30);
                    $t->string('dependency_stage', 10);
                    $t->string('unit_basis', 80)->nullable();
                    $t->string('required_mode', 20);
                    $t->string('nature', 10);
                    $t->string('classification', 80);
                    $t->string('jurisdiction', 80);
                    $t->foreign(['company_id', 'component_version_id', 'component_id'], 'tax_rule_component_version_fk')->references(['company_id', 'id', 'component_id'])->on('tax_component_versions');
                    $t->foreign(['company_id', 'rate_version_id', 'component_id'], 'tax_rule_rate_version_fk')->references(['company_id', 'id', 'component_id'])->on('tax_rate_versions');
                }
                if ($kind === 'group') {
                    $t->string('required_mode', 20);
                    $t->foreignId('currency_id')->constrained('currencies');
                }
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT tax_{$kind}_version_shape CHECK (
                version_no > 0 AND code ~ '^[a-z0-9][a-z0-9_.-]*$' AND length(trim(reason)) > 0
                AND (effective_to IS NULL OR effective_to > effective_from)
                AND ((version_no = 1 AND predecessor_id IS NULL) OR (version_no > 1 AND predecessor_id IS NOT NULL))
            )");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT tax_{$kind}_period_exclusion EXCLUDE USING gist
                (company_id WITH =, code WITH =, daterange(effective_from, effective_to, '[)') WITH &&)");
        }
        DB::statement("ALTER TABLE tax_component_versions ADD CONSTRAINT tax_component_role CHECK (role = 'OUTPUT_COLLECTED' AND length(trim(name)) > 0)");
        DB::statement("ALTER TABLE tax_rate_versions ADD CONSTRAINT tax_rate_shape CHECK (
            (percentage IS NOT NULL AND percentage >= 0 AND percentage <= 100 AND amount IS NULL AND currency_id IS NULL)
            OR (percentage IS NULL AND amount IS NOT NULL AND amount >= 0 AND amount <> 'NaN'::numeric AND currency_id IS NOT NULL))");
        DB::statement("ALTER TABLE tax_rule_versions ADD CONSTRAINT tax_rule_shape CHECK (
            method IN ('PERCENT', 'FIXED_LINE', 'FIXED_DOCUMENT', 'PER_UNIT')
            AND base IN ('NET', 'TAX_AMOUNT', 'NET_PLUS_COMPONENTS') AND dependency_stage IN ('RAW', 'SETTLED')
            AND required_mode IN ('BASIC', 'LOCALISED', 'ADVANCED') AND nature IN ('GOODS', 'SERVICES', 'BOTH')
            AND length(trim(classification)) > 0 AND length(trim(jurisdiction)) > 0
            AND ((method = 'PER_UNIT' AND unit_basis IS NOT NULL AND length(trim(unit_basis)) > 0)
                OR (method <> 'PER_UNIT' AND unit_basis IS NULL))
            AND (method = 'PERCENT' OR base = 'NET'))");
        DB::statement("ALTER TABLE tax_group_versions ADD CONSTRAINT tax_group_mode CHECK (required_mode IN ('BASIC', 'LOCALISED', 'ADVANCED'))");
        Schema::create('tax_group_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('group_version_id');
            $t->unsignedBigInteger('component_id');
            $t->unsignedBigInteger('rule_version_id');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'group_version_id', 'component_id']);
            $t->foreign(['company_id', 'group_version_id'])->references(['company_id', 'id'])->on('tax_group_versions');
            $t->foreign(['company_id', 'component_id'])->references(['company_id', 'id'])->on('tax_components');
            $t->foreign(['company_id', 'rule_version_id', 'component_id'], 'tax_member_rule_component_fk')->references(['company_id', 'id', 'component_id'])->on('tax_rule_versions');
        });
        Schema::create('tax_dependency_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('group_version_id');
            $t->unsignedBigInteger('component_id');
            $t->unsignedBigInteger('predecessor_component_id');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'group_version_id', 'component_id', 'predecessor_component_id'], 'tax_dependency_identity_unique');
            $t->foreign(['company_id', 'group_version_id', 'component_id'], 'tax_dependency_member_fk')->references(['company_id', 'group_version_id', 'component_id'])->on('tax_group_members');
            $t->foreign(['company_id', 'group_version_id', 'predecessor_component_id'], 'tax_dependency_predecessor_fk')->references(['company_id', 'group_version_id', 'component_id'])->on('tax_group_members');
        });
        DB::statement('ALTER TABLE tax_dependency_versions ADD CONSTRAINT tax_dependency_not_self CHECK (component_id <> predecessor_component_id)');
        Schema::create('tax_group_activations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('group_version_id');
            $t->unsignedBigInteger('activated_by');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'group_version_id']);
            $t->foreign(['company_id', 'group_version_id'])->references(['company_id', 'id'])->on('tax_group_versions');
            $t->foreign(['company_id', 'activated_by'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE tax_group_activations ADD CONSTRAINT tax_activation_reason CHECK (length(trim(reason)) > 0)');
        Schema::create('tax_operation_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('operation_key', 120);
            $t->char('intent_digest', 64);
            $t->jsonb('outcome');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'operation_key']);
        });
        DB::statement("ALTER TABLE tax_operation_receipts ADD CONSTRAINT tax_receipt_shape CHECK (length(trim(operation_key)) > 0 AND intent_digest ~ '^[0-9a-f]{64}$' AND jsonb_typeof(outcome) = 'object')");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tax_fr2a_write_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous record;
BEGIN
 INSERT INTO tax_generations(company_id, generation) VALUES (NEW.company_id, 0) ON CONFLICT DO NOTHING;
 UPDATE tax_generations SET generation = generation + 1 WHERE company_id = NEW.company_id;
 IF TG_TABLE_NAME IN ('tax_group_members', 'tax_dependency_versions') THEN
   IF EXISTS (SELECT 1 FROM tax_group_activations WHERE company_id = NEW.company_id AND group_version_id = NEW.group_version_id) THEN
     RAISE EXCEPTION 'Activated tax graphs are immutable' USING ERRCODE = '23514';
   END IF;
 END IF;
 IF TG_TABLE_NAME = 'tax_component_versions' THEN
   IF NOT EXISTS (SELECT 1 FROM tax_components WHERE company_id = NEW.company_id AND id = NEW.component_id AND code = NEW.code) THEN
     RAISE EXCEPTION 'Component version code must match its stable identity' USING ERRCODE = '23514';
   END IF;
 END IF;
 IF TG_TABLE_NAME IN ('tax_component_versions', 'tax_rate_versions', 'tax_rule_versions', 'tax_group_versions') THEN
 IF NEW.predecessor_id IS NOT NULL THEN
   EXECUTE format('SELECT * FROM %I WHERE company_id = $1 AND id = $2', TG_TABLE_NAME) INTO previous USING NEW.company_id, NEW.predecessor_id;
   IF previous.id IS NULL OR previous.code <> NEW.code OR previous.version_no + 1 <> NEW.version_no
      OR previous.effective_to IS NULL OR previous.effective_to <> NEW.effective_from THEN
     RAISE EXCEPTION 'Tax successor must follow its exact bounded predecessor' USING ERRCODE = '23514';
   END IF;
   IF TG_TABLE_NAME <> 'tax_group_versions' THEN
     IF previous.component_id <> NEW.component_id THEN
       RAISE EXCEPTION 'Tax successor cannot change component identity' USING ERRCODE = '23514';
     END IF;
   END IF;
 END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION tax_fr2a_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION '% rows are immutable; create a successor', TG_TABLE_NAME USING ERRCODE = '23514';
END $$;
CREATE OR REPLACE FUNCTION tax_fr2a_validate_group(cid bigint, gid bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE g record;
BEGIN
 SELECT * INTO STRICT g FROM tax_group_versions WHERE company_id = cid AND id = gid;
 IF NOT EXISTS (SELECT 1 FROM tax_group_members WHERE company_id = cid AND group_version_id = gid) THEN
   RAISE EXCEPTION 'Tax group needs components' USING ERRCODE = '23514';
 END IF;
 IF EXISTS (
   SELECT 1 FROM tax_group_members m
   JOIN tax_rule_versions r ON (r.company_id, r.id) = (m.company_id, m.rule_version_id)
   JOIN tax_rate_versions v ON (v.company_id, v.id) = (r.company_id, r.rate_version_id)
   JOIN tax_component_versions c ON (c.company_id, c.id) = (r.company_id, r.component_version_id)
   WHERE m.company_id = cid AND m.group_version_id = gid AND (
     NOT daterange(r.effective_from, r.effective_to, '[)') @> daterange(g.effective_from, g.effective_to, '[)')
     OR NOT daterange(v.effective_from, v.effective_to, '[)') @> daterange(g.effective_from, g.effective_to, '[)')
     OR NOT daterange(c.effective_from, c.effective_to, '[)') @> daterange(g.effective_from, g.effective_to, '[)')
     OR (r.method = 'PERCENT') <> (v.percentage IS NOT NULL)
     OR (v.currency_id IS NOT NULL AND v.currency_id <> g.currency_id)
     OR (r.required_mode = 'ADVANCED' AND g.required_mode <> 'ADVANCED')
     OR (r.required_mode = 'LOCALISED' AND g.required_mode = 'BASIC')
     OR (r.base <> 'NET' AND g.required_mode <> 'ADVANCED')
     OR (r.method <> 'PERCENT' AND g.required_mode <> 'ADVANCED')
     OR (r.base = 'NET' AND EXISTS (SELECT 1 FROM tax_dependency_versions d WHERE d.company_id = cid AND d.group_version_id = gid AND d.component_id = m.component_id))
     OR (r.base <> 'NET' AND NOT EXISTS (SELECT 1 FROM tax_dependency_versions d WHERE d.company_id = cid AND d.group_version_id = gid AND d.component_id = m.component_id))
   )
 ) THEN RAISE EXCEPTION 'Tax graph has incompatible period, method, base, mode or currency' USING ERRCODE = '23514'; END IF;
 IF g.required_mode = 'BASIC' AND (SELECT count(*) FROM tax_group_members WHERE company_id = cid AND group_version_id = gid) <> 1 THEN
   RAISE EXCEPTION 'BASIC tax group requires one component' USING ERRCODE = '23514';
 END IF;
 IF EXISTS (
   SELECT 1 FROM tax_dependency_versions d
   JOIN tax_group_members child ON (child.company_id, child.group_version_id, child.component_id) = (d.company_id, d.group_version_id, d.component_id)
   JOIN tax_group_members parent ON (parent.company_id, parent.group_version_id, parent.component_id) = (d.company_id, d.group_version_id, d.predecessor_component_id)
   JOIN tax_rule_versions cr ON (cr.company_id, cr.id) = (child.company_id, child.rule_version_id)
   JOIN tax_rule_versions pr ON (pr.company_id, pr.id) = (parent.company_id, parent.rule_version_id)
   WHERE d.company_id = cid AND d.group_version_id = gid AND (
     pr.method = 'FIXED_DOCUMENT' OR cr.jurisdiction <> pr.jurisdiction OR cr.classification <> pr.classification
     OR (cr.nature <> pr.nature AND cr.nature <> 'BOTH' AND pr.nature <> 'BOTH')
   )
 ) THEN RAISE EXCEPTION 'Tax dependency has incompatible assessment scope or classification' USING ERRCODE = '23514'; END IF;
 IF EXISTS (
   WITH RECURSIVE reach(component_id, predecessor_id) AS (
     SELECT component_id, predecessor_component_id FROM tax_dependency_versions WHERE company_id = cid AND group_version_id = gid
     UNION
     SELECT w.component_id, d.predecessor_component_id FROM reach w
     JOIN tax_dependency_versions d ON d.component_id = w.predecessor_id AND d.company_id = cid AND d.group_version_id = gid
   ) SELECT 1 FROM reach WHERE component_id = predecessor_id
 ) THEN RAISE EXCEPTION 'Tax graph contains a cycle' USING ERRCODE = '23514'; END IF;
END $$;
CREATE OR REPLACE FUNCTION tax_fr2a_activation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM tax_fr2a_validate_group(NEW.company_id, NEW.group_version_id);
 RETURN NEW;
END $$;
SQL);
        foreach ($this->evidenceTables() as $table) {
            DB::statement("CREATE TRIGGER tax_a_write BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION tax_fr2a_write_guard()");
            DB::statement("CREATE TRIGGER tax_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION tax_fr2a_append_only_guard()");
            DB::statement("CREATE TRIGGER tax_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION tax_fr2a_append_only_guard()");
        }
        DB::statement('CREATE TRIGGER tax_b_activate BEFORE INSERT ON tax_group_activations FOR EACH ROW EXECUTE FUNCTION tax_fr2a_activation_guard()');
    }

    public function down(): void
    {
        foreach ($this->evidenceTables() as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Tax evidence exists; rollback would erase history. Plan a backout first.');
            }
        }
        foreach (array_reverse($this->evidenceTables()) as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('tax_generations');
        DB::statement('DROP FUNCTION IF EXISTS tax_fr2a_activation_guard()');
        DB::statement('DROP FUNCTION IF EXISTS tax_fr2a_validate_group(bigint, bigint)');
        DB::statement('DROP FUNCTION IF EXISTS tax_fr2a_write_guard()');
        DB::statement('DROP FUNCTION IF EXISTS tax_fr2a_append_only_guard()');
    }

    /** @return list<string> */
    private function evidenceTables(): array
    {
        return ['tax_components', 'tax_component_versions', 'tax_rate_versions', 'tax_rule_versions', 'tax_group_versions', 'tax_group_members', 'tax_dependency_versions', 'tax_group_activations', 'tax_operation_receipts'];
    }
};
