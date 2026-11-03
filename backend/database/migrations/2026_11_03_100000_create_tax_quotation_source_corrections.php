<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, FR2a/FR3 follow-up: a way to CORRECT a quotation tax source fact without mutating evidence.
 *
 * A jurisdiction or an Item classification fact (`tax_quotation_jurisdictions`, `tax_quotation_item_classifications`) is append-only and its period cannot overlap another fact's,
 * so a wrong fact could never be put right. A correction is a new immutable VERSION that supersedes the fact it names for the fact's own period: version 1 corrects the original
 * fact, version n+1 supersedes version n (the predecessor is the latest version, enforced under the Tax generation lock and by the unique version number). The reader uses the
 * latest version for the period; the original and every earlier version stay as evidence. Each correction needs a reason, an actor and a time, bumps the Tax generation (so a
 * determination read before it is provably stale) and rejects UPDATE, DELETE and TRUNCATE. Every reference is a composite company foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_quotation_source_corrections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('jurisdiction_fact_id')->nullable();
            $t->unsignedBigInteger('classification_fact_id')->nullable();
            $t->unsignedInteger('version_no');
            $t->unsignedBigInteger('predecessor_id')->nullable();
            $t->string('jurisdiction', 80)->nullable();
            $t->string('nature', 10)->nullable();
            $t->string('classification', 80)->nullable();
            $t->text('reason');
            $t->unsignedBigInteger('created_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id'], 'tax_quotation_source_corrections_company_id_unique');
            $t->foreign(['company_id', 'jurisdiction_fact_id'], 'tax_quote_correction_jurisdiction_fk')->references(['company_id', 'id'])->on('tax_quotation_jurisdictions');
            $t->foreign(['company_id', 'classification_fact_id'], 'tax_quote_correction_classification_fk')->references(['company_id', 'id'])->on('tax_quotation_item_classifications');
            $t->foreign(['company_id', 'predecessor_id'], 'tax_quote_correction_predecessor_fk')->references(['company_id', 'id'])->on('tax_quotation_source_corrections');
            $t->foreign(['company_id', 'created_by'], 'tax_quote_correction_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE tax_quotation_source_corrections ADD CONSTRAINT tax_quote_correction_shape CHECK (
            (jurisdiction_fact_id IS NULL) <> (classification_fact_id IS NULL)
            AND version_no >= 1 AND (version_no = 1) = (predecessor_id IS NULL)
            AND length(trim(reason)) > 0
            AND ((jurisdiction_fact_id IS NOT NULL AND length(trim(coalesce(jurisdiction, ''))) > 0 AND nature IS NULL AND classification IS NULL)
              OR (classification_fact_id IS NOT NULL AND jurisdiction IS NULL AND nature IN ('GOODS','SERVICES') AND length(trim(coalesce(classification, ''))) > 0))
        )");
        DB::statement('CREATE UNIQUE INDEX tax_quote_correction_jurisdiction_version ON tax_quotation_source_corrections (company_id, jurisdiction_fact_id, version_no) WHERE jurisdiction_fact_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX tax_quote_correction_classification_version ON tax_quotation_source_corrections (company_id, classification_fact_id, version_no) WHERE classification_fact_id IS NOT NULL');
        // The same generation bump and append-only guards the source facts themselves use (functions created by 2026_10_31_130000).
        DB::statement('CREATE TRIGGER tax_quote_source_write BEFORE INSERT ON tax_quotation_source_corrections FOR EACH ROW EXECUTE FUNCTION tax_quote_source_write()');
        DB::statement('CREATE TRIGGER tax_quote_source_immutable BEFORE UPDATE OR DELETE ON tax_quotation_source_corrections FOR EACH ROW EXECUTE FUNCTION tax_quote_source_immutable()');
        DB::statement('CREATE TRIGGER tax_quote_source_no_truncate BEFORE TRUNCATE ON tax_quotation_source_corrections FOR EACH STATEMENT EXECUTE FUNCTION tax_quote_source_immutable()');
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE tax_quotation_source_corrections IN ACCESS EXCLUSIVE MODE');
        if (DB::table('tax_quotation_source_corrections')->exists()) {
            throw new RuntimeException('Tax quotation source corrections exist; rolling back would erase evidence.');
        }
        Schema::dropIfExists('tax_quotation_source_corrections');
    }
};
