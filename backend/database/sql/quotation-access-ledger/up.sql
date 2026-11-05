-- One transaction, fence before subjects. Existing ownership rows/projections are never rewritten.
LOCK TABLE company_access_generations, quotation_families, quotation_ownership_events IN ACCESS EXCLUSIVE MODE;

CREATE TABLE quotation_access_grants (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), family_id bigint NOT NULL,
 issuer_id bigint NOT NULL, recipient_id bigint NOT NULL, actor_id bigint NOT NULL,
 level varchar(8) NOT NULL, starts_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(), ends_at timestamptz(6),
 issuer_authorization_generation bigint NOT NULL, recipient_authorization_generation bigint NOT NULL,
 organisation_generation bigint NOT NULL, capability_generation bigint NOT NULL, ownership_generation bigint NOT NULL,
 reason text NOT NULL, recorded_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CONSTRAINT quotation_access_grants_company_id_unique UNIQUE(company_id,id),
 CONSTRAINT quotation_access_grants_family_unique UNIQUE(company_id,family_id,id),
 CONSTRAINT quotation_access_grants_family_fk FOREIGN KEY(company_id,family_id) REFERENCES quotation_families(company_id,id),
 CONSTRAINT quotation_access_grants_issuer_fk FOREIGN KEY(company_id,issuer_id) REFERENCES users(company_id,id),
 CONSTRAINT quotation_access_grants_recipient_fk FOREIGN KEY(company_id,recipient_id) REFERENCES users(company_id,id),
 CONSTRAINT quotation_access_grants_actor_fk FOREIGN KEY(company_id,actor_id) REFERENCES users(company_id,id),
 CONSTRAINT quotation_access_grants_shape CHECK (
  actor_id=issuer_id AND issuer_id<>recipient_id AND level IN ('VIEW','PREPARE') AND length(trim(reason))>0
  AND (ends_at IS NULL OR ends_at>starts_at) AND issuer_authorization_generation>=0 AND recipient_authorization_generation>=0
  AND organisation_generation>=0 AND capability_generation>=0 AND ownership_generation>=1)
);
CREATE INDEX quotation_access_grants_recipient ON quotation_access_grants(company_id,recipient_id,family_id,starts_at,ends_at);
CREATE INDEX quotation_access_grants_issuer ON quotation_access_grants(company_id,issuer_id,family_id);

CREATE TABLE quotation_access_revocations (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), family_id bigint NOT NULL,
 grant_id bigint NOT NULL, revoked_by bigint NOT NULL, reason text NOT NULL, revoked_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CONSTRAINT quotation_access_revocations_company_id_unique UNIQUE(company_id,id),
 CONSTRAINT quotation_access_revocations_family_unique UNIQUE(company_id,family_id,id),
 CONSTRAINT quotation_access_revocations_grant_unique UNIQUE(company_id,grant_id),
 CONSTRAINT quotation_access_revocations_family_fk FOREIGN KEY(company_id,family_id) REFERENCES quotation_families(company_id,id),
 CONSTRAINT quotation_access_revocations_grant_fk FOREIGN KEY(company_id,family_id,grant_id) REFERENCES quotation_access_grants(company_id,family_id,id),
 CONSTRAINT quotation_access_revocations_actor_fk FOREIGN KEY(company_id,revoked_by) REFERENCES users(company_id,id),
 CONSTRAINT quotation_access_revocations_reason CHECK(length(trim(reason))>0)
);

CREATE TABLE quotation_access_events (
 id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), family_id bigint NOT NULL,
 generation bigint NOT NULL, previous_event_id bigint, kind varchar(20) NOT NULL,
 ownership_event_id bigint, grant_id bigint, revocation_id bigint,
 origin varchar(12) NOT NULL, recorded_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 CONSTRAINT quotation_access_events_company_id_unique UNIQUE(company_id,id),
 CONSTRAINT quotation_access_events_family_unique UNIQUE(company_id,family_id,id),
 CONSTRAINT quotation_access_events_generation_unique UNIQUE(company_id,family_id,generation),
 CONSTRAINT quotation_access_events_family_fk FOREIGN KEY(company_id,family_id) REFERENCES quotation_families(company_id,id),
 CONSTRAINT quotation_access_events_previous_fk FOREIGN KEY(company_id,family_id,previous_event_id) REFERENCES quotation_access_events(company_id,family_id,id),
 CONSTRAINT quotation_access_events_ownership_fk FOREIGN KEY(company_id,family_id,ownership_event_id) REFERENCES quotation_ownership_events(company_id,family_id,id),
 CONSTRAINT quotation_access_events_grant_fk FOREIGN KEY(company_id,family_id,grant_id) REFERENCES quotation_access_grants(company_id,family_id,id),
 CONSTRAINT quotation_access_events_revocation_fk FOREIGN KEY(company_id,family_id,revocation_id) REFERENCES quotation_access_revocations(company_id,family_id,id),
 CONSTRAINT quotation_access_events_shape CHECK (
  generation>0 AND (generation=1)=(previous_event_id IS NULL) AND origin IN ('BACKFILL','LIVE')
  AND num_nonnulls(ownership_event_id,grant_id,revocation_id)=1
  AND ((kind='OWNERSHIP_CHANGE' AND ownership_event_id IS NOT NULL)
    OR (kind='SHARE_GRANTED' AND grant_id IS NOT NULL) OR (kind='SHARE_REVOKED' AND revocation_id IS NOT NULL))
  AND (origin='LIVE' OR ownership_event_id IS NOT NULL))
);
CREATE UNIQUE INDEX quotation_access_events_ownership_source ON quotation_access_events(company_id,ownership_event_id) WHERE ownership_event_id IS NOT NULL;
CREATE UNIQUE INDEX quotation_access_events_grant_source ON quotation_access_events(company_id,grant_id) WHERE grant_id IS NOT NULL;
CREATE UNIQUE INDEX quotation_access_events_revocation_source ON quotation_access_events(company_id,revocation_id) WHERE revocation_id IS NOT NULL;

-- Derive a truthful new ledger from the immutable old ticks, without changing a source row.
DO $$ DECLARE source record; previous bigint;
BEGIN
 FOR source IN SELECT * FROM quotation_ownership_events WHERE kind IN ('CAPTURED_BASELINE','UNIT_CHANGED') ORDER BY company_id,family_id,access_generation LOOP
  SELECT id INTO previous FROM quotation_access_events WHERE company_id=source.company_id AND family_id=source.family_id ORDER BY generation DESC LIMIT 1;
  INSERT INTO quotation_access_events(company_id,family_id,generation,previous_event_id,kind,ownership_event_id,origin)
   VALUES(source.company_id,source.family_id,source.access_generation,previous,'OWNERSHIP_CHANGE',source.id,'BACKFILL');
 END LOOP;
 IF EXISTS(SELECT 1 FROM quotation_families f WHERE f.access_generation <> COALESCE((SELECT max(e.generation) FROM quotation_access_events e WHERE e.company_id=f.company_id AND e.family_id=f.id),0)) THEN
  RAISE EXCEPTION 'Existing access history cannot be reconciled to its immutable ownership sources' USING ERRCODE='23514';
 END IF;
END $$;

CREATE OR REPLACE FUNCTION quotation_access_bound_wait() RETURNS void LANGUAGE plpgsql AS $$
BEGIN
 PERFORM set_config('lock_timeout',LEAST(CASE WHEN setting::bigint=0 THEN 1000 ELSE setting::bigint END,1000)::text,true)
  FROM pg_settings WHERE name='lock_timeout';
END $$;
CREATE OR REPLACE FUNCTION quotation_access_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Quotation access evidence is append-only' USING ERRCODE='23514'; END $$;

CREATE OR REPLACE FUNCTION quotation_access_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE fam record; epoch record; own record;
BEGIN
 PERFORM quotation_access_bound_wait();
 SELECT * INTO fam FROM quotation_families WHERE company_id=NEW.company_id AND id=NEW.family_id FOR UPDATE;
 IF NOT FOUND OR fam.access_baseline_state='UNRESOLVED' THEN RAISE EXCEPTION 'A share needs a resolved same-company family' USING ERRCODE='23514'; END IF;
 SELECT * INTO own FROM quotation_ownership_events WHERE company_id=NEW.company_id AND family_id=NEW.family_id ORDER BY generation DESC LIMIT 1;
 IF NOT FOUND OR own.generation<>fam.ownership_generation OR own.new_owner_id IS DISTINCT FROM fam.responsible_owner_id OR own.new_unit_id IS DISTINCT FROM fam.access_unit_id
  OR fam.access_generation<>COALESCE((SELECT max(generation) FROM quotation_access_events WHERE company_id=NEW.company_id AND family_id=NEW.family_id),0) THEN
  RAISE EXCEPTION 'A share needs a complete ownership and access projection' USING ERRCODE='23514';
 END IF;
 IF TG_TABLE_NAME='quotation_access_grants' THEN
  IF NEW.starts_at IS NULL OR (NEW.ends_at IS NOT NULL AND NEW.ends_at<=NEW.starts_at) THEN RAISE EXCEPTION 'A share interval must be nonempty' USING ERRCODE='23514'; END IF;
  SELECT * INTO epoch FROM company_access_generations WHERE company_id=NEW.company_id;
  IF NEW.ownership_generation IS DISTINCT FROM fam.ownership_generation OR NEW.organisation_generation IS DISTINCT FROM epoch.organisation_generation OR NEW.capability_generation IS DISTINCT FROM epoch.capability_generation
   OR NOT EXISTS(SELECT 1 FROM users WHERE company_id=NEW.company_id AND id=NEW.issuer_id AND status='ACTIVE' AND authorization_generation=NEW.issuer_authorization_generation)
   OR NOT EXISTS(SELECT 1 FROM users WHERE company_id=NEW.company_id AND id=NEW.recipient_id AND status='ACTIVE' AND authorization_generation=NEW.recipient_authorization_generation) THEN
   RAISE EXCEPTION 'A share must pin current active same-company authority' USING ERRCODE='23514';
  END IF;
  IF EXISTS(SELECT 1 FROM quotation_access_grants g
   JOIN users issuer ON issuer.company_id=g.company_id AND issuer.id=g.issuer_id
   JOIN users recipient ON recipient.company_id=g.company_id AND recipient.id=g.recipient_id
   WHERE g.company_id=NEW.company_id AND g.family_id=NEW.family_id AND g.recipient_id=NEW.recipient_id AND g.level=NEW.level
    AND g.ownership_generation=fam.ownership_generation AND g.organisation_generation=epoch.organisation_generation AND g.capability_generation=epoch.capability_generation
    AND g.issuer_authorization_generation=issuer.authorization_generation AND g.recipient_authorization_generation=recipient.authorization_generation
    AND NOT EXISTS(SELECT 1 FROM quotation_access_revocations r WHERE r.company_id=g.company_id AND r.grant_id=g.id)
    AND tstzrange(g.starts_at,g.ends_at,'[)') && tstzrange(NEW.starts_at,NEW.ends_at,'[)')) THEN
   RAISE EXCEPTION 'An effective same-level share already overlaps this interval' USING ERRCODE='23514';
  END IF;
  NEW.recorded_at:=clock_timestamp();
 ELSE
  IF NOT EXISTS(SELECT 1 FROM quotation_access_grants WHERE company_id=NEW.company_id AND family_id=NEW.family_id AND id=NEW.grant_id)
   OR NOT EXISTS(SELECT 1 FROM users WHERE company_id=NEW.company_id AND id=NEW.revoked_by AND status='ACTIVE') THEN
   RAISE EXCEPTION 'A revocation needs its same-family grant and active actor' USING ERRCODE='23514';
  END IF;
  -- Expiry is not a prohibition on appending a revocation.
  NEW.revoked_at:=clock_timestamp();
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION quotation_access_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE tail record; fam record;
BEGIN
 PERFORM quotation_access_bound_wait();
 SELECT * INTO fam FROM quotation_families WHERE company_id=NEW.company_id AND id=NEW.family_id FOR UPDATE;
 SELECT id,generation INTO tail FROM quotation_access_events WHERE company_id=NEW.company_id AND family_id=NEW.family_id ORDER BY generation DESC LIMIT 1;
 IF NEW.origin<>'LIVE' OR NEW.generation<>COALESCE(tail.generation,0)+1 OR NEW.previous_event_id IS DISTINCT FROM tail.id THEN
  RAISE EXCEPTION 'An access event must continue the exact family counter chain' USING ERRCODE='23514';
 END IF;
 IF NEW.kind='OWNERSHIP_CHANGE' THEN
  IF NOT EXISTS(SELECT 1 FROM quotation_ownership_events WHERE company_id=NEW.company_id AND family_id=NEW.family_id AND id=NEW.ownership_event_id
    AND kind IN ('CAPTURED_BASELINE','UNIT_CHANGED') AND access_generation=NEW.generation)
   OR fam.access_generation NOT IN (NEW.generation-1,NEW.generation) THEN
   RAISE EXCEPTION 'An ownership access tick must match its immutable source' USING ERRCODE='23514';
  END IF;
 ELSE
  IF fam.access_generation<>NEW.generation-1 THEN RAISE EXCEPTION 'A share tick must advance the current access generation' USING ERRCODE='23514'; END IF;
 END IF;
 NEW.recorded_at:=clock_timestamp();
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION quotation_access_record_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE next_generation bigint; previous bigint;
BEGIN
 IF TG_TABLE_NAME='quotation_ownership_events' THEN
  IF NEW.kind NOT IN ('CAPTURED_BASELINE','UNIT_CHANGED') THEN RETURN NULL; END IF;
 END IF;
 SELECT id INTO previous FROM quotation_access_events WHERE company_id=NEW.company_id AND family_id=NEW.family_id ORDER BY generation DESC LIMIT 1;
 IF TG_TABLE_NAME='quotation_ownership_events' THEN
  INSERT INTO quotation_access_events(company_id,family_id,generation,previous_event_id,kind,ownership_event_id,origin)
   VALUES(NEW.company_id,NEW.family_id,NEW.access_generation,previous,'OWNERSHIP_CHANGE',NEW.id,'LIVE');
 ELSE
  SELECT access_generation+1 INTO next_generation FROM quotation_families WHERE company_id=NEW.company_id AND id=NEW.family_id FOR UPDATE;
  IF TG_TABLE_NAME='quotation_access_grants' THEN
   INSERT INTO quotation_access_events(company_id,family_id,generation,previous_event_id,kind,grant_id,origin)
    VALUES(NEW.company_id,NEW.family_id,next_generation,previous,'SHARE_GRANTED',NEW.id,'LIVE');
  ELSE
   INSERT INTO quotation_access_events(company_id,family_id,generation,previous_event_id,kind,revocation_id,origin)
    VALUES(NEW.company_id,NEW.family_id,next_generation,previous,'SHARE_REVOKED',NEW.id,'LIVE');
  END IF;
 END IF;
 RETURN NULL;
END $$;
CREATE OR REPLACE FUNCTION quotation_access_project_share() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 -- Ownership Actions retain their existing event/projection write protocol, in either order.
 IF NEW.kind<>'OWNERSHIP_CHANGE' THEN
  UPDATE quotation_families SET access_generation=NEW.generation,updated_at=clock_timestamp() WHERE company_id=NEW.company_id AND id=NEW.family_id;
 END IF;
 RETURN NULL;
END $$;

CREATE TRIGGER company_access_security_barrier BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON quotation_ownership_events FOR EACH STATEMENT EXECUTE FUNCTION company_access_security_writer_barrier();
CREATE TRIGGER quotation_access_ownership_source AFTER INSERT ON quotation_ownership_events FOR EACH ROW EXECUTE FUNCTION quotation_access_record_source();
CREATE TRIGGER company_access_security_barrier BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON quotation_access_grants FOR EACH STATEMENT EXECUTE FUNCTION company_access_security_writer_barrier();
CREATE TRIGGER company_access_security_barrier BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON quotation_access_revocations FOR EACH STATEMENT EXECUTE FUNCTION company_access_security_writer_barrier();
CREATE TRIGGER company_access_security_barrier BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON quotation_access_events FOR EACH STATEMENT EXECUTE FUNCTION company_access_security_writer_barrier();
CREATE TRIGGER quotation_access_grant_guard BEFORE INSERT ON quotation_access_grants FOR EACH ROW EXECUTE FUNCTION quotation_access_source_guard();
CREATE TRIGGER quotation_access_revocation_guard BEFORE INSERT ON quotation_access_revocations FOR EACH ROW EXECUTE FUNCTION quotation_access_source_guard();
CREATE TRIGGER quotation_access_grant_source AFTER INSERT ON quotation_access_grants FOR EACH ROW EXECUTE FUNCTION quotation_access_record_source();
CREATE TRIGGER quotation_access_revocation_source AFTER INSERT ON quotation_access_revocations FOR EACH ROW EXECUTE FUNCTION quotation_access_record_source();
CREATE TRIGGER quotation_access_event_guard BEFORE INSERT ON quotation_access_events FOR EACH ROW EXECUTE FUNCTION quotation_access_event_guard();
CREATE TRIGGER quotation_access_share_projection AFTER INSERT ON quotation_access_events FOR EACH ROW EXECUTE FUNCTION quotation_access_project_share();
CREATE TRIGGER quotation_access_grants_immutable BEFORE UPDATE OR DELETE ON quotation_access_grants FOR EACH ROW EXECUTE FUNCTION quotation_access_append_only();
CREATE TRIGGER quotation_access_grants_no_truncate BEFORE TRUNCATE ON quotation_access_grants FOR EACH STATEMENT EXECUTE FUNCTION quotation_access_append_only();
CREATE TRIGGER quotation_access_revocations_immutable BEFORE UPDATE OR DELETE ON quotation_access_revocations FOR EACH ROW EXECUTE FUNCTION quotation_access_append_only();
CREATE TRIGGER quotation_access_revocations_no_truncate BEFORE TRUNCATE ON quotation_access_revocations FOR EACH STATEMENT EXECUTE FUNCTION quotation_access_append_only();
CREATE TRIGGER quotation_access_events_immutable BEFORE UPDATE OR DELETE ON quotation_access_events FOR EACH ROW EXECUTE FUNCTION quotation_access_append_only();
CREATE TRIGGER quotation_access_events_no_truncate BEFORE TRUNCATE ON quotation_access_events FOR EACH STATEMENT EXECUTE FUNCTION quotation_access_append_only();

-- Preserve the original owner/unit/baseline chain; access accounting now follows the audited access tail.
CREATE OR REPLACE FUNCTION quotation_ownership_event_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE last_event record; current_access bigint;
BEGIN
 PERFORM quotation_access_bound_wait();
 PERFORM 1 FROM quotation_families WHERE company_id = NEW.company_id AND id = NEW.family_id FOR UPDATE;
 SELECT COALESCE(max(generation),0) INTO current_access FROM quotation_access_events WHERE company_id=NEW.company_id AND family_id=NEW.family_id;
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
  IF NEW.access_generation <> (current_access + (CASE WHEN NEW.kind = 'UNIT_CHANGED' THEN 1 ELSE 0 END)) THEN
   RAISE EXCEPTION 'The access generation moves with an access unit change and nothing else' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION quotation_family_ownership_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_family bigint; fam record; last_event record; first_kind text; access_tail bigint;
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
 IF fam.ownership_generation <> last_event.generation OR fam.responsible_owner_id IS DISTINCT FROM last_event.new_owner_id
  OR fam.access_unit_id IS DISTINCT FROM last_event.new_unit_id OR fam.access_baseline_state <> (CASE WHEN first_kind = 'INITIAL' THEN 'NATIVE' ELSE 'CAPTURED' END) THEN
  RAISE EXCEPTION 'A quotation family ownership must equal its latest recorded ownership event' USING ERRCODE = '23514';
 END IF;
 SELECT COALESCE(max(generation),0) INTO access_tail FROM quotation_access_events WHERE company_id=NEW.company_id AND family_id=v_family;
 IF fam.access_generation <> access_tail OR last_event.access_generation > access_tail THEN
  RAISE EXCEPTION 'A quotation family ownership must equal its latest recorded ownership event; its access generation must equal its latest access event' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER quotation_access_event_consistency AFTER INSERT ON quotation_access_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION quotation_family_ownership_check();
CREATE CONSTRAINT TRIGGER quotation_access_grant_consistency AFTER INSERT ON quotation_access_grants DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION quotation_family_ownership_check();
CREATE CONSTRAINT TRIGGER quotation_access_revocation_consistency AFTER INSERT ON quotation_access_revocations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION quotation_family_ownership_check();
