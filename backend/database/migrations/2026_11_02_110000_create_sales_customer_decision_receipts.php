<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CA1: the operation receipts of the customer-decision Actions (D2: the same convention as the quotation receipts — one per company, actor and key, a hash of the intent, a
 * versioned result — but keyed to a decision case, which is not tied to one quotation family). Append-only. `case_id` is a plain reference, not a foreign key: this table sits outside the
 * `customer_decision_*` storage so that the intake foundation can be rolled back on its own; the Action only ever writes the id of a case it has just locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_customer_decision_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('actor_id');
            $t->string('request_key', 85);
            $t->string('operation', 40);
            $t->unsignedBigInteger('case_id');
            $t->char('payload_hash', 64);
            $t->timestampTz('completed_at', 6);
            $t->jsonb('result');
            $t->unique(['company_id', 'actor_id', 'request_key'], 'sales_customer_decision_receipts_key_unique');
            $t->foreign(['company_id', 'actor_id'], 'sales_customer_decision_receipts_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE sales_customer_decision_receipts ADD CONSTRAINT sales_customer_decision_receipts_shape CHECK (
    length(trim(request_key)) > 0 AND length(trim(operation)) > 0 AND payload_hash ~ '^[0-9a-f]{64}$'
);
CREATE OR REPLACE FUNCTION sales_customer_decision_receipts_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Customer decision receipts are append-only' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER sales_customer_decision_receipts_guard BEFORE UPDATE OR DELETE ON sales_customer_decision_receipts FOR EACH ROW EXECUTE FUNCTION sales_customer_decision_receipts_append_only();
CREATE TRIGGER sales_customer_decision_receipts_truncate_guard BEFORE TRUNCATE ON sales_customer_decision_receipts FOR EACH STATEMENT EXECUTE FUNCTION sales_customer_decision_receipts_append_only();
SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE sales_customer_decision_receipts IN ACCESS EXCLUSIVE MODE');
        if (DB::table('sales_customer_decision_receipts')->exists()) {
            throw new RuntimeException('Customer decision receipts exist: they are the record of what was done. Plan a separately approved backout first.');
        }
        Schema::dropIfExists('sales_customer_decision_receipts');
        DB::unprepared('DROP FUNCTION IF EXISTS sales_customer_decision_receipts_append_only();');
    }
};
