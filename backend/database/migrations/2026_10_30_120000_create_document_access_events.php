<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, DF4 (document-print-framework slice DF4, DOC4 evidence): who was handed the bytes of which stored document, and when a hand-off was refused because the object was
 * not available. Insert-only history on the database clock; it records the hand-off only. A download or print is NEVER evidence that the customer received, read or
 * accepted anything — that stays the Sales owner's separate manual-delivery evidence.
 *
 * No payload, hash, key, path or URL is stored; tenancy is by composite keys. No route, grant or duty accompanies this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('artefact_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('access_kind', 12);
            $t->string('outcome', 12);
            $t->timestampTz('occurred_at', 6);
            $t->unique(['company_id', 'id'], 'document_access_events_company_id_unique');
            $t->foreign(['company_id', 'artefact_id'], 'document_access_events_artefact_fk')->references(['company_id', 'id'])->on('document_artefacts');
            $t->foreign(['company_id', 'actor_id'], 'document_access_events_actor_fk')->references(['company_id', 'id'])->on('users');
            $t->index(['company_id', 'artefact_id', 'id'], 'document_access_events_by_artefact');
        });
        DB::statement("ALTER TABLE document_access_events ADD CONSTRAINT document_access_events_shape CHECK (access_kind IN ('DOWNLOAD','PRINT') AND outcome IN ('DELIVERED','UNAVAILABLE'))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER document_access_events_guard BEFORE UPDATE OR DELETE ON document_access_events FOR EACH ROW EXECUTE FUNCTION documents_append_only_guard();
CREATE TRIGGER document_access_events_truncate_guard BEFORE TRUNCATE ON document_access_events FOR EACH STATEMENT EXECUTE FUNCTION documents_append_only_guard();

-- The time is the database's, whatever the writer supplied.
CREATE OR REPLACE FUNCTION document_access_event_stamp() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 NEW.occurred_at := clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER document_access_event_stamp BEFORE INSERT ON document_access_events FOR EACH ROW EXECUTE FUNCTION document_access_event_stamp();
SQL);
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drops.
        DB::statement('LOCK TABLE document_access_events IN ACCESS EXCLUSIVE MODE');
        if (DB::table('document_access_events')->exists()) {
            throw new RuntimeException('Document access events exist; rolling this migration back would erase hand-off evidence. Plan a backout first.');
        }
        Schema::dropIfExists('document_access_events');
        DB::statement('DROP FUNCTION IF EXISTS document_access_event_stamp()');
    }
};
