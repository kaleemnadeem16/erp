<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database integrity (issue #99): a customer-decision receipt names the case it belongs to, but only as a plain number, so the database accepted a receipt for a case that does not
 * exist, or one that belongs to another company. An insert guard now refuses it, taking the same share lock on the case row that a foreign key would.
 *
 * It is a guard and not a foreign key on purpose: the receipts sit outside the `customer_decision_*` storage so that the intake foundation can still be rolled back on its own (a
 * foreign key would make that rollback fail while receipts exist). Rows written before this migration are not re-judged; any that name no case are logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_customer_decision_receipt_case_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM 1 FROM customer_decision_cases WHERE company_id = NEW.company_id AND id = NEW.case_id FOR KEY SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'A customer decision receipt must name a case of its own company' USING ERRCODE = '23503'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_customer_decision_receipts_case BEFORE INSERT ON sales_customer_decision_receipts FOR EACH ROW EXECUTE FUNCTION sales_customer_decision_receipt_case_guard();
SQL);
        $orphans = (int) DB::selectOne('SELECT count(*) AS n FROM sales_customer_decision_receipts r WHERE NOT EXISTS (SELECT 1 FROM customer_decision_cases c WHERE c.company_id = r.company_id AND c.id = r.case_id)')->n;
        if ($orphans > 0) {
            Log::warning("sales_customer_decision_receipts: {$orphans} existing receipt(s) name no case; the rule applies to new receipts only. Review them.");
        }
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE sales_customer_decision_receipts IN ACCESS EXCLUSIVE MODE');
        DB::statement('DROP TRIGGER IF EXISTS sales_customer_decision_receipts_case ON sales_customer_decision_receipts');
        DB::statement('DROP FUNCTION IF EXISTS sales_customer_decision_receipt_case_guard()');
    }
};
