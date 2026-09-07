<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('entity_type');
            $table->string('name');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'entity_type', 'name']);
        });

        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_profile_id')->constrained()->cascadeOnDelete();
            $table->string('source_column');
            $table->string('target_field');
            $table->jsonb('transform')->nullable();
            $table->timestamps();
            $table->unique(['import_profile_id', 'source_column']);
            $table->unique(['import_profile_id', 'target_field']);
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('import_profile_id');
            $table->string('purpose');
            $table->string('entity_type');
            $table->string('external_batch_id');
            $table->char('source_sha256', 64);
            $table->string('file_name');
            $table->string('status')->default('STAGED');
            $table->unsignedBigInteger('uploaded_by');
            $table->timestampTz('uploaded_at');
            $table->timestampTz('validated_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'purpose', 'external_batch_id']);
            $table->index(['company_id', 'purpose', 'status']);
        });

        Schema::create('import_batch_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->jsonb('raw_data');
            $table->jsonb('mapped_data');
            $table->string('status')->default('PENDING');
            $table->jsonb('errors')->nullable();
            $table->timestamps();
            $table->unique(['import_batch_id', 'row_number']);
        });

        DB::statement('ALTER TABLE import_batches ADD CONSTRAINT ib_profile_company_fk FOREIGN KEY (company_id, import_profile_id) REFERENCES import_profiles (company_id, id)');
        DB::statement('ALTER TABLE import_batches ADD CONSTRAINT ib_actor_company_fk FOREIGN KEY (company_id, uploaded_by) REFERENCES users (company_id, id)');
        DB::statement("ALTER TABLE import_profiles ADD CONSTRAINT ip_text_check CHECK (btrim(entity_type) <> '' AND btrim(name) <> '')");
        DB::statement("ALTER TABLE import_mappings ADD CONSTRAINT im_text_check CHECK (btrim(source_column) <> '' AND btrim(target_field) <> '')");
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT ib_purpose_check CHECK (purpose IN ('STANDARD', 'INVENTORY_CUTOVER', 'FINANCE_OPENING'))");
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT ib_status_check CHECK (status IN ('STAGED', 'VALIDATED', 'COMMITTED', 'FAILED'))");
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT ib_identity_check CHECK (btrim(entity_type) <> '' AND btrim(external_batch_id) <> '' AND btrim(file_name) <> '')");
        DB::statement("ALTER TABLE import_batch_rows ADD CONSTRAINT ibr_status_check CHECK (status IN ('PENDING', 'VALID', 'INVALID', 'COMMITTED'))");
        DB::statement("ALTER TABLE import_batch_rows ADD CONSTRAINT ibr_errors_shape_check CHECK ((status = 'INVALID') = (errors IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_import_batch_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'import batches cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF NEW.company_id <> OLD.company_id
                   OR NEW.import_profile_id <> OLD.import_profile_id
                   OR NEW.purpose <> OLD.purpose
                   OR NEW.entity_type <> OLD.entity_type
                   OR NEW.external_batch_id <> OLD.external_batch_id
                   OR NEW.source_sha256 <> OLD.source_sha256
                   OR NEW.file_name <> OLD.file_name
                   OR NEW.uploaded_by <> OLD.uploaded_by
                   OR NEW.uploaded_at <> OLD.uploaded_at
                THEN RAISE EXCEPTION 'import batch source facts are immutable' USING ERRCODE = '23514'; END IF;
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'STAGED' AND NEW.status IN ('VALIDATED', 'FAILED'))
                    OR (OLD.status = 'VALIDATED' AND NEW.status IN ('COMMITTED', 'FAILED'))
                ) THEN RAISE EXCEPTION 'invalid import batch status transition' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER import_batches_guard BEFORE UPDATE OR DELETE ON import_batches
                FOR EACH ROW EXECUTE FUNCTION guard_import_batch_mutation();

            CREATE OR REPLACE FUNCTION guard_import_batch_row_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'staged import rows cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF NEW.import_batch_id <> OLD.import_batch_id
                   OR NEW.row_number <> OLD.row_number
                   OR NEW.raw_data <> OLD.raw_data
                   OR NEW.mapped_data <> OLD.mapped_data
                THEN RAISE EXCEPTION 'staged import source and mapped data are immutable' USING ERRCODE = '23514'; END IF;
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'PENDING' AND NEW.status IN ('VALID', 'INVALID'))
                    OR (OLD.status = 'VALID' AND NEW.status = 'COMMITTED')
                ) THEN RAISE EXCEPTION 'invalid import row status transition' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER import_batch_rows_guard BEFORE UPDATE OR DELETE ON import_batch_rows
                FOR EACH ROW EXECUTE FUNCTION guard_import_batch_row_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS guard_import_batch_row_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS guard_import_batch_mutation() CASCADE');
        Schema::dropIfExists('import_batch_rows');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('import_mappings');
        Schema::dropIfExists('import_profiles');
    }
};
