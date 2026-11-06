<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['sales_quotation_reference_anchors', 'sales_discount_policy_versions', 'sales_discount_bands', 'sales_discount_policy_activations', 'sales_discount_assignments', 'sales_discount_revocations', 'sales_discount_mode_events', 'sales_discount_evaluations'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE sales_quotation_reference_anchors (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), family_id bigint NOT NULL, logical_scope_id bigint NOT NULL,
 binding_id bigint NOT NULL, economic_key char(64) NOT NULL, recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(company_id,id), UNIQUE(company_id,logical_scope_id),
 FOREIGN KEY(company_id,family_id,logical_scope_id) REFERENCES quotation_logical_scopes(company_id,family_id,id),
 FOREIGN KEY(company_id,binding_id) REFERENCES sales_quotation_reference_bindings(company_id,id)
);
CREATE TABLE sales_discount_policy_versions (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), code varchar(80) NOT NULL,
 version_no integer NOT NULL CHECK(version_no>0), priority integer NOT NULL, base_currency_id bigint NOT NULL REFERENCES currencies(id),
 allow_higher_approver boolean NOT NULL DEFAULT false, operation varchar(16) NOT NULL CHECK(operation IN ('ANY','DISCOUNT','OVERRIDE')),
 customer_id bigint, unit_id bigint, contains_item_id bigint, enforcement varchar(16) NOT NULL CHECK(enforcement IN ('LINE','DOCUMENT','BOTH')),
 effective_from date NOT NULL, effective_to date, expiry_calendar_days integer NOT NULL DEFAULT 14 CHECK(expiry_calendar_days BETWEEN 1 AND 365),
 created_by bigint NOT NULL, reason text NOT NULL CHECK(length(trim(reason)) BETWEEN 1 AND 1000),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(company_id,id), UNIQUE(company_id,code,version_no),
 CHECK(effective_to IS NULL OR effective_to>effective_from),
 FOREIGN KEY(company_id,customer_id) REFERENCES parties(company_id,id),
 FOREIGN KEY(company_id,unit_id) REFERENCES organisation_units(company_id,id),
 FOREIGN KEY(company_id,contains_item_id) REFERENCES items(company_id,id),
 FOREIGN KEY(company_id,created_by) REFERENCES users(company_id,id)
);
CREATE TABLE sales_discount_bands (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), policy_id bigint NOT NULL,
 rank integer NOT NULL CHECK(rank>=0), label varchar(100) NOT NULL,
 line_percent decimal(19,4), line_base_amount decimal(19,4), document_percent decimal(19,4), document_base_amount decimal(19,4),
 input_percent decimal(19,4), input_base_amount decimal(19,4), exception_only boolean NOT NULL DEFAULT false,
 UNIQUE(company_id,id), UNIQUE(company_id,policy_id,rank),
 FOREIGN KEY(company_id,policy_id) REFERENCES sales_discount_policy_versions(company_id,id),
 CHECK(line_percent IS NULL OR line_percent BETWEEN 0 AND 100), CHECK(document_percent IS NULL OR document_percent BETWEEN 0 AND 100), CHECK(input_percent IS NULL OR input_percent BETWEEN 0 AND 100),
 CHECK(line_base_amount IS NULL OR line_base_amount>=0), CHECK(document_base_amount IS NULL OR document_base_amount>=0), CHECK(input_base_amount IS NULL OR input_base_amount>=0),
 CHECK(exception_only OR COALESCE(line_percent,line_base_amount,document_percent,document_base_amount,input_percent,input_base_amount) IS NOT NULL),
 CHECK(NOT exception_only OR COALESCE(line_percent,line_base_amount,document_percent,document_base_amount,input_percent,input_base_amount) IS NULL)
);
CREATE TABLE sales_discount_policy_activations (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), policy_id bigint NOT NULL, actor_id bigint NOT NULL,
 reason text NOT NULL CHECK(length(trim(reason)) BETWEEN 1 AND 1000), recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(company_id,id), UNIQUE(company_id,policy_id), FOREIGN KEY(company_id,policy_id) REFERENCES sales_discount_policy_versions(company_id,id),
 FOREIGN KEY(company_id,actor_id) REFERENCES users(company_id,id)
);
CREATE TABLE sales_discount_assignments (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), user_id bigint NOT NULL, band_id bigint NOT NULL,
 role_id bigint REFERENCES roles(id), unit_id bigint, purpose varchar(16) NOT NULL CHECK(purpose IN ('ROUTINE','APPROVE','BOTH')),
 effective_from date NOT NULL, effective_to date, assigned_by bigint NOT NULL, reason text NOT NULL CHECK(length(trim(reason)) BETWEEN 1 AND 1000),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(company_id,id), CHECK(effective_to IS NULL OR effective_to>effective_from),
 FOREIGN KEY(company_id,user_id) REFERENCES users(company_id,id), FOREIGN KEY(company_id,band_id) REFERENCES sales_discount_bands(company_id,id),
 FOREIGN KEY(company_id,unit_id) REFERENCES organisation_units(company_id,id), FOREIGN KEY(company_id,assigned_by) REFERENCES users(company_id,id)
);
CREATE INDEX sales_discount_assignment_lookup ON sales_discount_assignments(company_id,user_id,band_id,effective_from);
CREATE TABLE sales_discount_revocations (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), assignment_id bigint NOT NULL, actor_id bigint NOT NULL,
 reason text NOT NULL CHECK(length(trim(reason)) BETWEEN 1 AND 1000), recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(company_id,id), UNIQUE(company_id,assignment_id), FOREIGN KEY(company_id,assignment_id) REFERENCES sales_discount_assignments(company_id,id),
 FOREIGN KEY(company_id,actor_id) REFERENCES users(company_id,id)
);
CREATE TABLE sales_discount_mode_events (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), mode varchar(8) NOT NULL CHECK(mode IN ('OFF','SHADOW','ENFORCE')),
 actor_id bigint NOT NULL, reason text NOT NULL CHECK(length(trim(reason)) BETWEEN 1 AND 1000), recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(company_id,id), FOREIGN KEY(company_id,actor_id) REFERENCES users(company_id,id)
);
CREATE TABLE sales_discount_evaluations (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), revision_id bigint NOT NULL, input_generation bigint NOT NULL,
 actor_id bigint NOT NULL, authority_generation bigint NOT NULL, policy_id bigint, mode varchar(8) NOT NULL CHECK(mode IN ('SHADOW','ENFORCE')),
 result varchar(32) NOT NULL CHECK(result IN ('ROUTINE','APPROVAL_REQUIRED','UNEVALUABLE','NOT_APPLICABLE')),
 transaction_currency_id bigint NOT NULL REFERENCES currencies(id), base_currency_id bigint REFERENCES currencies(id),
 reference_amount decimal(19,4), reference_base_amount decimal(19,4), final_net_amount decimal(19,4), final_net_base_amount decimal(19,4),
 reduction_amount decimal(19,4), reduction_base_amount decimal(19,4),
 snapshot jsonb NOT NULL, fingerprint char(64) NOT NULL, recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(company_id,id), FOREIGN KEY(company_id,revision_id) REFERENCES quotation_revisions(company_id,id), FOREIGN KEY(company_id,actor_id) REFERENCES users(company_id,id),
 FOREIGN KEY(company_id,policy_id) REFERENCES sales_discount_policy_versions(company_id,id),
 CHECK(reference_amount IS NULL OR reference_amount>=0), CHECK(reference_base_amount IS NULL OR reference_base_amount>=0),
 CHECK(final_net_amount IS NULL OR final_net_amount>=0), CHECK(final_net_base_amount IS NULL OR final_net_base_amount>=0),
 CHECK(reduction_amount IS NULL OR reduction_amount>=0), CHECK(reduction_base_amount IS NULL OR reduction_base_amount>=0),
 CHECK((reference_amount IS NULL)=(reference_base_amount IS NULL)),
 CHECK((final_net_amount IS NULL)=(final_net_base_amount IS NULL)),
 CHECK((reduction_amount IS NULL)=(reduction_base_amount IS NULL)),
 CHECK(base_currency_id IS NOT NULL OR (result='UNEVALUABLE' AND reference_amount IS NULL AND final_net_amount IS NULL AND reduction_amount IS NULL)),
 CHECK(result='UNEVALUABLE' OR (policy_id IS NOT NULL AND reference_amount IS NOT NULL AND final_net_amount IS NOT NULL AND reduction_amount IS NOT NULL))
);
CREATE INDEX sales_discount_evaluation_lookup ON sales_discount_evaluations(company_id,revision_id,id DESC);
CREATE OR REPLACE FUNCTION sales_reference_anchor_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM sales_quotation_reference_bindings WHERE company_id=NEW.company_id AND id=NEW.binding_id AND family_id=NEW.family_id AND economic_key=NEW.economic_key) THEN
  RAISE EXCEPTION 'Reference anchor must name its own family and economic binding' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_reference_anchor_guard BEFORE INSERT ON sales_quotation_reference_anchors FOR EACH ROW EXECUTE FUNCTION sales_reference_anchor_guard();
CREATE OR REPLACE FUNCTION sales_discount_version_shape_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='sales_discount_bands' THEN
  IF EXISTS(SELECT 1 FROM sales_discount_policy_activations WHERE company_id=NEW.company_id AND policy_id=NEW.policy_id) THEN
   RAISE EXCEPTION 'An activated policy cannot acquire new bands' USING ERRCODE='23514';
  END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM sales_discount_bands WHERE company_id=NEW.company_id AND policy_id=NEW.policy_id) THEN
   RAISE EXCEPTION 'A policy needs explicit bands before activation' USING ERRCODE='23514';
  END IF;
  IF EXISTS(SELECT 1 FROM sales_discount_policy_versions p JOIN sales_discount_policy_versions n ON n.company_id=p.company_id AND n.id=NEW.policy_id
    JOIN sales_discount_policy_activations a ON a.company_id=p.company_id AND a.policy_id=p.id
    WHERE p.company_id=NEW.company_id AND p.code=n.code AND (p.version_no>=n.version_no OR p.effective_from>=n.effective_from)) THEN
   RAISE EXCEPTION 'Activated policy intervals must have an ordered successor' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_discount_version_bands BEFORE INSERT ON sales_discount_bands FOR EACH ROW EXECUTE FUNCTION sales_discount_version_shape_guard();
CREATE TRIGGER sales_discount_version_activation BEFORE INSERT ON sales_discount_policy_activations FOR EACH ROW EXECUTE FUNCTION sales_discount_version_shape_guard();
CREATE OR REPLACE FUNCTION sales_discount_assignment_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.purpose<>'APPROVE' AND EXISTS(SELECT 1 FROM sales_discount_bands WHERE company_id=NEW.company_id AND id=NEW.band_id AND exception_only) THEN
  RAISE EXCEPTION 'Exception-only authority cannot grant routine clearance' USING ERRCODE='23514';
 END IF;
 IF NEW.role_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM roles WHERE id=NEW.role_id AND team_id=NEW.company_id) THEN
  RAISE EXCEPTION 'Authority role must belong to this company' USING ERRCODE='23514';
 END IF;
 IF EXISTS(
  SELECT 1 FROM sales_discount_assignments a JOIN sales_discount_bands b ON b.company_id=a.company_id AND b.id=a.band_id
  JOIN sales_discount_bands nb ON nb.company_id=NEW.company_id AND nb.id=NEW.band_id
  WHERE a.company_id=NEW.company_id AND a.user_id=NEW.user_id AND b.policy_id=nb.policy_id
   AND (a.unit_id IS NULL OR NEW.unit_id IS NULL OR a.unit_id=NEW.unit_id)
   AND (a.purpose='BOTH' OR NEW.purpose='BOTH' OR a.purpose=NEW.purpose)
   AND daterange(a.effective_from,a.effective_to,'[)') && daterange(NEW.effective_from,NEW.effective_to,'[)')
   AND NOT EXISTS(SELECT 1 FROM sales_discount_revocations r WHERE r.company_id=a.company_id AND r.assignment_id=a.id)
 ) THEN RAISE EXCEPTION 'Overlapping authority assignments are ambiguous' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_discount_assignment_guard BEFORE INSERT ON sales_discount_assignments FOR EACH ROW EXECUTE FUNCTION sales_discount_assignment_guard();
SQL);
        foreach (self::TABLES as $table) {
            DB::statement("CREATE TRIGGER sales_discount_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION sales_authority_reject_mutation()");
            if ($table !== 'sales_discount_evaluations') {
                DB::statement("CREATE TRIGGER sales_discount_barrier BEFORE INSERT ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION sales_authority_writer_barrier()");
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Discount authority evidence exists; rollback would destroy its history.');
            }
        }
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("DROP TABLE {$table}");
        }
        DB::statement('DROP FUNCTION sales_discount_assignment_guard(), sales_discount_version_shape_guard(), sales_reference_anchor_guard()');
    }
};
