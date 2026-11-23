<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-phase integration: ADR 0056 classifies Assembly work by its own explicit classification, not by the sum of its components. This is that explicit fact, a sibling of
 * `tax_quotation_item_classifications` with the same shape, immutability, effective-period exclusion and Tax-generation bump, so a quotation Assembly line can be classified for
 * Tax at issue. ADDITIVE: one table (plus a composite unique key on `assemblies` so the fact is company-scoped by the database); nothing existing changes.
 * Corrections as later versions (the Item fact's `tax_quotation_source_corrections`) are not extended to Assembly facts here. down() refuses while a fact exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assemblies', fn (Blueprint $t) => $t->unique(['company_id', 'id'], 'assemblies_company_id_id_unique'));
        Schema::create('tax_quotation_assembly_classifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('assembly_id');
            $t->string('nature', 10);
            $t->string('classification', 80);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'assembly_id'])->references(['company_id', 'id'])->on('assemblies');
            $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE tax_quotation_assembly_classifications ADD CONSTRAINT tax_quote_assembly_class_shape CHECK (nature IN ('GOODS','SERVICES') AND length(trim(classification)) > 0 AND length(trim(reason)) > 0 AND (effective_to IS NULL OR effective_to > effective_from))");
        DB::statement("ALTER TABLE tax_quotation_assembly_classifications ADD CONSTRAINT tax_quote_assembly_class_no_overlap EXCLUDE USING gist (company_id WITH =, assembly_id WITH =, daterange(effective_from,effective_to,'[)') WITH &&)");
        DB::statement('CREATE TRIGGER tax_quote_source_write BEFORE INSERT ON tax_quotation_assembly_classifications FOR EACH ROW EXECUTE FUNCTION tax_quote_source_write()');
        DB::statement('CREATE TRIGGER tax_quote_source_immutable BEFORE UPDATE OR DELETE ON tax_quotation_assembly_classifications FOR EACH ROW EXECUTE FUNCTION tax_quote_source_immutable()');
        DB::statement('CREATE TRIGGER tax_quote_source_no_truncate BEFORE TRUNCATE ON tax_quotation_assembly_classifications FOR EACH STATEMENT EXECUTE FUNCTION tax_quote_source_immutable()');
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE tax_quotation_assembly_classifications IN ACCESS EXCLUSIVE MODE');
        if (DB::table('tax_quotation_assembly_classifications')->exists()) {
            throw new RuntimeException('Tax Assembly classification facts exist; rolling back would erase evidence.');
        }
        Schema::dropIfExists('tax_quotation_assembly_classifications');
        Schema::table('assemblies', fn (Blueprint $t) => $t->dropUnique('assemblies_company_id_id_unique'));
    }
};
