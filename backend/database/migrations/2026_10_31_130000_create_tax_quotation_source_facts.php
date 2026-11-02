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
        Schema::create('tax_quotation_jurisdictions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('jurisdiction', 80);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users');
        });
        Schema::create('tax_quotation_item_classifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('item_id');
            $t->string('nature', 10);
            $t->string('classification', 80);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items');
            $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement('ALTER TABLE tax_quotation_jurisdictions ADD CONSTRAINT tax_quote_jurisdiction_shape CHECK (length(trim(jurisdiction)) > 0 AND length(trim(reason)) > 0 AND (effective_to IS NULL OR effective_to > effective_from))');
        DB::statement("ALTER TABLE tax_quotation_item_classifications ADD CONSTRAINT tax_quote_item_class_shape CHECK (nature IN ('GOODS','SERVICES') AND length(trim(classification)) > 0 AND length(trim(reason)) > 0 AND (effective_to IS NULL OR effective_to > effective_from))");
        DB::statement("ALTER TABLE tax_quotation_jurisdictions ADD CONSTRAINT tax_quote_jurisdiction_no_overlap EXCLUDE USING gist (company_id WITH =, daterange(effective_from,effective_to,'[)') WITH &&)");
        DB::statement("ALTER TABLE tax_quotation_item_classifications ADD CONSTRAINT tax_quote_item_class_no_overlap EXCLUDE USING gist (company_id WITH =, item_id WITH =, daterange(effective_from,effective_to,'[)') WITH &&)");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tax_quote_source_write() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 INSERT INTO tax_generations(company_id,generation) VALUES (NEW.company_id,0) ON CONFLICT DO NOTHING;
 UPDATE tax_generations SET generation=generation+1 WHERE company_id=NEW.company_id;
 NEW.created_at := now();
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION tax_quote_source_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Tax quotation source facts are append-only' USING ERRCODE = '23514';
END $$;
SQL);
        foreach (['tax_quotation_jurisdictions', 'tax_quotation_item_classifications'] as $table) {
            DB::statement("CREATE TRIGGER tax_quote_source_write BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION tax_quote_source_write()");
            DB::statement("CREATE TRIGGER tax_quote_source_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION tax_quote_source_immutable()");
            DB::statement("CREATE TRIGGER tax_quote_source_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION tax_quote_source_immutable()");
        }
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE tax_quotation_jurisdictions, tax_quotation_item_classifications IN ACCESS EXCLUSIVE MODE');
        if (DB::table('tax_quotation_jurisdictions')->exists() || DB::table('tax_quotation_item_classifications')->exists()) {
            throw new RuntimeException('Tax quotation source facts exist; rolling back would erase evidence.');
        }
        Schema::dropIfExists('tax_quotation_item_classifications');
        Schema::dropIfExists('tax_quotation_jurisdictions');
        DB::statement('DROP FUNCTION tax_quote_source_write()');
        DB::statement('DROP FUNCTION tax_quote_source_immutable()');
    }
};
