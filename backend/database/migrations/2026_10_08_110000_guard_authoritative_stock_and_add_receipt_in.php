<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0091 §3: fail-closed enforcement for items with grouped package tracking, and the RECEIPT_IN evidence leg that lets such an item
 * receive stock. For an authoritative item, any write to stock_moves or stock_layers quantities is refused unless the transaction carries
 * that item's package-aware flag (erp.package_aware_item, set only by the package-aware Actions around their own posting call), and every
 * later move must carry package evidence anchored on it by commit. Quantity-only items exit both triggers after one primary-key lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DO $$ DECLARE constraint_name text;
BEGIN
 FOR constraint_name IN SELECT conname FROM pg_constraint
 WHERE conrelid='inventory_package_operations'::regclass AND contype='c'
 AND pg_get_constraintdef(oid) LIKE '%evidence_kind%'
 LOOP EXECUTE format('ALTER TABLE inventory_package_operations DROP CONSTRAINT %I',constraint_name); END LOOP;
END $$;
SQL);
        DB::statement($this->kindCheck(true));
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_package_issue_scope() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE op inventory_package_operations; layer stock_layers;
BEGIN
 SELECT * INTO STRICT op FROM inventory_package_operations WHERE id=NEW.operation_id;
 IF op.evidence_kind NOT IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN') THEN RAISE EXCEPTION 'Movement draws and allocations belong to ISSUE or TRANSFER evidence only'; END IF;
 IF TG_TABLE_NAME='inventory_package_issue_draws' THEN
  IF NEW.item_packaging_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item_packagings p WHERE p.id=NEW.item_packaging_id AND p.item_id=op.item_id) THEN
   RAISE EXCEPTION 'Issue draw names a package of another item'; END IF;
 ELSE
  SELECT * INTO STRICT layer FROM stock_layers WHERE id=NEW.stock_layer_id;
  IF layer.company_id<>NEW.company_id OR layer.item_id<>op.item_id OR layer.location_id<>op.location_id OR layer.batch_id IS DISTINCT FROM op.batch_id OR layer.serial_no IS NOT NULL THEN
   RAISE EXCEPTION 'Issue allocation does not match stock scope'; END IF;
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION guard_package_operation_lineage_v4() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent inventory_package_operations; chosen inventory_package_states; mv stock_moves;
BEGIN
 IF NEW.evidence_kind IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN') THEN
  PERFORM id FROM locations WHERE id=NEW.location_id FOR UPDATE;
  SELECT * INTO STRICT mv FROM stock_moves WHERE id=NEW.stock_move_id;
  IF mv.company_id<>NEW.company_id OR mv.item_id<>NEW.item_id OR mv.batch_id IS DISTINCT FROM NEW.batch_id OR mv.serial_no IS NOT NULL
   OR (NEW.evidence_kind='ISSUE' AND (mv.movement_kind<>'ISSUE' OR mv.from_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='TRANSFER_OUT' AND (mv.movement_kind<>'TRANSFER' OR mv.from_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='TRANSFER_IN' AND (mv.movement_kind<>'TRANSFER' OR mv.to_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='RECEIPT_IN' AND (mv.movement_kind<>'RECEIPT' OR mv.to_location_id IS DISTINCT FROM NEW.location_id))
  THEN RAISE EXCEPTION 'Movement evidence does not match its stock move'; END IF;
  IF NEW.predecessor_id IS NULL THEN
   -- Only a destination that held nothing may start its chain from an incoming transfer.
   IF NEW.evidence_kind NOT IN ('TRANSFER_IN','RECEIPT_IN') OR EXISTS (SELECT 1 FROM stock_layers l WHERE l.company_id=NEW.company_id AND l.item_id=NEW.item_id
     AND l.location_id=NEW.location_id AND l.batch_id IS NOT DISTINCT FROM NEW.batch_id AND l.serial_no IS NULL AND l.qty_remaining>0
     AND NOT (l.source_type=mv.source_type AND l.source_id=mv.source_id))
   THEN RAISE EXCEPTION 'A destination holding stock needs fresh package evidence before it can receive stock'; END IF;
  ELSE
   SELECT * INTO STRICT parent FROM inventory_package_operations WHERE id=NEW.predecessor_id;
   IF parent.company_id<>NEW.company_id OR parent.item_id<>NEW.item_id OR parent.location_id<>NEW.location_id
    OR parent.batch_id IS DISTINCT FROM NEW.batch_id OR parent.evidence_kind NOT IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN')
    OR parent.id<>(SELECT max(id) FROM inventory_package_operations WHERE company_id=NEW.company_id
     AND item_id=NEW.item_id AND location_id=NEW.location_id AND batch_id IS NOT DISTINCT FROM NEW.batch_id
     AND evidence_kind IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN'))
    OR parent.stock_move_watermark IS NULL OR mv.id<=parent.stock_move_watermark
    OR EXISTS (SELECT 1 FROM stock_moves o WHERE o.company_id=NEW.company_id AND o.item_id=NEW.item_id AND o.batch_id IS NOT DISTINCT FROM NEW.batch_id
     AND o.serial_no IS NULL AND (o.from_location_id=NEW.location_id OR o.to_location_id=NEW.location_id)
     AND o.id>parent.stock_move_watermark AND o.id<>mv.id)
   THEN RAISE EXCEPTION 'Movement evidence must follow its predecessor with its own move as the only later movement'; END IF;
  END IF;
 ELSIF NEW.predecessor_id IS NOT NULL THEN
  PERFORM id FROM locations WHERE id=NEW.location_id FOR UPDATE;
  SELECT * INTO STRICT parent FROM inventory_package_operations WHERE id=NEW.predecessor_id;
  SELECT * INTO STRICT chosen FROM inventory_package_states WHERE id=NEW.selected_state_id;
  IF parent.company_id<>NEW.company_id OR parent.item_id<>NEW.item_id OR parent.location_id<>NEW.location_id
   OR parent.batch_id IS DISTINCT FROM NEW.batch_id OR parent.evidence_kind NOT IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN')
   OR parent.id<>(SELECT max(id) FROM inventory_package_operations WHERE company_id=NEW.company_id
    AND item_id=NEW.item_id AND location_id=NEW.location_id AND batch_id IS NOT DISTINCT FROM NEW.batch_id
    AND evidence_kind IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN'))
   OR parent.stock_move_watermark IS DISTINCT FROM NEW.stock_move_watermark OR chosen.operation_id<>parent.id
   OR chosen.form<>'SEALED' OR chosen.availability<>'USABLE' OR NEW.selected_count>chosen.sealed_count
  THEN RAISE EXCEPTION 'Opening lineage does not match predecessor scope and sealed selection'; END IF;
 END IF;
 RETURN NEW;
END $$;
DROP TRIGGER package_operation_lineage ON inventory_package_operations;
CREATE TRIGGER package_operation_lineage BEFORE INSERT ON inventory_package_operations
FOR EACH ROW EXECUTE FUNCTION guard_package_operation_lineage_v4();

CREATE OR REPLACE FUNCTION reconcile_package_movement() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE mv stock_moves; drawn numeric; allocated numeric; sign integer; prior_total numeric;
BEGIN
 SELECT * INTO STRICT mv FROM stock_moves WHERE id=NEW.stock_move_id;
 sign := CASE WHEN NEW.evidence_kind IN ('TRANSFER_IN','RECEIPT_IN') THEN 1 ELSE -1 END;
 SELECT coalesce(sum(quantity),0) INTO drawn FROM inventory_package_issue_draws WHERE operation_id=NEW.id;
 SELECT coalesce(sum(quantity),0) INTO allocated FROM inventory_package_issue_allocations WHERE operation_id=NEW.id;
 SELECT coalesce(sum(stock_quantity),0) INTO prior_total FROM inventory_package_states WHERE operation_id=NEW.predecessor_id;
 IF NOT EXISTS (SELECT 1 FROM inventory_package_states WHERE operation_id=NEW.id) AND prior_total+sign*drawn<>0 THEN
  RAISE EXCEPTION 'Movement successor requires a complete composition (it may be empty only when nothing remains on hand)'; END IF;
 IF drawn<=0 OR drawn<>mv.qty OR allocated<>mv.qty THEN
  RAISE EXCEPTION 'Movement draw and allocation must each equal the posted stock quantity'; END IF;
 IF EXISTS (
  SELECT 1 FROM inventory_package_issue_draws d JOIN item_packagings p ON p.id=d.item_packaging_id
  WHERE d.operation_id=NEW.id AND d.kind='SEALED' AND d.quantity<>d.whole_count*p.stock_quantity
 ) THEN RAISE EXCEPTION 'A sealed draw must equal whole packages times their content'; END IF;
 IF EXISTS (
  WITH original AS (
   SELECT c.stock_layer_id,sum(c.quantity) quantity FROM inventory_package_states s
   JOIN inventory_package_contributions c ON c.state_id=s.id WHERE s.operation_id=NEW.predecessor_id GROUP BY c.stock_layer_id
  ), moved AS (
   SELECT stock_layer_id,quantity FROM inventory_package_issue_allocations WHERE operation_id=NEW.id
  ), successor AS (
   SELECT c.stock_layer_id,sum(c.quantity) quantity FROM inventory_package_states s
   JOIN inventory_package_contributions c ON c.state_id=s.id WHERE s.operation_id=NEW.id GROUP BY c.stock_layer_id
  ), layers AS (SELECT stock_layer_id FROM original UNION SELECT stock_layer_id FROM moved UNION SELECT stock_layer_id FROM successor)
  SELECT 1 FROM layers l LEFT JOIN original o USING(stock_layer_id) LEFT JOIN moved t USING(stock_layer_id) LEFT JOIN successor s USING(stock_layer_id)
  WHERE coalesce(o.quantity,0)+sign*coalesce(t.quantity,0) IS DISTINCT FROM coalesce(s.quantity,0)
 ) THEN RAISE EXCEPTION 'Movement successor must equal the predecessor adjusted by the allocated layer quantities'; END IF;
 IF EXISTS (
  WITH changes AS (
   SELECT form,item_packaging_id,availability,stock_quantity quantity FROM inventory_package_states WHERE operation_id=NEW.predecessor_id
   UNION ALL SELECT 'SEALED',d.item_packaging_id,'USABLE',sign*d.quantity FROM inventory_package_issue_draws d WHERE d.operation_id=NEW.id AND d.kind='SEALED'
   UNION ALL SELECT 'OPEN_LOOSE',NULL,'USABLE',sign*d.quantity FROM inventory_package_issue_draws d WHERE d.operation_id=NEW.id AND d.kind='LOOSE'
  ), expected AS (
   SELECT form,item_packaging_id,availability,sum(quantity) quantity FROM changes GROUP BY form,item_packaging_id,availability HAVING sum(quantity)<>0
  ), actual AS (
   SELECT form,item_packaging_id,availability,sum(stock_quantity) quantity FROM inventory_package_states WHERE operation_id=NEW.id
   GROUP BY form,item_packaging_id,availability
  ) SELECT 1 FROM expected FULL JOIN actual ON expected.form=actual.form
   AND expected.item_packaging_id IS NOT DISTINCT FROM actual.item_packaging_id AND expected.availability=actual.availability
   WHERE expected.quantity IS DISTINCT FROM actual.quantity
 ) THEN RAISE EXCEPTION 'Movement successor composition does not match the physical draw'; END IF;
 RETURN NULL;
END $$;
DROP TRIGGER package_issue_conservation ON inventory_package_operations;
CREATE CONSTRAINT TRIGGER package_issue_conservation AFTER INSERT ON inventory_package_operations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.evidence_kind IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN')) EXECUTE FUNCTION reconcile_package_movement();

CREATE OR REPLACE FUNCTION guard_authoritative_stock_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rec record; authoritative boolean;
BEGIN
 IF TG_OP='DELETE' THEN rec := OLD; ELSE rec := NEW; END IF;
 IF TG_OP='UPDATE' THEN
  IF TG_TABLE_NAME='stock_layers' THEN
   IF NEW.qty_remaining=OLD.qty_remaining AND NEW.qty_received=OLD.qty_received THEN RETURN NEW; END IF;
  ELSE
   IF NEW.qty=OLD.qty AND NEW.item_id=OLD.item_id THEN RETURN NEW; END IF;
  END IF;
 END IF;
 SELECT i.package_tracking_mode='PACKAGE_BALANCE' INTO authoritative FROM items i WHERE i.id=rec.item_id;
 IF authoritative IS TRUE AND coalesce(current_setting('erp.package_aware_item', true),'')<>rec.item_id::text THEN
  RAISE EXCEPTION 'Stock of an item with grouped package tracking can change only through package-aware operations';
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER authoritative_stock_guard BEFORE INSERT OR UPDATE OR DELETE ON stock_moves FOR EACH ROW EXECUTE FUNCTION guard_authoritative_stock_write();
CREATE TRIGGER authoritative_stock_guard BEFORE INSERT OR UPDATE OR DELETE ON stock_layers FOR EACH ROW EXECUTE FUNCTION guard_authoritative_stock_write();

CREATE OR REPLACE FUNCTION require_authoritative_move_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE after_id bigint; present boolean;
BEGIN
 SELECT a.after_stock_move_id INTO after_id FROM inventory_package_activations a JOIN items i ON i.package_tracking_activation_id=a.id WHERE i.id=NEW.item_id;
 IF after_id IS NULL OR NEW.id<=after_id THEN RETURN NULL; END IF;
 IF NEW.movement_kind='ISSUE' THEN
  present := EXISTS (SELECT 1 FROM inventory_package_operations o WHERE o.company_id=NEW.company_id AND o.stock_move_id=NEW.id AND o.evidence_kind='ISSUE');
 ELSIF NEW.movement_kind='TRANSFER' THEN
  present := EXISTS (SELECT 1 FROM inventory_package_operations o WHERE o.company_id=NEW.company_id AND o.stock_move_id=NEW.id AND o.evidence_kind='TRANSFER_OUT')
         AND EXISTS (SELECT 1 FROM inventory_package_operations o WHERE o.company_id=NEW.company_id AND o.stock_move_id=NEW.id AND o.evidence_kind='TRANSFER_IN');
 ELSIF NEW.movement_kind='RECEIPT' THEN
  present := EXISTS (SELECT 1 FROM inventory_package_operations o WHERE o.company_id=NEW.company_id AND o.stock_move_id=NEW.id AND o.evidence_kind='RECEIPT_IN');
 ELSE
  present := false;
 END IF;
 IF NOT present THEN RAISE EXCEPTION 'A stock move of an item with grouped package tracking needs package evidence'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER authoritative_move_evidence AFTER INSERT ON stock_moves
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_authoritative_move_evidence();
SQL);
    }

    public function down(): void
    {
        if (DB::table('inventory_package_operations')->where('evidence_kind', 'RECEIPT_IN')->exists()) {
            throw new RuntimeException('Package receipt evidence exists; restore a compatible application instead of discarding history.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER authoritative_move_evidence ON stock_moves;
DROP TRIGGER authoritative_stock_guard ON stock_moves;
DROP TRIGGER authoritative_stock_guard ON stock_layers;
DROP FUNCTION require_authoritative_move_evidence();
DROP FUNCTION guard_authoritative_stock_write();
DROP TRIGGER package_operation_lineage ON inventory_package_operations;
CREATE TRIGGER package_operation_lineage BEFORE INSERT ON inventory_package_operations
FOR EACH ROW EXECUTE FUNCTION guard_package_operation_lineage_v3();
DROP FUNCTION guard_package_operation_lineage_v4();
DROP TRIGGER package_issue_conservation ON inventory_package_operations;
CREATE CONSTRAINT TRIGGER package_issue_conservation AFTER INSERT ON inventory_package_operations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.evidence_kind IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN')) EXECUTE FUNCTION reconcile_package_movement();
SQL);
        DB::unprepared(<<<'SQL'
DO $$ DECLARE constraint_name text;
BEGIN
 FOR constraint_name IN SELECT conname FROM pg_constraint
 WHERE conrelid='inventory_package_operations'::regclass AND contype='c'
 AND pg_get_constraintdef(oid) LIKE '%evidence_kind%'
 LOOP EXECUTE format('ALTER TABLE inventory_package_operations DROP CONSTRAINT %I',constraint_name); END LOOP;
END $$;
SQL);
        DB::statement($this->kindCheck(false));
    }

    private function kindCheck(bool $withReceiptIn): string
    {
        $kinds = $withReceiptIn ? "'COUNTED','RECEIPT','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN'" : "'COUNTED','RECEIPT','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN'";
        $receiptIn = $withReceiptIn
            ? " OR (evidence_kind='RECEIPT_IN' AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id)"
            : '';

        return "ALTER TABLE inventory_package_operations ADD CONSTRAINT package_operation_kind CHECK (evidence_kind IN ({$kinds}) AND length(trim(reason))>0 AND length(trim(request_key))>0 AND payload_hash ~ '^[0-9a-f]{64}\$' AND ((evidence_kind='COUNTED' AND source_receipt_line_id IS NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL) OR (evidence_kind='RECEIPT' AND source_receipt_line_id IS NOT NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL) OR (evidence_kind IN ('OPEN','UNPACK') AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NOT NULL AND selected_count IS NOT NULL AND selected_count>0 AND stock_move_id IS NULL) OR (evidence_kind='ISSUE' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id) OR (evidence_kind='TRANSFER_OUT' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id) OR (evidence_kind='TRANSFER_IN' AND source_receipt_line_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id){$receiptIn}))";
    }
};
