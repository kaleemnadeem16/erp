-- Called only after the migration's locked populated-evidence refusal.
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
DROP TRIGGER IF EXISTS quotation_access_ownership_source ON quotation_ownership_events;
DROP TRIGGER IF EXISTS company_access_security_barrier ON quotation_ownership_events;
DROP TABLE quotation_access_events;
DROP TABLE quotation_access_revocations;
DROP TABLE quotation_access_grants;
DROP FUNCTION IF EXISTS quotation_access_project_share();
DROP FUNCTION IF EXISTS quotation_access_record_source();
DROP FUNCTION IF EXISTS quotation_access_event_guard();
DROP FUNCTION IF EXISTS quotation_access_source_guard();
DROP FUNCTION IF EXISTS quotation_access_append_only();
DROP FUNCTION IF EXISTS quotation_access_bound_wait();
