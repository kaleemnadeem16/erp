<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 2 of docs/phases/phase-3-estimating-core.md's Assembly plan — the
 * overhead-policy resolver, per ADR 0049 (superseding ADR 0048). See
 * docs/domain/assemblies.md "Overhead allocation" for the schema this
 * mirrors exactly.
 *
 * scope_id is deliberately untyped at the database level (no FK) — it
 * resolves against assembly_groups or assemblies depending on scope_type,
 * validated at the application layer (see StoreOverheadPolicyRequest),
 * same as ADR 0048 always intended this "morphTo-style" column to work.
 * scope_id is NULL iff scope_type = COMPANY — there is nothing else to
 * point at for a company-wide default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overhead_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('driver_type');
            $table->decimal('rate', 19, 6);
            $table->text('reason');
            $table->string('status')->default('DRAFT');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('changed_by');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE overhead_policies ADD CONSTRAINT overhead_policies_rate_check CHECK (rate >= 0)');
        DB::statement('ALTER TABLE overhead_policies ADD CONSTRAINT overhead_policies_changed_by_company_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');

        // COALESCE(scope_id, 0) treats every COMPANY-scope row (scope_id NULL)
        // as sharing one identity for uniqueness purposes — Postgres unique
        // indexes otherwise treat every NULL as distinct from every other
        // NULL, which would silently allow more than one ACTIVE company
        // default. 0 is never a real id (Postgres ids start at 1), so it
        // can't collide with a genuine assembly_group/assembly id.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX overhead_policies_one_active
            ON overhead_policies (company_id, scope_type, COALESCE(scope_id, 0))
            WHERE status = 'ACTIVE'
            SQL);

        // Same COALESCE trick, applied to the version-period exclusion
        // constraint described in docs/architecture/database.md "Exclusion
        // constraints for version periods" — this table follows
        // formulation_versions' pattern exactly. DRAFT rows carry no
        // effective_from yet and are excluded via the WHERE clause.
        DB::statement(<<<'SQL'
            ALTER TABLE overhead_policies ADD CONSTRAINT overhead_policies_no_overlap
            EXCLUDE USING gist (
                company_id WITH =,
                scope_type WITH =,
                COALESCE(scope_id, 0) WITH =,
                daterange(effective_from, effective_to, '[)') WITH &&
            ) WHERE (status <> 'DRAFT')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('overhead_policies');
    }
};
