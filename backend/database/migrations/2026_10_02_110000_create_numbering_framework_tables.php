<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The central document-numbering framework (ADR 0079): series of governed template versions, counters, routing rules, an
 * append-only allocation log, counter adjustments and company constants. Every table is company-scoped, with the company
 * participating in each foreign key and unique index.
 *
 * The data step turns each existing `sequences` row for purchase orders and goods receipts into a series DEFAULT whose version 1
 * is `prefix + {SEQ:padding}`, GAPLESS (what those numbers were produced under), with a locked counter carrying the same
 * next number — so the next number issued is exactly the one that would have been. The `sequences` table itself is left in
 * place, unused by those two documents, so a rollback of the code finds it intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('doc_type', 50);
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_selectable')->default(false);
            $table->string('status', 10)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'doc_type', 'code']);
        });
        DB::statement("ALTER TABLE numbering_series ADD CONSTRAINT numbering_series_status_check CHECK (status IN ('ACTIVE', 'RETIRED'))");
        DB::statement("ALTER TABLE numbering_series ADD CONSTRAINT numbering_series_default_active_check CHECK (NOT is_default OR status = 'ACTIVE')");
        DB::statement('CREATE UNIQUE INDEX numbering_series_one_default ON numbering_series (company_id, doc_type) WHERE is_default');

        Schema::create('numbering_series_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('series_id');
            $table->string('template');
            $table->string('scope_period', 20);
            $table->jsonb('scope_dimensions')->default('[]');
            $table->string('continuity', 12);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->string('status', 12)->default('ACTIVE');
            $table->text('reason');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
        });
        DB::statement('ALTER TABLE numbering_series_versions ADD CONSTRAINT numbering_series_versions_series_fk FOREIGN KEY (company_id, series_id) REFERENCES numbering_series (company_id, id)');
        DB::statement("ALTER TABLE numbering_series_versions ADD CONSTRAINT numbering_series_versions_scope_check CHECK (scope_period IN ('NONE', 'YEAR', 'MONTH', 'DAY', 'FISCAL_YEAR', 'FISCAL_PERIOD'))");
        DB::statement("ALTER TABLE numbering_series_versions ADD CONSTRAINT numbering_series_versions_continuity_check CHECK (continuity IN ('GAPLESS', 'ALLOW_GAPS'))");
        DB::statement("ALTER TABLE numbering_series_versions ADD CONSTRAINT numbering_series_versions_status_check CHECK (status IN ('ACTIVE', 'SUPERSEDED') AND ((status = 'ACTIVE') = (effective_to IS NULL)))");
        DB::statement('ALTER TABLE numbering_series_versions ADD CONSTRAINT numbering_series_versions_changed_by_company_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');
        DB::statement("CREATE UNIQUE INDEX numbering_series_versions_one_active ON numbering_series_versions (company_id, series_id) WHERE status = 'ACTIVE'");

        // A routing rule picks this series for a document whose routing attributes satisfy every condition:
        // conditions = [{"attribute": "ORDER_TYPE", "operator": "EQ"|"IN", "values": ["IMPORT"]}], first match by priority wins.
        Schema::create('numbering_series_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('series_id');
            $table->unsignedSmallInteger('priority');
            $table->jsonb('conditions');
            $table->string('status', 10)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
        });
        DB::statement('ALTER TABLE numbering_series_rules ADD CONSTRAINT numbering_series_rules_series_fk FOREIGN KEY (company_id, series_id) REFERENCES numbering_series (company_id, id)');
        DB::statement("ALTER TABLE numbering_series_rules ADD CONSTRAINT numbering_series_rules_status_check CHECK (status IN ('ACTIVE', 'RETIRED'))");

        // One running number per (series, counter key). LOCKED counters keep next_number and are advanced under a row lock inside the
        // document's transaction (GAPLESS); SEQUENCE counters own a PostgreSQL sequence, whose nextval is not transactional (ALLOW_GAPS).
        Schema::create('numbering_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('series_id');
            $table->string('counter_key', 200);
            $table->string('allocator', 10);
            $table->unsignedBigInteger('next_number')->nullable();
            $table->string('sequence_name', 63)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'series_id', 'counter_key']);
        });
        DB::statement('ALTER TABLE numbering_counters ADD CONSTRAINT numbering_counters_series_fk FOREIGN KEY (company_id, series_id) REFERENCES numbering_series (company_id, id)');
        DB::statement("ALTER TABLE numbering_counters ADD CONSTRAINT numbering_counters_allocator_check CHECK ((allocator = 'LOCKED' AND next_number IS NOT NULL AND next_number >= 1 AND sequence_name IS NULL) OR (allocator = 'SEQUENCE' AND next_number IS NULL AND sequence_name IS NOT NULL))");

        Schema::create('numbering_counter_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('counter_id');
            $table->unsignedBigInteger('from_value');
            $table->unsignedBigInteger('to_value');
            $table->text('reason');
            $table->unsignedBigInteger('changed_by');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE numbering_counter_adjustments ADD CONSTRAINT numbering_counter_adjustments_counter_fk FOREIGN KEY (company_id, counter_id) REFERENCES numbering_counters (company_id, id)');
        DB::statement('ALTER TABLE numbering_counter_adjustments ADD CONSTRAINT numbering_counter_adjustments_changed_by_company_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');
        // A counter only ever goes up (ADR 0079 §16).
        DB::statement('ALTER TABLE numbering_counter_adjustments ADD CONSTRAINT numbering_counter_adjustments_raise_only_check CHECK (to_value > from_value)');

        // The permanent record of every number issued: frozen, append-only.
        Schema::create('numbering_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('doc_type', 50);
            $table->unsignedBigInteger('series_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('number', 100);
            $table->string('counter_key', 200);
            $table->unsignedBigInteger('counter_value');
            $table->timestampTz('issued_at');
            $table->date('local_date')->nullable();
            $table->jsonb('variables');
            $table->unsignedBigInteger('allocated_by')->nullable();
            $table->timestamps();

            // The uniqueness boundary (ADR 0079 §7): a number is unique within a company's document type, across every series.
            $table->unique(['company_id', 'doc_type', 'number']);
        });
        DB::statement('ALTER TABLE numbering_allocations ADD CONSTRAINT numbering_allocations_series_fk FOREIGN KEY (company_id, series_id) REFERENCES numbering_series (company_id, id)');
        DB::statement('ALTER TABLE numbering_allocations ADD CONSTRAINT numbering_allocations_version_fk FOREIGN KEY (company_id, version_id) REFERENCES numbering_series_versions (company_id, id)');
        DB::statement('ALTER TABLE numbering_allocations ADD CONSTRAINT numbering_allocations_allocated_by_company_fk FOREIGN KEY (company_id, allocated_by) REFERENCES users (company_id, id)');
        DB::statement('CREATE INDEX numbering_allocations_document_index ON numbering_allocations (company_id, doc_type, document_id)');

        // Company-defined constants, a governed history: a row per change; value NULL = retired.
        Schema::create('numbering_constants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('code', 20);
            $table->string('value', 20)->nullable();
            $table->string('status', 12)->default('ACTIVE');
            $table->text('reason');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->unsignedBigInteger('changed_by');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE numbering_constants ADD CONSTRAINT numbering_constants_status_check CHECK (status IN ('ACTIVE', 'SUPERSEDED') AND ((status = 'ACTIVE') = (effective_to IS NULL)))");
        DB::statement('ALTER TABLE numbering_constants ADD CONSTRAINT numbering_constants_changed_by_company_fk FOREIGN KEY (company_id, changed_by) REFERENCES users (company_id, id)');
        DB::statement("CREATE UNIQUE INDEX numbering_constants_one_active ON numbering_constants (company_id, code) WHERE status = 'ACTIVE'");

        $this->migrateExistingSequences();
    }

    /** Purchase orders and goods receipts keep exactly the numbering they had: same prefix, padding and next number, still gapless. */
    private function migrateExistingSequences(): void
    {
        $types = ['purchase_order' => 'PURCHASE_ORDER', 'goods_receipt' => 'GOODS_RECEIPT'];
        $rows = DB::table('sequences')->whereIn('doc_type', array_keys($types))->get();

        foreach ($rows as $row) {
            $prefix = (string) ($row->prefix ?? '');
            if (! preg_match('/^[A-Za-z0-9\-_\/.]{0,30}$/', $prefix)) {
                throw new RuntimeException("Cannot migrate the numbering prefix '{$prefix}' of {$row->doc_type}: it contains characters document numbering does not allow.");
            }
            $now = now();
            $seriesId = DB::table('numbering_series')->insertGetId([
                'company_id' => $row->company_id, 'doc_type' => $types[$row->doc_type], 'code' => 'DEFAULT', 'name' => 'Default',
                'is_default' => true, 'is_selectable' => false, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('numbering_series_versions')->insert([
                'company_id' => $row->company_id, 'series_id' => $seriesId,
                'template' => $prefix.'{SEQ:'.max(1, (int) $row->padding).'}',
                'scope_period' => 'NONE', 'scope_dimensions' => '[]', 'continuity' => 'GAPLESS',
                'effective_from' => $now, 'status' => 'ACTIVE', 'reason' => 'Carried over from the previous numbering setting; numbers continue unchanged.',
                'changed_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('numbering_counters')->insert([
                'company_id' => $row->company_id, 'series_id' => $seriesId, 'counter_key' => 'ALL', 'allocator' => 'LOCKED',
                'next_number' => max(1, (int) $row->next_number), 'sequence_name' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Counters may own PostgreSQL sequences; drop them with their rows.
        foreach (DB::table('numbering_counters')->whereNotNull('sequence_name')->pluck('sequence_name') as $name) {
            DB::statement('DROP SEQUENCE IF EXISTS '.$name);
        }
        Schema::dropIfExists('numbering_constants');
        Schema::dropIfExists('numbering_allocations');
        Schema::dropIfExists('numbering_counter_adjustments');
        Schema::dropIfExists('numbering_counters');
        Schema::dropIfExists('numbering_series_rules');
        Schema::dropIfExists('numbering_series_versions');
        Schema::dropIfExists('numbering_series');
    }
};
