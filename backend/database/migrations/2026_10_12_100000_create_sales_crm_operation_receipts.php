<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM corrections (ADRs 0111–0114): one module-owned receipt per governed operation, keyed by company, actor and a caller-chosen key.
 * The same key with the same intent replays the original result; the same key with a different intent is refused. A receipt records
 * which operation, which single subject (a lead or an opportunity), a hash of the normalised intent and a versioned result reference —
 * never the raw request. Receipts are append-only: the database refuses updates and deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_crm_operation_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('actor_id');
            $t->string('request_key', 85);
            $t->string('operation', 40);
            $t->unsignedBigInteger('lead_id')->nullable();
            $t->unsignedBigInteger('opportunity_id')->nullable();
            $t->char('payload_hash', 64);
            $t->timestampTz('completed_at');
            $t->jsonb('result');
            $t->unique(['company_id', 'actor_id', 'request_key']);
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'actor_id'])->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'lead_id'])->references(['company_id', 'id'])->on('sales_leads');
            $t->foreign(['company_id', 'opportunity_id'])->references(['company_id', 'id'])->on('sales_opportunities');
            $t->index(['company_id', 'lead_id']);
            $t->index(['company_id', 'opportunity_id']);
        });
        DB::statement("ALTER TABLE sales_crm_operation_receipts ADD CONSTRAINT sales_crm_operation_receipts_shape CHECK (
            ((lead_id IS NULL) <> (opportunity_id IS NULL))
            AND length(trim(request_key))>0 AND length(trim(operation))>0
            AND payload_hash ~ '^[0-9a-f]{64}$'
        )");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_receipts_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Sales operation receipts are append-only';
END $$;
CREATE TRIGGER sales_crm_operation_receipts_append_only BEFORE UPDATE OR DELETE ON sales_crm_operation_receipts FOR EACH ROW EXECUTE FUNCTION sales_receipts_append_only();
SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_crm_operation_receipts') && DB::table('sales_crm_operation_receipts')->exists()) {
            throw new RuntimeException('Refusing to drop sales_crm_operation_receipts: it holds receipts that make accepted CRM operations replayable and auditable.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS sales_crm_operation_receipts_append_only ON sales_crm_operation_receipts');
        Schema::dropIfExists('sales_crm_operation_receipts');
        DB::unprepared('DROP FUNCTION IF EXISTS sales_receipts_append_only()');
    }
};
