<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D3: the minimal, versioned storage of a company's final-net controls (FR4). A control set is published whole and never changed: the highest version of a company is the one in
 * force, so publishing is the only way to change a limit and every issued quotation can name the exact set that judged it. A set holds `FLOOR`, `MIN_MARGIN_AMOUNT` and
 * `MIN_MARGIN_PERCENT` controls (the kinds the evaluator supports); the rest wait for their governed-reference evidence. Control references are opaque. No exception path exists.
 * Append-only; nothing here is a seller input.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_control_sets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedInteger('version_no');
            $t->char('rounding_policy_fingerprint', 64)->nullable();
            $t->string('reason', 1000);
            $t->unsignedBigInteger('published_by');
            $t->timestampTz('published_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'quotation_control_sets_company_id_unique');
            $t->unique(['company_id', 'version_no'], 'quotation_control_sets_version_unique');
            $t->foreign(['company_id', 'published_by'], 'quotation_control_sets_publisher_fk')->references(['company_id', 'id'])->on('users');
        });
        Schema::create('quotation_net_controls', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('control_set_id');
            $t->string('control_ref', 64);
            $t->unsignedInteger('control_version');
            $t->string('kind', 24);
            $t->string('mode', 12);
            $t->decimal('limit_value', 19, 4);
            $t->unsignedBigInteger('currency_id')->nullable();
            $t->string('basis', 12);
            $t->unique(['company_id', 'control_set_id', 'control_ref'], 'quotation_net_controls_ref_unique');
            $t->foreign(['company_id', 'control_set_id'], 'quotation_net_controls_set_fk')->references(['company_id', 'id'])->on('quotation_control_sets');
            $t->foreign('currency_id', 'quotation_net_controls_currency_fk')->references('id')->on('currencies');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE quotation_net_controls ADD CONSTRAINT quotation_net_controls_shape CHECK (
    kind IN ('FLOOR','MIN_MARGIN_AMOUNT','MIN_MARGIN_PERCENT') AND mode IN ('ADVISORY','MANDATORY') AND basis IN ('LINE','PER_UNIT')
    AND length(trim(control_ref)) > 0 AND control_version >= 1 AND limit_value >= 0
    AND ((kind = 'MIN_MARGIN_PERCENT' AND currency_id IS NULL AND limit_value <= 100) OR (kind <> 'MIN_MARGIN_PERCENT' AND currency_id IS NOT NULL))
);
ALTER TABLE quotation_control_sets ADD CONSTRAINT quotation_control_sets_shape CHECK (version_no >= 1 AND length(trim(reason)) > 0);

CREATE OR REPLACE FUNCTION quotation_control_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'A published quotation control set is append-only' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER quotation_control_sets_guard BEFORE UPDATE OR DELETE ON quotation_control_sets FOR EACH ROW EXECUTE FUNCTION quotation_control_append_only_guard();
CREATE TRIGGER quotation_control_sets_truncate_guard BEFORE TRUNCATE ON quotation_control_sets FOR EACH STATEMENT EXECUTE FUNCTION quotation_control_append_only_guard();
CREATE TRIGGER quotation_net_controls_guard BEFORE UPDATE OR DELETE ON quotation_net_controls FOR EACH ROW EXECUTE FUNCTION quotation_control_append_only_guard();
CREATE TRIGGER quotation_net_controls_truncate_guard BEFORE TRUNCATE ON quotation_net_controls FOR EACH STATEMENT EXECUTE FUNCTION quotation_control_append_only_guard();

-- A set is published whole: its controls arrive with it, in the one transaction, and a set cannot gain a control later.
CREATE OR REPLACE FUNCTION quotation_net_control_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE published timestamptz; BEGIN
 SELECT published_at INTO published FROM quotation_control_sets WHERE company_id = NEW.company_id AND id = NEW.control_set_id;
 IF published IS DISTINCT FROM transaction_timestamp() THEN RAISE EXCEPTION 'Controls are published with their set, in one transaction' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_net_controls_insert_guard BEFORE INSERT ON quotation_net_controls FOR EACH ROW EXECUTE FUNCTION quotation_net_control_insert_guard();
SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE quotation_control_sets, quotation_net_controls IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_control_sets')->exists()) {
            throw new RuntimeException('Published control sets exist: they are the record of what judged issued quotations. Plan a separately approved backout first.');
        }
        Schema::dropIfExists('quotation_net_controls');
        Schema::dropIfExists('quotation_control_sets');
        DB::unprepared('DROP FUNCTION IF EXISTS quotation_net_control_insert_guard(); DROP FUNCTION IF EXISTS quotation_control_append_only_guard();');
    }
};
