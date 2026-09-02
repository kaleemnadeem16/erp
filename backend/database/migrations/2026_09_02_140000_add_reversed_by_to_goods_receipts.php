<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ADR 0043 names "actor" as a fact a receipt reversal must record, and nothing
        // on this table captured it yet — the same gap quality_dispositions.disposed_by
        // closed for QA. users_company_id_id_unique already exists (added by
        // create_quality_dispositions_table), so no further users-side change is needed.
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
        });

        DB::statement('ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_reversed_by_company_fk FOREIGN KEY (company_id, reversed_by) REFERENCES users (company_id, id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE goods_receipts DROP CONSTRAINT goods_receipts_reversed_by_company_fk');
        Schema::table('goods_receipts', fn (Blueprint $table) => $table->dropColumn('reversed_by'));
    }
};
