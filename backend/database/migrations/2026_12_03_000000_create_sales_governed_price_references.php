<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE sales_authority_heads (
 company_id bigint PRIMARY KEY REFERENCES companies(id), generation bigint NOT NULL DEFAULT 0 CHECK (generation >= 0)
);
INSERT INTO sales_authority_heads(company_id) SELECT id FROM companies;
CREATE TABLE sales_price_reference_versions (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id),
 code varchar(80) NOT NULL, version_no integer NOT NULL CHECK (version_no > 0),
 source_kind varchar(24) NOT NULL CHECK (source_kind IN ('CUSTOMER_AGREEMENT','PRICE_LIST','PROJECT_BASELINE')),
 source_key varchar(160) NOT NULL CHECK (length(trim(source_key)) > 0), source_version varchar(80) NOT NULL,
 family_id bigint, customer_id bigint, item_id bigint, manual_measure varchar(80), uom_id bigint,
 currency_id bigint NOT NULL REFERENCES currencies(id), unit_price decimal(19,4) NOT NULL CHECK (unit_price > 0),
 effective_from date NOT NULL, effective_to date, priority integer NOT NULL DEFAULT 0,
 proposed_by bigint NOT NULL, reason text NOT NULL CHECK (length(trim(reason)) BETWEEN 1 AND 1000),
 fingerprint char(64) NOT NULL, recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(company_id,id), UNIQUE(company_id,code,version_no),
 FOREIGN KEY(company_id,proposed_by) REFERENCES users(company_id,id),
 FOREIGN KEY(company_id,family_id) REFERENCES quotation_families(company_id,id),
 FOREIGN KEY(company_id,customer_id) REFERENCES parties(company_id,id),
 FOREIGN KEY(company_id,item_id) REFERENCES items(company_id,id),
 FOREIGN KEY(company_id,uom_id) REFERENCES uoms(company_id,id),
 CHECK (effective_to IS NULL OR effective_to > effective_from),
 CHECK ((item_id IS NOT NULL AND manual_measure IS NULL) OR (item_id IS NULL AND manual_measure IS NOT NULL AND family_id IS NOT NULL AND source_kind = 'PROJECT_BASELINE')),
 CHECK (source_kind <> 'CUSTOMER_AGREEMENT' OR customer_id IS NOT NULL)
);
CREATE INDEX sales_price_reference_lookup ON sales_price_reference_versions(company_id,currency_id,item_id,family_id,effective_from);
CREATE TABLE sales_price_reference_activations (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), reference_id bigint NOT NULL,
 approved_by bigint NOT NULL, reason text NOT NULL CHECK (length(trim(reason)) BETWEEN 1 AND 1000),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(company_id,id), UNIQUE(company_id,reference_id),
 FOREIGN KEY(company_id,reference_id) REFERENCES sales_price_reference_versions(company_id,id),
 FOREIGN KEY(company_id,approved_by) REFERENCES users(company_id,id)
);
CREATE TABLE sales_quotation_reference_bindings (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), family_id bigint NOT NULL,
 economic_key char(64) NOT NULL, reference_id bigint NOT NULL, bound_by bigint NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(company_id,id), UNIQUE(company_id,family_id,economic_key),
 FOREIGN KEY(company_id,family_id) REFERENCES quotation_families(company_id,id),
 FOREIGN KEY(company_id,reference_id) REFERENCES sales_price_reference_versions(company_id,id),
 FOREIGN KEY(company_id,bound_by) REFERENCES users(company_id,id)
);
CREATE TABLE sales_authority_operation_receipts (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), actor_id bigint NOT NULL,
 request_key varchar(85) NOT NULL, intent_hash char(64) NOT NULL, result jsonb NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(company_id,id), UNIQUE(company_id,actor_id,request_key),
 FOREIGN KEY(company_id,actor_id) REFERENCES users(company_id,id)
);
CREATE OR REPLACE FUNCTION sales_authority_reject_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Sales authority evidence is append-only' USING ERRCODE = '23514'; END $$;
CREATE OR REPLACE FUNCTION sales_authority_head_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Authority heads cannot be removed' USING ERRCODE='23514'; END IF;
 IF NEW.company_id <> OLD.company_id OR NEW.generation < OLD.generation THEN
  RAISE EXCEPTION 'Authority generations cannot decrease' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_authority_head_guard BEFORE UPDATE OR DELETE ON sales_authority_heads FOR EACH ROW EXECUTE FUNCTION sales_authority_head_guard();
CREATE TRIGGER sales_authority_head_truncate BEFORE TRUNCATE ON sales_authority_heads FOR EACH STATEMENT EXECUTE FUNCTION sales_authority_head_guard();
CREATE OR REPLACE FUNCTION sales_authority_writer_barrier() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cid bigint;
BEGIN
 INSERT INTO company_access_generations(company_id) SELECT id FROM companies ORDER BY id ON CONFLICT DO NOTHING;
 FOR cid IN SELECT company_id FROM company_access_generations ORDER BY company_id FOR UPDATE LOOP END LOOP;
 INSERT INTO sales_authority_heads(company_id) SELECT id FROM companies ORDER BY id ON CONFLICT DO NOTHING;
 FOR cid IN SELECT company_id FROM sales_authority_heads ORDER BY company_id FOR UPDATE LOOP
  UPDATE sales_authority_heads SET generation = generation + 1 WHERE company_id = cid;
 END LOOP;
 RETURN NULL;
END $$;
CREATE OR REPLACE FUNCTION sales_reference_activation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE proposer bigint;
BEGIN
 SELECT proposed_by INTO proposer FROM sales_price_reference_versions WHERE company_id=NEW.company_id AND id=NEW.reference_id;
 IF proposer IS NULL OR proposer = NEW.approved_by THEN RAISE EXCEPTION 'Reference activation requires an independent authoriser' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_reference_independence BEFORE INSERT ON sales_price_reference_activations FOR EACH ROW EXECUTE FUNCTION sales_reference_activation_guard();
CREATE OR REPLACE FUNCTION sales_reference_binding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM sales_price_reference_activations WHERE company_id=NEW.company_id AND reference_id=NEW.reference_id) THEN
  RAISE EXCEPTION 'A reference must be independently approved before binding' USING ERRCODE='23514';
 END IF;
 IF EXISTS (SELECT 1 FROM sales_price_reference_versions WHERE company_id=NEW.company_id AND id=NEW.reference_id AND family_id IS NOT NULL AND family_id<>NEW.family_id) THEN
  RAISE EXCEPTION 'Reference belongs to a different commercial family' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_reference_binding_approval BEFORE INSERT ON sales_quotation_reference_bindings FOR EACH ROW EXECUTE FUNCTION sales_reference_binding_guard();
SQL);
        foreach (['sales_price_reference_versions', 'sales_price_reference_activations', 'sales_quotation_reference_bindings', 'sales_authority_operation_receipts'] as $table) {
            DB::statement("CREATE TRIGGER sales_authority_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION sales_authority_reject_mutation()");
        }
        foreach (['sales_price_reference_versions', 'sales_price_reference_activations', 'sales_quotation_reference_bindings'] as $table) {
            DB::statement("CREATE TRIGGER sales_authority_barrier BEFORE INSERT ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION sales_authority_writer_barrier()");
        }
    }

    public function down(): void
    {
        $tables = ['sales_authority_operation_receipts', 'sales_quotation_reference_bindings', 'sales_price_reference_activations', 'sales_price_reference_versions'];
        foreach ($tables as $table) {
            DB::statement("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Governed reference evidence exists; rollback would destroy its history.');
            }
        }
        foreach ($tables as $table) {
            DB::statement("DROP TABLE {$table}");
        }
        DB::statement('DROP TABLE sales_authority_heads');
        DB::statement('DROP FUNCTION sales_reference_binding_guard(), sales_reference_activation_guard(), sales_authority_writer_barrier(), sales_authority_reject_mutation(), sales_authority_head_guard()');
    }
};
