<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First composite tenant reference to users — nothing needed this before.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_company_id_id_unique UNIQUE (company_id, id)');

        Schema::create('quality_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->foreignId('goods_receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->unsignedBigInteger('source_disposition_id')->nullable();
            $table->string('disposition');
            $table->decimal('qty', 19, 4);
            $table->text('reason');
            $table->unsignedBigInteger('disposed_by');
            $table->timestampTz('disposed_at');
            $table->unsignedBigInteger('resulting_stock_move_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index('goods_receipt_line_id');
            $table->index('source_disposition_id');
        });

        // company_id is on both sides of these three, so — per
        // tests/Architecture/CompanyScopedForeignKeyTest.php — the tenant column must
        // participate in the key, not just prove existence. goods_receipt_line_id is the
        // one exception: goods_receipt_lines carries no company_id of its own (see
        // enforce_goods_receipt_line_company in create_goods_receipts), so it keeps a
        // plain FK plus the trigger below.
        DB::statement('ALTER TABLE quality_dispositions ADD CONSTRAINT quality_dispositions_source_disposition_company_fk FOREIGN KEY (company_id, source_disposition_id) REFERENCES quality_dispositions (company_id, id)');
        DB::statement('ALTER TABLE quality_dispositions ADD CONSTRAINT quality_dispositions_disposed_by_company_fk FOREIGN KEY (company_id, disposed_by) REFERENCES users (company_id, id)');
        DB::statement('ALTER TABLE quality_dispositions ADD CONSTRAINT quality_dispositions_stock_move_company_fk FOREIGN KEY (company_id, resulting_stock_move_id) REFERENCES stock_moves (company_id, id)');

        DB::statement("ALTER TABLE quality_dispositions ADD CONSTRAINT quality_dispositions_disposition_check CHECK (disposition IN ('PASS', 'ACCEPT_WITH_CONCESSION', 'RETURN_TO_SUPPLIER', 'REWORK', 'SCRAP', 'PENDING_DECISION'))");
        DB::statement('ALTER TABLE quality_dispositions ADD CONSTRAINT quality_dispositions_qty_check CHECK (qty > 0)');
        DB::statement('ALTER TABLE quality_dispositions ADD CONSTRAINT quality_dispositions_no_self_source_check CHECK (source_disposition_id IS NULL OR source_disposition_id <> id)');
        DB::statement('CREATE UNIQUE INDEX quality_dispositions_company_idempotency_unique ON quality_dispositions (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        // Tenant-consistency backstop for the one reference the composite FKs above
        // can't cover: goods_receipt_lines has no company_id, so this mirrors
        // enforce_goods_receipt_line_company; it also pins source_disposition_id to the
        // same receipt line as this row (the composite FK above only proves same
        // company, not same line — there's no natural key for that).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_quality_disposition_company() RETURNS trigger AS $$
            DECLARE line_company bigint;
            DECLARE parent_line_id bigint;
            BEGIN
                SELECT gr.company_id INTO line_company
                FROM goods_receipt_lines grl JOIN goods_receipts gr ON gr.id = grl.goods_receipt_id
                WHERE grl.id = NEW.goods_receipt_line_id;
                IF line_company IS NULL OR line_company != NEW.company_id THEN
                    RAISE EXCEPTION 'quality disposition references another company goods receipt line' USING ERRCODE = '23503';
                END IF;

                IF NEW.source_disposition_id IS NOT NULL THEN
                    SELECT goods_receipt_line_id INTO parent_line_id FROM quality_dispositions WHERE id = NEW.source_disposition_id;
                    IF parent_line_id IS NULL OR parent_line_id != NEW.goods_receipt_line_id THEN
                        RAISE EXCEPTION 'quality disposition source must belong to the same receipt line' USING ERRCODE = '23503';
                    END IF;
                END IF;

                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER quality_dispositions_company BEFORE INSERT ON quality_dispositions
            FOR EACH ROW EXECUTE FUNCTION enforce_quality_disposition_company();

            CREATE OR REPLACE FUNCTION prevent_quality_disposition_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'quality dispositions are immutable and cannot be deleted' USING ERRCODE = '23503';
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER quality_dispositions_no_delete BEFORE DELETE ON quality_dispositions
            FOR EACH ROW EXECUTE FUNCTION prevent_quality_disposition_delete();

            -- A posted disposition is a ledger fact, not a row anyone should ever
            -- correct in place — not even to "fix" a company/line reference, which is
            -- why this is unconditional rather than folded into the INSERT trigger
            -- above (which still needs to let a first INSERT through). This is the
            -- real backstop behind QualityDisposition's Eloquent updating() guard,
            -- which only stops the ORM path, not DB::table(...)->update(...) or other
            -- raw SQL.
            CREATE OR REPLACE FUNCTION prevent_quality_disposition_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'quality dispositions are immutable and cannot be updated' USING ERRCODE = '23503';
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER quality_dispositions_no_update BEFORE UPDATE ON quality_dispositions
            FOR EACH ROW EXECUTE FUNCTION prevent_quality_disposition_update();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS enforce_quality_disposition_company() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS prevent_quality_disposition_delete() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS prevent_quality_disposition_update() CASCADE');
        Schema::dropIfExists('quality_dispositions');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_company_id_id_unique');
    }
};
