<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, QA1a (issue #59; proposal on #50, design #45): who is accountable for a quotation family, as stored fact and immutable evidence — and nothing that can
 * widen anyone's access. `created_by` stays the immutable provenance of who started the family; `responsible_owner_id` is the current accountability and moves only
 * through an ownership event.
 *
 *  - QUOTATION_FAMILIES gain the current owner, a reserved nullable access unit (a typed seam: no unit is inferred and nothing reads it for visibility), an ownership
 *    generation, an access generation and a baseline state. UNRESOLVED (every existing row) means the family has no recorded owner; there is no backfill, and
 *    created_by is never copied into the owner. NATIVE families start owned by their creator; CAPTURED ones were adopted from an approved mapping.
 *  - QUOTATION_OWNERSHIP_EVENTS are the append-only chain: INITIAL or CAPTURED_BASELINE first, then REASSIGNED and UNIT_CHANGED. Every event names the previous
 *    one, the old and new values, the actor, a reason and the database's own clock; the chain, and the family projection it must equal at COMMIT, are enforced here.
 *  - All references are company-scoped composite keys. The database refuses UPDATE, DELETE and TRUNCATE on the events; a referenced user or unit cannot be deleted.
 *
 * No Action, route, duty registration, grant or list accompanies the schema in this migration. Rolling back refuses while any event or owned family exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_families', function (Blueprint $t): void {
            $t->unsignedBigInteger('responsible_owner_id')->nullable();
            $t->unsignedBigInteger('access_unit_id')->nullable();
            $t->unsignedBigInteger('ownership_generation')->default(0);
            $t->unsignedBigInteger('access_generation')->default(0);
            $t->string('access_baseline_state', 12)->default('UNRESOLVED');
            $t->foreign(['company_id', 'responsible_owner_id'], 'quotation_families_owner_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'access_unit_id'], 'quotation_families_access_unit_fk')->references(['company_id', 'id'])->on('organisation_units');
            $t->index(['company_id', 'responsible_owner_id', 'id'], 'quotation_families_by_owner');
            $t->index(['company_id', 'access_unit_id', 'id'], 'quotation_families_by_access_unit');
        });
        DB::statement("ALTER TABLE quotation_families ADD CONSTRAINT quotation_families_ownership_shape CHECK (
            (access_baseline_state = 'UNRESOLVED' AND responsible_owner_id IS NULL AND access_unit_id IS NULL AND ownership_generation = 0 AND access_generation = 0)
            OR (access_baseline_state IN ('CAPTURED','NATIVE') AND responsible_owner_id IS NOT NULL AND ownership_generation >= 1)
        )");

        Schema::create('quotation_ownership_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('family_id');
            $t->unsignedBigInteger('generation');
            $t->unsignedBigInteger('previous_event_id')->nullable();
            $t->string('kind', 20);
            $t->unsignedBigInteger('old_owner_id')->nullable();
            $t->unsignedBigInteger('new_owner_id');
            $t->unsignedBigInteger('old_unit_id')->nullable();
            $t->unsignedBigInteger('new_unit_id')->nullable();
            $t->unsignedBigInteger('access_generation');
            $t->text('baseline_source_reference')->nullable();
            $t->text('approval_reference')->nullable();
            $t->unsignedBigInteger('actor_id');
            $t->text('reason');
            $t->timestampTz('recorded_at', 6);
            $t->unique(['company_id', 'id'], 'quotation_ownership_events_company_id_unique');
            $t->unique(['company_id', 'family_id', 'id'], 'quotation_ownership_events_family_unique');
            $t->unique(['company_id', 'family_id', 'generation'], 'quotation_ownership_events_generation_unique');
            $t->foreign(['company_id', 'family_id'], 'quotation_ownership_events_family_fk')->references(['company_id', 'id'])->on('quotation_families');
            $t->foreign(['company_id', 'family_id', 'previous_event_id'], 'quotation_ownership_events_previous_fk')->references(['company_id', 'family_id', 'id'])->on('quotation_ownership_events');
            $t->foreign(['company_id', 'old_owner_id'], 'quotation_ownership_events_old_owner_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'new_owner_id'], 'quotation_ownership_events_new_owner_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'actor_id'], 'quotation_ownership_events_actor_fk')->references(['company_id', 'id'])->on('users');
            $t->foreign(['company_id', 'old_unit_id'], 'quotation_ownership_events_old_unit_fk')->references(['company_id', 'id'])->on('organisation_units');
            $t->foreign(['company_id', 'new_unit_id'], 'quotation_ownership_events_new_unit_fk')->references(['company_id', 'id'])->on('organisation_units');
        });
        DB::statement("ALTER TABLE quotation_ownership_events ADD CONSTRAINT quotation_ownership_events_shape CHECK (
            generation >= 1 AND length(trim(reason)) > 0
            AND (baseline_source_reference IS NULL OR length(trim(baseline_source_reference)) > 0) AND (approval_reference IS NULL OR length(trim(approval_reference)) > 0)
            AND ((kind = 'INITIAL' AND generation = 1 AND previous_event_id IS NULL AND old_owner_id IS NULL AND old_unit_id IS NULL AND new_unit_id IS NULL AND access_generation = 0
                    AND baseline_source_reference IS NULL AND approval_reference IS NULL)
              OR (kind = 'CAPTURED_BASELINE' AND generation = 1 AND previous_event_id IS NULL AND old_owner_id IS NULL AND old_unit_id IS NULL AND access_generation = 1
                    AND baseline_source_reference IS NOT NULL AND approval_reference IS NOT NULL)
              OR (kind = 'REASSIGNED' AND generation >= 2 AND previous_event_id IS NOT NULL AND old_owner_id IS NOT NULL AND old_owner_id <> new_owner_id AND new_unit_id IS NOT DISTINCT FROM old_unit_id
                    AND baseline_source_reference IS NULL AND approval_reference IS NULL)
              OR (kind = 'UNIT_CHANGED' AND generation >= 2 AND previous_event_id IS NOT NULL AND old_owner_id = new_owner_id AND new_unit_id IS DISTINCT FROM old_unit_id
                    AND baseline_source_reference IS NULL AND approval_reference IS NULL))
        )");

        DB::unprepared(<<<'SQL'
-- Ownership history is evidence: never updated, never deleted, never truncated.
CREATE OR REPLACE FUNCTION quotation_ownership_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Quotation ownership events are append-only' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER quotation_ownership_events_guard BEFORE UPDATE OR DELETE ON quotation_ownership_events FOR EACH ROW EXECUTE FUNCTION quotation_ownership_append_only_guard();
CREATE TRIGGER quotation_ownership_events_truncate_guard BEFORE TRUNCATE ON quotation_ownership_events FOR EACH STATEMENT EXECUTE FUNCTION quotation_ownership_append_only_guard();

-- An appended event continues the one chain of its family: the family row is locked first (writers serialise), the time is the database's own clock whatever the
-- writer supplied, and old values equal the previous event's new values.
CREATE OR REPLACE FUNCTION quotation_ownership_event_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE last_event record;
BEGIN
 PERFORM 1 FROM quotation_families WHERE company_id = NEW.company_id AND id = NEW.family_id FOR UPDATE;
 NEW.recorded_at := clock_timestamp();
 SELECT id, generation, new_owner_id, new_unit_id, access_generation INTO last_event FROM quotation_ownership_events
  WHERE company_id = NEW.company_id AND family_id = NEW.family_id ORDER BY generation DESC LIMIT 1;
 IF NOT FOUND THEN
  IF NEW.generation <> 1 OR NEW.kind NOT IN ('INITIAL', 'CAPTURED_BASELINE') THEN
   RAISE EXCEPTION 'The first ownership event of a family is its initial owner or its captured baseline' USING ERRCODE = '23514';
  END IF;
 ELSE
  IF NEW.kind IN ('INITIAL', 'CAPTURED_BASELINE') OR NEW.generation <> last_event.generation + 1 OR NEW.previous_event_id IS DISTINCT FROM last_event.id
   OR NEW.old_owner_id IS DISTINCT FROM last_event.new_owner_id OR NEW.old_unit_id IS DISTINCT FROM last_event.new_unit_id THEN
   RAISE EXCEPTION 'Ownership events must continue the chain' USING ERRCODE = '23514';
  END IF;
  IF NEW.access_generation <> (last_event.access_generation + (CASE WHEN NEW.kind = 'UNIT_CHANGED' THEN 1 ELSE 0 END)) THEN
   RAISE EXCEPTION 'The access generation moves with an access unit change and nothing else' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_ownership_event_insert_guard BEFORE INSERT ON quotation_ownership_events FOR EACH ROW EXECUTE FUNCTION quotation_ownership_event_insert_guard();

-- The family's ownership never moves silently or backwards, and its baseline is final once it leaves UNRESOLVED.
CREATE OR REPLACE FUNCTION quotation_family_ownership_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.ownership_generation < OLD.ownership_generation OR NEW.access_generation < OLD.access_generation THEN
  RAISE EXCEPTION 'Quotation ownership generations only move forward' USING ERRCODE = '23514';
 END IF;
 IF NEW.access_baseline_state IS DISTINCT FROM OLD.access_baseline_state AND NOT (OLD.access_baseline_state = 'UNRESOLVED' AND NEW.access_baseline_state = 'CAPTURED') THEN
  RAISE EXCEPTION 'A quotation access baseline moves only from UNRESOLVED to CAPTURED' USING ERRCODE = '23514';
 END IF;
 IF (NEW.responsible_owner_id, NEW.access_unit_id) IS DISTINCT FROM (OLD.responsible_owner_id, OLD.access_unit_id) AND NEW.ownership_generation = OLD.ownership_generation THEN
  RAISE EXCEPTION 'A quotation owner or access unit changes only with an ownership event' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER quotation_family_ownership_guard BEFORE UPDATE ON quotation_families FOR EACH ROW EXECUTE FUNCTION quotation_family_ownership_guard();

-- At COMMIT the family must equal the end of its chain (or be UNRESOLVED with no chain at all), so an event and the family change may be written in either order
-- but neither can commit without the other.
CREATE OR REPLACE FUNCTION quotation_family_ownership_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_family bigint; fam record; last_event record; first_kind text;
BEGIN
 IF TG_TABLE_NAME = 'quotation_families' THEN
  v_family := NEW.id;
 ELSE
  v_family := NEW.family_id;
 END IF;
 SELECT responsible_owner_id, access_unit_id, ownership_generation, access_generation, access_baseline_state INTO fam FROM quotation_families WHERE company_id = NEW.company_id AND id = v_family;
 IF NOT FOUND THEN RETURN NULL; END IF;
 SELECT generation, new_owner_id, new_unit_id, access_generation INTO last_event FROM quotation_ownership_events
  WHERE company_id = NEW.company_id AND family_id = v_family ORDER BY generation DESC LIMIT 1;
 IF NOT FOUND THEN
  IF fam.access_baseline_state <> 'UNRESOLVED' OR fam.ownership_generation <> 0 OR fam.access_generation <> 0 OR fam.responsible_owner_id IS NOT NULL OR fam.access_unit_id IS NOT NULL THEN
   RAISE EXCEPTION 'A quotation family without ownership events must be UNRESOLVED' USING ERRCODE = '23514';
  END IF;
  RETURN NULL;
 END IF;
 SELECT kind INTO first_kind FROM quotation_ownership_events WHERE company_id = NEW.company_id AND family_id = v_family ORDER BY generation ASC LIMIT 1;
 IF fam.ownership_generation <> last_event.generation OR fam.access_generation <> last_event.access_generation OR fam.responsible_owner_id IS DISTINCT FROM last_event.new_owner_id
  OR fam.access_unit_id IS DISTINCT FROM last_event.new_unit_id OR fam.access_baseline_state <> (CASE WHEN first_kind = 'INITIAL' THEN 'NATIVE' ELSE 'CAPTURED' END) THEN
  RAISE EXCEPTION 'A quotation family ownership must equal its latest recorded ownership event' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER quotation_family_ownership_check AFTER INSERT OR UPDATE ON quotation_families DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION quotation_family_ownership_check();
CREATE CONSTRAINT TRIGGER quotation_ownership_event_check AFTER INSERT ON quotation_ownership_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION quotation_family_ownership_check();
SQL);
    }

    public function down(): void
    {
        // Lock first, then look: an unlocked pre-check could be overtaken by an insert between the check and the drops.
        DB::statement('LOCK TABLE quotation_ownership_events, quotation_families IN ACCESS EXCLUSIVE MODE');
        if (DB::table('quotation_ownership_events')->exists() || DB::table('quotation_families')->where('access_baseline_state', '<>', 'UNRESOLVED')->exists()) {
            throw new RuntimeException('Quotation ownership evidence exists; rolling this migration back would erase it. Plan a backout first.');
        }

        DB::statement('DROP TRIGGER IF EXISTS quotation_ownership_event_check ON quotation_ownership_events');
        DB::statement('DROP TRIGGER IF EXISTS quotation_family_ownership_check ON quotation_families');
        DB::statement('DROP TRIGGER IF EXISTS quotation_family_ownership_guard ON quotation_families');
        Schema::dropIfExists('quotation_ownership_events');
        DB::statement('DROP FUNCTION IF EXISTS quotation_family_ownership_check()');
        DB::statement('DROP FUNCTION IF EXISTS quotation_family_ownership_guard()');
        DB::statement('DROP FUNCTION IF EXISTS quotation_ownership_event_insert_guard()');
        DB::statement('DROP FUNCTION IF EXISTS quotation_ownership_append_only_guard()');
        DB::statement('ALTER TABLE quotation_families DROP CONSTRAINT IF EXISTS quotation_families_ownership_shape');
        Schema::table('quotation_families', function (Blueprint $t): void {
            $t->dropForeign('quotation_families_owner_fk');
            $t->dropForeign('quotation_families_access_unit_fk');
            $t->dropIndex('quotation_families_by_owner');
            $t->dropIndex('quotation_families_by_access_unit');
            $t->dropColumn(['responsible_owner_id', 'access_unit_id', 'ownership_generation', 'access_generation', 'access_baseline_state']);
        });
    }
};
