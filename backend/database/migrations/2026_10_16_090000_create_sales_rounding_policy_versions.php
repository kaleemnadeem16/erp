<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR4/PX6 policy foundation (ADRs 0120, 0144): the company's versioned Sales rounding and unit-price precision policy. A version holds only what the
 * accepted contract says it holds today — the unit-price entry precision (two to four places) and the rounding mode that settles a line net to the
 * currency's minor unit. The quantum itself comes from the currency's own decimal places, never from here.
 *
 * Versions form a timeline per company. A DRAFT is created, then ACTIVATED with an effective date; activating closes its predecessor at that date, so
 * periods are half-open `[effective_from, effective_end_exclusive)` and a database exclusion constraint makes an overlap impossible. A version that has
 * been activated is immutable apart from the single closing step; it can never be deleted, so anything that cites it stays explainable. Money is not
 * stored here, so FinancialActivity does not apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_rounding_policy_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->default(1)->constrained();
            $t->unsignedInteger('version_no');
            $t->unsignedSmallInteger('unit_price_scale')->default(4);
            $t->string('rounding_mode', 12)->default('HALF_UP');
            $t->string('status', 12)->default('DRAFT');
            $t->date('effective_from')->nullable();
            $t->date('effective_end_exclusive')->nullable();
            $t->unsignedBigInteger('previous_version_id')->nullable();
            $t->text('reason');
            $t->unsignedBigInteger('changed_by');
            $t->unsignedBigInteger('activated_by')->nullable();
            $t->timestampTz('activated_at')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'id'], 'sales_rounding_policy_company_id_unique');
            $t->unique(['company_id', 'version_no'], 'sales_rounding_policy_version_unique');
            $t->foreign(['company_id', 'changed_by'], 'sales_rounding_policy_changed_by_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'activated_by'], 'sales_rounding_policy_activated_by_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'previous_version_id'], 'sales_rounding_policy_previous_fk')->references(['company_id', 'id'])->on('sales_rounding_policy_versions');
        });

        DB::statement("ALTER TABLE sales_rounding_policy_versions ADD CONSTRAINT sales_rounding_policy_shape CHECK (
            unit_price_scale BETWEEN 2 AND 4
            AND rounding_mode IN ('HALF_UP','HALF_EVEN','HALF_DOWN','UP','DOWN')
            AND status IN ('DRAFT','ACTIVE','SUPERSEDED')
            AND length(trim(reason)) > 0 AND length(reason) <= 1000
            AND (effective_end_exclusive IS NULL OR effective_from IS NULL OR effective_end_exclusive > effective_from)
            AND ((status = 'DRAFT' AND effective_from IS NULL AND effective_end_exclusive IS NULL AND activated_by IS NULL AND activated_at IS NULL)
              OR (status = 'ACTIVE' AND effective_from IS NOT NULL AND effective_end_exclusive IS NULL AND activated_by IS NOT NULL AND activated_at IS NOT NULL)
              OR (status = 'SUPERSEDED' AND effective_from IS NOT NULL AND effective_end_exclusive IS NOT NULL AND activated_by IS NOT NULL AND activated_at IS NOT NULL))
        )");
        // One open-ended head per company, and no two non-draft versions may share a day (the same device as the other versioned policies).
        DB::statement("CREATE UNIQUE INDEX sales_rounding_policy_one_active ON sales_rounding_policy_versions (company_id) WHERE status = 'ACTIVE'");
        DB::statement("ALTER TABLE sales_rounding_policy_versions ADD CONSTRAINT sales_rounding_policy_no_overlap EXCLUDE USING gist (
            company_id WITH =, daterange(effective_from, effective_end_exclusive, '[)') WITH &&
        ) WHERE (status <> 'DRAFT')");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_rounding_policy_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'DRAFT' THEN RAISE EXCEPTION 'A rounding policy version is created as a draft and then activated' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF OLD.status = 'DRAFT' THEN
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  IF NEW.status = 'SUPERSEDED' THEN RAISE EXCEPTION 'A draft cannot be superseded' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'An activated rounding policy version is history and cannot be deleted' USING ERRCODE = '23514'; END IF;
 IF ROW(NEW.company_id, NEW.version_no, NEW.unit_price_scale, NEW.rounding_mode, NEW.effective_from, NEW.previous_version_id, NEW.reason, NEW.changed_by, NEW.activated_by, NEW.activated_at)
    IS DISTINCT FROM ROW(OLD.company_id, OLD.version_no, OLD.unit_price_scale, OLD.rounding_mode, OLD.effective_from, OLD.previous_version_id, OLD.reason, OLD.changed_by, OLD.activated_by, OLD.activated_at) THEN
  RAISE EXCEPTION 'An activated rounding policy version is immutable; create a successor' USING ERRCODE = '23514';
 END IF;
 IF NEW.status IS DISTINCT FROM OLD.status OR NEW.effective_end_exclusive IS DISTINCT FROM OLD.effective_end_exclusive THEN
  IF NOT (OLD.status = 'ACTIVE' AND NEW.status = 'SUPERSEDED' AND OLD.effective_end_exclusive IS NULL AND NEW.effective_end_exclusive IS NOT NULL) THEN
   RAISE EXCEPTION 'An activated rounding policy version can only be closed by its successor' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_rounding_policy_guard BEFORE INSERT OR UPDATE OR DELETE ON sales_rounding_policy_versions FOR EACH ROW EXECUTE FUNCTION sales_rounding_policy_guard();

CREATE OR REPLACE FUNCTION sales_rounding_policy_check_successor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 -- A version is closed only by its successor: some version names it as its predecessor and takes effect exactly where it ends. Checked at commit, so
 -- the activation can close the predecessor before it marks the successor active.
 IF NEW.status = 'SUPERSEDED' AND NOT EXISTS (
   SELECT 1 FROM sales_rounding_policy_versions s
   WHERE s.company_id = NEW.company_id AND s.previous_version_id = NEW.id AND s.effective_from = NEW.effective_end_exclusive AND s.status IN ('ACTIVE', 'SUPERSEDED')) THEN
  RAISE EXCEPTION 'A rounding policy version is closed only by its successor' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER sales_rounding_policy_successor AFTER INSERT OR UPDATE ON sales_rounding_policy_versions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sales_rounding_policy_check_successor();
SQL);
    }

    public function down(): void
    {
        // Refuse rather than discard history: dropping the table would erase the policy any issued evidence cites.
        if (Schema::hasTable('sales_rounding_policy_versions') && DB::table('sales_rounding_policy_versions')->where('status', '<>', 'DRAFT')->exists()) {
            throw new RuntimeException('Activated Sales rounding policy versions exist; rolling this migration back would erase policy history. Plan a backout first.');
        }
        DB::statement('DROP TRIGGER IF EXISTS sales_rounding_policy_successor ON sales_rounding_policy_versions');
        DB::statement('DROP FUNCTION IF EXISTS sales_rounding_policy_check_successor()');
        DB::statement('DROP TRIGGER IF EXISTS sales_rounding_policy_guard ON sales_rounding_policy_versions');
        DB::statement('DROP FUNCTION IF EXISTS sales_rounding_policy_guard()');
        Schema::dropIfExists('sales_rounding_policy_versions');
    }
};
