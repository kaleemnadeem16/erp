<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — the Sales-owned fulfilment receipt (disabled; no route, no grant). ADDITIVE; no existing guard is weakened.
 *
 * `handover_fulfilment_receipts`: ONE immutable record per released service that the exact customer-agreed deliverable and conditions were fulfilled, written in the SAME
 * transaction as the Job's COMPLETE event and completion row. It names the real release, the accepted line (through the release), the Job completion event, the agreed
 * conditions identity, the digest of the agreed deliverable text, the completion basis and the source applicability pin the completion relied on. It records no money and
 * changes no acceptance claim, allocation or accepted capacity.
 *
 *  - Business uniqueness: one receipt per release event, one per Job and one per completion event, whatever key, actor or command identity produced it.
 *  - Composite FKs bind it to the Job's own Sales origin (so the release IS the Job's release), to the Job's completion event row and to the release's line and basis.
 *  - Insert guard (the Job head is locked first, the same stable order as every Jobs writer): the event is a COMPLETE event of that Job by that actor; the conditions
 *    identity, issued line, basis and deliverable digest equal the Job's baseline component and the current issued line text; the recorded time is the event's database time.
 *  - COMMIT-time completeness (deferred constraint triggers): a COMPLETE event of a Sales-origin Job never commits without exactly its own receipt; a receipt never commits
 *    without its COMPLETE event.
 *
 * Applying refuses if a Sales-origin Job was already completed (a genuine fulfilment record cannot be back-filled); rolling back refuses while any receipt exists, checked
 * after the table is locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE job_events, job_completions, job_sales_origins IN SHARE ROW EXCLUSIVE MODE');
        if (DB::table('job_events as e')->join('job_sales_origins as o', fn ($j) => $j->on('o.company_id', 'e.company_id')->on('o.job_id', 'e.job_id'))->where('e.operation', 'COMPLETE')->exists()) {
            throw new RuntimeException('A Sales-origin Job has already been completed without a fulfilment receipt; a genuine fulfilment record cannot be back-filled. Plan a reviewed correction first.');
        }

        Schema::create('handover_fulfilment_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('job_id');
            $t->unsignedBigInteger('event_id');
            $t->unsignedBigInteger('release_event_id');
            $t->unsignedBigInteger('issued_line_id');
            $t->unsignedBigInteger('basis_id');
            $t->string('condition_identity', 120);
            $t->char('deliverable_digest', 64);
            $t->string('applicability_pin', 128)->nullable();
            $t->unsignedBigInteger('completed_by');
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['company_id', 'id'], 'handover_fulfilment_receipts_company_id_unique');
            // One fulfilment per release, per Job and per completion event.
            $t->unique(['company_id', 'release_event_id'], 'handover_fulfilment_receipts_release_unique');
            $t->unique(['company_id', 'job_id'], 'handover_fulfilment_receipts_job_unique');
            $t->unique(['company_id', 'job_id', 'event_id'], 'handover_fulfilment_receipts_event_unique');
            $t->foreign(['company_id', 'job_id', 'release_event_id'], 'handover_fulfilment_receipts_origin_fk')->references(['company_id', 'job_id', 'release_event_id'])->on('job_sales_origins');
            $t->foreign(['company_id', 'job_id', 'event_id'], 'handover_fulfilment_receipts_completion_fk')->references(['company_id', 'job_id', 'event_id'])->on('job_completions');
            $t->foreign(['company_id', 'release_event_id'], 'handover_fulfilment_receipts_release_fk')->references(['company_id', 'id'])->on('handover_release_events');
            $t->foreign(['company_id', 'issued_line_id'], 'handover_fulfilment_receipts_line_fk')->references(['company_id', 'id'])->on('quotation_issued_lines');
            $t->foreign(['company_id', 'basis_id'], 'handover_fulfilment_receipts_basis_fk')->references(['company_id', 'id'])->on('service_completion_bases');
            $t->foreign(['company_id', 'completed_by'], 'handover_fulfilment_receipts_actor_fk')->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE handover_fulfilment_receipts ADD CONSTRAINT handover_fulfilment_receipts_shape CHECK (
            length(btrim(condition_identity)) > 0 AND deliverable_digest ~ '^[0-9a-f]{64}\$' AND (applicability_pin IS NULL OR length(btrim(applicability_pin)) > 0)
        )");

        DB::statement('CREATE TRIGGER handover_fulfilment_receipts_append_only BEFORE UPDATE OR DELETE ON handover_fulfilment_receipts FOR EACH ROW EXECUTE FUNCTION customer_decision_append_only_guard()');
        DB::statement('CREATE TRIGGER handover_fulfilment_receipts_truncate BEFORE TRUNCATE ON handover_fulfilment_receipts FOR EACH STATEMENT EXECUTE FUNCTION customer_decision_append_only_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION handover_fulfilment_receipt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 ev job_events%ROWTYPE;
 jb job_baselines%ROWTYPE;
 comp job_baseline_components%ROWTYPE;
 il quotation_issued_lines%ROWTYPE;
BEGIN
 -- The Job head first: the same stable order as every Jobs writer (the lifecycle command already holds it).
 PERFORM 1 FROM service_jobs WHERE company_id = NEW.company_id AND id = NEW.job_id FOR UPDATE;
 SELECT * INTO ev FROM job_events WHERE company_id = NEW.company_id AND job_id = NEW.job_id AND id = NEW.event_id;
 IF NOT FOUND OR ev.operation <> 'COMPLETE' OR ev.actor_id <> NEW.completed_by THEN
  RAISE EXCEPTION 'A fulfilment receipt must name the COMPLETE event of its Job and the actor who completed it' USING ERRCODE = '23514';
 END IF;
 SELECT * INTO jb FROM job_baselines WHERE company_id = NEW.company_id AND job_id = NEW.job_id ORDER BY version DESC LIMIT 1;
 SELECT * INTO comp FROM job_baseline_components WHERE company_id = NEW.company_id AND job_id = NEW.job_id AND baseline_id = jb.id;
 SELECT * INTO il FROM quotation_issued_lines WHERE company_id = NEW.company_id AND id = NEW.issued_line_id;
 IF comp.id IS NULL OR il.id IS NULL OR comp.release_event_id <> NEW.release_event_id OR comp.issued_line_id <> NEW.issued_line_id OR comp.basis_id <> NEW.basis_id
  OR comp.condition_identity <> NEW.condition_identity THEN
  RAISE EXCEPTION 'A fulfilment receipt must identify the exact agreed deliverable and conditions the Job was released with' USING ERRCODE = '23514';
 END IF;
 IF NEW.deliverable_digest <> encode(sha256(convert_to(btrim(il.description, E' \t\n\r\x0B'), 'UTF8')), 'hex') THEN
  RAISE EXCEPTION 'The fulfilment receipt does not carry the digest of the agreed deliverable text' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM handover_release_events r WHERE r.company_id = NEW.company_id AND r.id = NEW.release_event_id AND r.basis_id = NEW.basis_id
   AND r.condition_identity = NEW.condition_identity) THEN
  RAISE EXCEPTION 'The fulfilment receipt disagrees with the release it fulfils' USING ERRCODE = '23514';
 END IF;
 NEW.recorded_at := ev.recorded_at;
 RETURN NEW;
END $$;
CREATE TRIGGER handover_fulfilment_receipt_guard BEFORE INSERT ON handover_fulfilment_receipts FOR EACH ROW EXECUTE FUNCTION handover_fulfilment_receipt_guard();

-- COMMIT-time completeness: a COMPLETE event of a Sales-origin Job has exactly its own receipt, and a receipt belongs to exactly one COMPLETE event.
CREATE OR REPLACE FUNCTION handover_fulfilment_consistency_v1() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 ev job_events%ROWTYPE;
 n int;
BEGIN
 IF TG_TABLE_NAME = 'job_events' THEN
  ev := NEW;
 ELSE
  SELECT * INTO ev FROM job_events WHERE company_id = NEW.company_id AND job_id = NEW.job_id AND id = NEW.event_id;
  IF NOT FOUND THEN
   RAISE EXCEPTION 'A fulfilment receipt names no event' USING ERRCODE = '23514';
  END IF;
 END IF;
 IF ev.operation = 'COMPLETE' AND EXISTS (SELECT 1 FROM job_sales_origins WHERE company_id = ev.company_id AND job_id = ev.job_id) THEN
  SELECT count(*) INTO n FROM handover_fulfilment_receipts WHERE company_id = ev.company_id AND job_id = ev.job_id AND event_id = ev.id;
  IF n <> 1 THEN
   RAISE EXCEPTION 'Job % COMPLETE event % must carry exactly one Sales fulfilment receipt', ev.job_id, ev.sequence USING ERRCODE = '23514';
  END IF;
 ELSIF TG_TABLE_NAME = 'handover_fulfilment_receipts' THEN
  RAISE EXCEPTION 'A fulfilment receipt must belong to the COMPLETE event of a Sales-origin Job' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER job_events_fulfilment_consistency AFTER INSERT ON job_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION handover_fulfilment_consistency_v1();
CREATE CONSTRAINT TRIGGER handover_fulfilment_receipts_consistency AFTER INSERT ON handover_fulfilment_receipts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION handover_fulfilment_consistency_v1();
SQL);
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drop.
        DB::statement('LOCK TABLE handover_fulfilment_receipts, job_events IN ACCESS EXCLUSIVE MODE');
        if (DB::table('handover_fulfilment_receipts')->exists()) {
            throw new RuntimeException('Fulfilment receipts exist; rolling this migration back would erase Sales fulfilment evidence. Plan a backout first.');
        }
        DB::statement('DROP TRIGGER IF EXISTS job_events_fulfilment_consistency ON job_events');
        DB::statement('DROP TRIGGER IF EXISTS handover_fulfilment_receipts_consistency ON handover_fulfilment_receipts');
        Schema::drop('handover_fulfilment_receipts');
        DB::unprepared('DROP FUNCTION IF EXISTS handover_fulfilment_consistency_v1(), handover_fulfilment_receipt_guard()');
    }
};
