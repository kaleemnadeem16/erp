<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0094: package-aware supplier QA. Inspection-required receipts of a grouped item are received HELD; a QA disposition relabels a declared physical
 * portion HELD -> USABLE (pass, concession) or HELD -> REJECTED (return, scrap) through a QA_DISPOSITION evidence successor that moves no stock and no
 * cost. The database re-proves each relabelling at commit, keeps held stock equal to the quantity QA has not finally decided, requires evidence for
 * every deciding disposition of a grouped item, and ties every receipt leg and reversal to the line's QA state. Existing evidence is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_package_states DROP CONSTRAINT inventory_package_states_check');
        DB::statement("ALTER TABLE inventory_package_states ADD CONSTRAINT inventory_package_states_check CHECK (stock_quantity > 0 AND availability IN ('HELD', 'USABLE', 'REJECTED') AND ((form = 'SEALED' AND item_packaging_id IS NOT NULL AND sealed_count IS NOT NULL AND content_quantity IS NOT NULL AND sealed_count > 0 AND content_quantity > 0 AND stock_quantity = sealed_count * content_quantity AND packaging_code IS NOT NULL AND packaging_name IS NOT NULL) OR (form = 'OPEN_LOOSE' AND item_packaging_id IS NULL AND sealed_count IS NULL AND content_quantity IS NULL AND packaging_code IS NULL AND packaging_name IS NULL)))");
        Schema::table('inventory_package_issue_draws', function (Blueprint $t): void {
            $t->string('availability', 20)->default('USABLE');
        });
        DB::statement("ALTER TABLE inventory_package_issue_draws ADD CONSTRAINT package_issue_draw_availability CHECK (availability IN ('USABLE','HELD'))");
        Schema::table('inventory_package_operations', function (Blueprint $t): void {
            $t->unsignedBigInteger('quality_disposition_id')->nullable();
            $t->foreign(['company_id', 'quality_disposition_id'], 'package_operation_quality_disposition')->references(['company_id', 'id'])->on('quality_dispositions');
        });
        DB::statement('CREATE UNIQUE INDEX package_operation_qa_unique ON inventory_package_operations(company_id,quality_disposition_id) WHERE quality_disposition_id IS NOT NULL');
        DB::unprepared(<<<'SQL'
DO $$ DECLARE constraint_name text;
BEGIN
 FOR constraint_name IN SELECT conname FROM pg_constraint
 WHERE conrelid='inventory_package_operations'::regclass AND contype='c'
 AND pg_get_constraintdef(oid) LIKE '%evidence_kind%'
 LOOP EXECUTE format('ALTER TABLE inventory_package_operations DROP CONSTRAINT %I',constraint_name); END LOOP;
END $$;
SQL);
        DB::statement("ALTER TABLE inventory_package_operations ADD CONSTRAINT package_operation_kind CHECK (evidence_kind IN ('COUNTED','RECEIPT','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL','QA_DISPOSITION') AND length(trim(reason))>0 AND length(trim(request_key))>0 AND payload_hash ~ '^[0-9a-f]{64}$' AND ((evidence_kind='COUNTED' AND source_receipt_line_id IS NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL) OR (evidence_kind='RECEIPT' AND source_receipt_line_id IS NOT NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL AND physical_count_line_id IS NULL) OR (evidence_kind IN ('OPEN','UNPACK') AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NOT NULL AND selected_count IS NOT NULL AND selected_count>0 AND stock_move_id IS NULL AND physical_count_line_id IS NULL) OR (evidence_kind='ISSUE' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='TRANSFER_OUT' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='TRANSFER_IN' AND source_receipt_line_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='RECEIPT_IN' AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='COUNT_VARIANCE' AND source_receipt_line_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NOT NULL) OR (evidence_kind='RECEIPT_REVERSAL' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='QA_DISPOSITION' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL AND physical_count_line_id IS NULL AND quality_disposition_id IS NOT NULL AND stock_move_watermark IS NOT NULL)) AND (reverses_operation_id IS NULL OR evidence_kind='RECEIPT_REVERSAL') AND (quality_disposition_id IS NULL OR evidence_kind='QA_DISPOSITION'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_package_operation_lineage_v7() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent inventory_package_operations; chosen inventory_package_states; mv stock_moves;
BEGIN
 IF NEW.evidence_kind IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL') THEN
  PERFORM id FROM locations WHERE id=NEW.location_id FOR UPDATE;
  SELECT * INTO STRICT mv FROM stock_moves WHERE id=NEW.stock_move_id;
  IF mv.company_id<>NEW.company_id OR mv.item_id<>NEW.item_id OR mv.batch_id IS DISTINCT FROM NEW.batch_id OR mv.serial_no IS NOT NULL
   OR (NEW.evidence_kind='ISSUE' AND (mv.movement_kind<>'ISSUE' OR mv.from_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='TRANSFER_OUT' AND (mv.movement_kind<>'TRANSFER' OR mv.from_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='TRANSFER_IN' AND (mv.movement_kind<>'TRANSFER' OR mv.to_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='RECEIPT_REVERSAL' AND (mv.movement_kind<>'REVERSAL' OR mv.from_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='RECEIPT_IN' AND (mv.movement_kind<>'RECEIPT' OR mv.to_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='COUNT_VARIANCE' AND NOT ((mv.movement_kind='ISSUE' AND mv.from_location_id IS NOT DISTINCT FROM NEW.location_id) OR (mv.movement_kind='RECEIPT' AND mv.to_location_id IS NOT DISTINCT FROM NEW.location_id)))
  THEN RAISE EXCEPTION 'Movement evidence does not match its stock move'; END IF;
  IF NEW.reverses_operation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM inventory_package_operations r WHERE r.id=NEW.reverses_operation_id
    AND r.company_id=NEW.company_id AND r.evidence_kind='RECEIPT_IN' AND r.item_id=NEW.item_id AND r.location_id=NEW.location_id
    AND r.batch_id IS NOT DISTINCT FROM NEW.batch_id AND r.stock_move_id=mv.reverses_move_id) THEN
   RAISE EXCEPTION 'A receipt reversal must name the receipt evidence it undoes'; END IF;
  IF NEW.predecessor_id IS NULL THEN
   -- Only a destination that held nothing may start its chain from an incoming transfer.
   IF NOT (NEW.evidence_kind IN ('TRANSFER_IN','RECEIPT_IN') OR (NEW.evidence_kind='COUNT_VARIANCE' AND mv.movement_kind='RECEIPT')) OR EXISTS (SELECT 1 FROM stock_layers l WHERE l.company_id=NEW.company_id AND l.item_id=NEW.item_id
     AND l.location_id=NEW.location_id AND l.batch_id IS NOT DISTINCT FROM NEW.batch_id AND l.serial_no IS NULL AND l.qty_remaining>0
     AND NOT (l.source_type=mv.source_type AND l.source_id=mv.source_id))
   THEN RAISE EXCEPTION 'A destination holding stock needs fresh package evidence before it can receive stock'; END IF;
  ELSE
   SELECT * INTO STRICT parent FROM inventory_package_operations WHERE id=NEW.predecessor_id;
   IF parent.company_id<>NEW.company_id OR parent.item_id<>NEW.item_id OR parent.location_id<>NEW.location_id
    OR parent.batch_id IS DISTINCT FROM NEW.batch_id OR parent.evidence_kind NOT IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL','QA_DISPOSITION')
    OR parent.id<>(SELECT max(id) FROM inventory_package_operations WHERE company_id=NEW.company_id
     AND item_id=NEW.item_id AND location_id=NEW.location_id AND batch_id IS NOT DISTINCT FROM NEW.batch_id
     AND evidence_kind IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL','QA_DISPOSITION'))
    OR parent.stock_move_watermark IS NULL OR mv.id<=parent.stock_move_watermark
    OR EXISTS (SELECT 1 FROM stock_moves o WHERE o.company_id=NEW.company_id AND o.item_id=NEW.item_id AND o.batch_id IS NOT DISTINCT FROM NEW.batch_id
     AND o.serial_no IS NULL AND (o.from_location_id=NEW.location_id OR o.to_location_id=NEW.location_id)
     AND o.id>parent.stock_move_watermark AND o.id<>mv.id)
   THEN RAISE EXCEPTION 'Movement evidence must follow its predecessor with its own move as the only later movement'; END IF;
  END IF;
 ELSIF NEW.evidence_kind='QA_DISPOSITION' THEN
  PERFORM id FROM locations WHERE id=NEW.location_id FOR UPDATE;
  SELECT * INTO STRICT parent FROM inventory_package_operations WHERE id=NEW.predecessor_id;
  IF parent.company_id<>NEW.company_id OR parent.item_id<>NEW.item_id OR parent.location_id<>NEW.location_id
   OR parent.batch_id IS DISTINCT FROM NEW.batch_id OR parent.evidence_kind NOT IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL','QA_DISPOSITION')
   OR parent.id<>(SELECT max(id) FROM inventory_package_operations WHERE company_id=NEW.company_id
    AND item_id=NEW.item_id AND location_id=NEW.location_id AND batch_id IS NOT DISTINCT FROM NEW.batch_id
    AND evidence_kind IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL','QA_DISPOSITION'))
   OR parent.stock_move_watermark IS DISTINCT FROM NEW.stock_move_watermark
   OR EXISTS (SELECT 1 FROM stock_moves o WHERE o.company_id=NEW.company_id AND o.item_id=NEW.item_id AND o.batch_id IS NOT DISTINCT FROM NEW.batch_id
     AND o.serial_no IS NULL AND (o.from_location_id=NEW.location_id OR o.to_location_id=NEW.location_id) AND o.id>parent.stock_move_watermark)
   OR NOT EXISTS (SELECT 1 FROM quality_dispositions q WHERE q.id=NEW.quality_disposition_id AND q.company_id=NEW.company_id AND q.goods_receipt_line_id IS NOT NULL)
   OR NOT EXISTS (SELECT 1 FROM stock_layers l JOIN quality_dispositions q ON q.id=NEW.quality_disposition_id WHERE l.company_id=NEW.company_id AND l.source_type='GOODS_RECEIPT_LINE' AND l.source_id=q.goods_receipt_line_id
     AND l.item_id=NEW.item_id AND l.location_id=NEW.location_id AND l.batch_id IS NOT DISTINCT FROM NEW.batch_id)
  THEN RAISE EXCEPTION 'A QA disposition must follow the latest evidence of the scope that holds its receipt stock'; END IF;
 ELSIF NEW.predecessor_id IS NOT NULL THEN
  PERFORM id FROM locations WHERE id=NEW.location_id FOR UPDATE;
  SELECT * INTO STRICT parent FROM inventory_package_operations WHERE id=NEW.predecessor_id;
  SELECT * INTO STRICT chosen FROM inventory_package_states WHERE id=NEW.selected_state_id;
  IF parent.company_id<>NEW.company_id OR parent.item_id<>NEW.item_id OR parent.location_id<>NEW.location_id
   OR parent.batch_id IS DISTINCT FROM NEW.batch_id OR parent.evidence_kind NOT IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL','QA_DISPOSITION')
   OR parent.id<>(SELECT max(id) FROM inventory_package_operations WHERE company_id=NEW.company_id
    AND item_id=NEW.item_id AND location_id=NEW.location_id AND batch_id IS NOT DISTINCT FROM NEW.batch_id
    AND evidence_kind IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL','QA_DISPOSITION'))
   OR parent.stock_move_watermark IS DISTINCT FROM NEW.stock_move_watermark OR chosen.operation_id<>parent.id
   OR chosen.form<>'SEALED' OR chosen.availability<>'USABLE' OR NEW.selected_count>chosen.sealed_count
  THEN RAISE EXCEPTION 'Opening lineage does not match predecessor scope and sealed selection'; END IF;
 END IF;
 RETURN NEW;
END $$;
DROP TRIGGER package_operation_lineage ON inventory_package_operations;
CREATE TRIGGER package_operation_lineage BEFORE INSERT ON inventory_package_operations
FOR EACH ROW EXECUTE FUNCTION guard_package_operation_lineage_v7();

CREATE OR REPLACE FUNCTION reconcile_package_movement_v4() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE mv stock_moves; drawn numeric; allocated numeric; sign integer; prior_total numeric; moved numeric; successor_total numeric;
BEGIN
 SELECT * INTO STRICT mv FROM stock_moves WHERE id=NEW.stock_move_id;
 sign := CASE WHEN NEW.evidence_kind IN ('TRANSFER_IN','RECEIPT_IN') OR (NEW.evidence_kind='COUNT_VARIANCE' AND mv.movement_kind='RECEIPT') THEN 1 ELSE -1 END;
 SELECT coalesce(sum(quantity),0) INTO drawn FROM inventory_package_issue_draws WHERE operation_id=NEW.id;
 SELECT coalesce(sum(quantity),0) INTO allocated FROM inventory_package_issue_allocations WHERE operation_id=NEW.id;
 SELECT coalesce(sum(stock_quantity),0) INTO prior_total FROM inventory_package_states WHERE operation_id=NEW.predecessor_id;
 IF NOT EXISTS (SELECT 1 FROM inventory_package_states WHERE operation_id=NEW.id) AND prior_total+sign*(CASE WHEN NEW.evidence_kind='COUNT_VARIANCE' THEN mv.qty ELSE drawn END)<>0 THEN
  RAISE EXCEPTION 'Movement successor requires a complete composition (it may be empty only when nothing remains on hand)'; END IF;
 IF allocated<>mv.qty OR (NEW.evidence_kind<>'COUNT_VARIANCE' AND (drawn<=0 OR drawn<>mv.qty)) OR (NEW.evidence_kind='COUNT_VARIANCE' AND drawn<>0) THEN
  RAISE EXCEPTION 'Movement draw and allocation must each equal the posted stock quantity'; END IF;
 IF NEW.evidence_kind='COUNT_VARIANCE' THEN
  SELECT coalesce(sum(stock_quantity),0) INTO successor_total FROM inventory_package_states WHERE operation_id=NEW.id;
  IF successor_total<>prior_total+sign*mv.qty THEN RAISE EXCEPTION 'Count variance successor must equal the predecessor adjusted by the variance'; END IF;
 END IF;
 IF NEW.evidence_kind IN ('RECEIPT_IN','RECEIPT_REVERSAL') AND EXISTS (
  SELECT 1 FROM inventory_package_issue_draws d WHERE d.operation_id=NEW.id AND d.availability<>
   CASE WHEN EXISTS (SELECT 1 FROM goods_receipt_lines g JOIN purchase_order_lines p ON p.id=g.purchase_order_line_id
     WHERE g.id=CASE WHEN NEW.evidence_kind='RECEIPT_IN' THEN NEW.source_receipt_line_id ELSE (SELECT r.source_receipt_line_id FROM inventory_package_operations r WHERE r.id=NEW.reverses_operation_id) END AND p.qa_required)
    THEN 'HELD' ELSE 'USABLE' END
 ) THEN RAISE EXCEPTION 'A receipt of inspection-required stock is held, and any other receipt is usable'; END IF;
 IF NEW.evidence_kind NOT IN ('RECEIPT_IN','RECEIPT_REVERSAL') AND EXISTS (SELECT 1 FROM inventory_package_issue_draws d WHERE d.operation_id=NEW.id AND d.availability<>'USABLE') THEN
  RAISE EXCEPTION 'Only usable packages can be issued or transferred'; END IF;
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
 IF NEW.evidence_kind<>'COUNT_VARIANCE' AND EXISTS (
  WITH changes AS (
   SELECT form,item_packaging_id,availability,stock_quantity quantity FROM inventory_package_states WHERE operation_id=NEW.predecessor_id
   UNION ALL SELECT 'SEALED',d.item_packaging_id,d.availability,sign*d.quantity FROM inventory_package_issue_draws d WHERE d.operation_id=NEW.id AND d.kind='SEALED'
   UNION ALL SELECT 'OPEN_LOOSE',NULL,d.availability,sign*d.quantity FROM inventory_package_issue_draws d WHERE d.operation_id=NEW.id AND d.kind='LOOSE'
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
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.evidence_kind IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL')) EXECUTE FUNCTION reconcile_package_movement_v4();

CREATE OR REPLACE FUNCTION guard_package_issue_scope_v4() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE op inventory_package_operations; layer stock_layers;
BEGIN
 SELECT * INTO STRICT op FROM inventory_package_operations WHERE id=NEW.operation_id;
 IF op.evidence_kind NOT IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL','QA_DISPOSITION') THEN RAISE EXCEPTION 'Movement draws and allocations belong to movement evidence only'; END IF;
 IF TG_TABLE_NAME='inventory_package_issue_draws' THEN
  IF op.evidence_kind='COUNT_VARIANCE' THEN RAISE EXCEPTION 'A count variance states a counted composition, not a draw'; END IF;
  IF op.evidence_kind='QA_DISPOSITION' AND NEW.availability<>'HELD' THEN RAISE EXCEPTION 'A QA disposition declares held packages'; END IF;
  IF NEW.item_packaging_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item_packagings p WHERE p.id=NEW.item_packaging_id AND p.item_id=op.item_id) THEN
   RAISE EXCEPTION 'Issue draw names a package of another item'; END IF;
 ELSE
  IF op.evidence_kind='QA_DISPOSITION' THEN RAISE EXCEPTION 'A QA disposition moves no stock and has no layer allocation'; END IF;
  SELECT * INTO STRICT layer FROM stock_layers WHERE id=NEW.stock_layer_id;
  IF layer.company_id<>NEW.company_id OR layer.item_id<>op.item_id OR layer.location_id<>op.location_id OR layer.batch_id IS DISTINCT FROM op.batch_id OR layer.serial_no IS NOT NULL THEN
   RAISE EXCEPTION 'Issue allocation does not match stock scope'; END IF;
 END IF;
 RETURN NEW;
END $$;
DROP TRIGGER package_scope ON inventory_package_issue_draws;
DROP TRIGGER package_scope ON inventory_package_issue_allocations;
CREATE TRIGGER package_scope BEFORE INSERT ON inventory_package_issue_draws FOR EACH ROW EXECUTE FUNCTION guard_package_issue_scope_v4();
CREATE TRIGGER package_scope BEFORE INSERT ON inventory_package_issue_allocations FOR EACH ROW EXECUTE FUNCTION guard_package_issue_scope_v4();

CREATE OR REPLACE FUNCTION reconcile_package_qa_disposition() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE qd quality_dispositions; layer stock_layers; target text; drawn numeric; line_qty numeric; decided numeric; held numeric;
BEGIN
 SELECT * INTO STRICT qd FROM quality_dispositions WHERE id=NEW.quality_disposition_id;
 target := CASE qd.disposition WHEN 'PASS' THEN 'USABLE' WHEN 'ACCEPT_WITH_CONCESSION' THEN 'USABLE' WHEN 'RETURN_TO_SUPPLIER' THEN 'REJECTED' WHEN 'SCRAP' THEN 'REJECTED' ELSE NULL END;
 IF target IS NULL THEN RAISE EXCEPTION 'Only a releasing or a final rejecting QA outcome changes package evidence'; END IF;
 SELECT * INTO STRICT layer FROM stock_layers WHERE source_type='GOODS_RECEIPT_LINE' AND source_id=qd.goods_receipt_line_id;
 SELECT coalesce(sum(quantity),0) INTO drawn FROM inventory_package_issue_draws WHERE operation_id=NEW.id;
 IF drawn<>qd.qty OR EXISTS (SELECT 1 FROM inventory_package_issue_allocations WHERE operation_id=NEW.id) THEN
  RAISE EXCEPTION 'The declared packages must add up to the disposition quantity'; END IF;
 IF EXISTS (SELECT 1 FROM inventory_package_issue_draws d JOIN item_packagings p ON p.id=d.item_packaging_id
  WHERE d.operation_id=NEW.id AND d.kind='SEALED' AND d.quantity<>d.whole_count*p.stock_quantity) THEN
  RAISE EXCEPTION 'A sealed draw must equal whole packages times their content'; END IF;
 IF (SELECT coalesce(sum(stock_quantity),0) FROM inventory_package_states WHERE operation_id=NEW.id)
    <>(SELECT coalesce(sum(stock_quantity),0) FROM inventory_package_states WHERE operation_id=NEW.predecessor_id) THEN
  RAISE EXCEPTION 'A QA disposition changes no quantity'; END IF;
 IF EXISTS (
  WITH original AS (
   SELECT c.stock_layer_id,sum(c.quantity) quantity FROM inventory_package_states s
   JOIN inventory_package_contributions c ON c.state_id=s.id WHERE s.operation_id=NEW.predecessor_id GROUP BY c.stock_layer_id
  ), successor AS (
   SELECT c.stock_layer_id,sum(c.quantity) quantity FROM inventory_package_states s
   JOIN inventory_package_contributions c ON c.state_id=s.id WHERE s.operation_id=NEW.id GROUP BY c.stock_layer_id
  ) SELECT 1 FROM original o FULL JOIN successor s USING(stock_layer_id) WHERE o.quantity IS DISTINCT FROM s.quantity
 ) THEN RAISE EXCEPTION 'A QA disposition must leave every layer total unchanged'; END IF;
 IF EXISTS (
  WITH changes AS (
   SELECT form,item_packaging_id,availability,stock_quantity quantity FROM inventory_package_states WHERE operation_id=NEW.predecessor_id
   UNION ALL SELECT CASE WHEN d.kind='SEALED' THEN 'SEALED' ELSE 'OPEN_LOOSE' END,d.item_packaging_id,'HELD',-d.quantity FROM inventory_package_issue_draws d WHERE d.operation_id=NEW.id
   UNION ALL SELECT CASE WHEN d.kind='SEALED' THEN 'SEALED' ELSE 'OPEN_LOOSE' END,d.item_packaging_id,target,d.quantity FROM inventory_package_issue_draws d WHERE d.operation_id=NEW.id
  ), expected AS (
   SELECT form,item_packaging_id,availability,sum(quantity) quantity FROM changes GROUP BY form,item_packaging_id,availability HAVING sum(quantity)<>0
  ), actual AS (
   SELECT form,item_packaging_id,availability,sum(stock_quantity) quantity FROM inventory_package_states WHERE operation_id=NEW.id
   GROUP BY form,item_packaging_id,availability
  ) SELECT 1 FROM expected FULL JOIN actual ON expected.form=actual.form
   AND expected.item_packaging_id IS NOT DISTINCT FROM actual.item_packaging_id AND expected.availability=actual.availability
   WHERE expected.quantity IS DISTINCT FROM actual.quantity
 ) THEN RAISE EXCEPTION 'The package composition does not match the declared QA portion'; END IF;
 -- Evidence and the QA table cannot drift: what is still held at the receipt layer is exactly the quantity not yet finally decided.
 SELECT g.stock_qty INTO STRICT line_qty FROM goods_receipt_lines g WHERE g.id=qd.goods_receipt_line_id;
 SELECT coalesce(sum(q.qty),0) INTO decided FROM quality_dispositions q WHERE q.goods_receipt_line_id=qd.goods_receipt_line_id
  AND q.disposition IN ('PASS','ACCEPT_WITH_CONCESSION','RETURN_TO_SUPPLIER','SCRAP');
 SELECT coalesce(sum(c.quantity),0) INTO held FROM inventory_package_states s JOIN inventory_package_contributions c ON c.state_id=s.id
  WHERE s.operation_id=NEW.id AND s.availability='HELD' AND c.stock_layer_id=layer.id;
 IF held<>line_qty-decided THEN RAISE EXCEPTION 'Held package stock must equal the quantity of the receipt line that QA has not finally decided'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER package_qa_conservation AFTER INSERT ON inventory_package_operations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.evidence_kind='QA_DISPOSITION') EXECUTE FUNCTION reconcile_package_qa_disposition();

CREATE OR REPLACE FUNCTION require_qa_disposition_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE item bigint;
BEGIN
 IF NEW.disposition NOT IN ('PASS','ACCEPT_WITH_CONCESSION','RETURN_TO_SUPPLIER','SCRAP') THEN RETURN NULL; END IF;
 SELECT g.item_id INTO item FROM goods_receipt_lines g WHERE g.id=NEW.goods_receipt_line_id;
 IF EXISTS (SELECT 1 FROM items i WHERE i.id=item AND i.package_tracking_mode='PACKAGE_BALANCE')
  AND NOT EXISTS (SELECT 1 FROM inventory_package_operations o WHERE o.company_id=NEW.company_id AND o.quality_disposition_id=NEW.id) THEN
  RAISE EXCEPTION 'A QA disposition of an item with grouped package tracking needs package evidence'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER qa_disposition_needs_evidence AFTER INSERT ON quality_dispositions
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_qa_disposition_evidence();
SQL);
    }

    public function down(): void
    {
        if (DB::table('inventory_package_operations')->where('evidence_kind', 'QA_DISPOSITION')->exists() || DB::table('inventory_package_states')->where('availability', 'REJECTED')->exists() || DB::table('inventory_package_issue_draws')->where('availability', '<>', 'USABLE')->exists()) {
            throw new RuntimeException('Held-receipt, rejected or QA-disposition package evidence exists; restore a compatible application instead of discarding history.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER qa_disposition_needs_evidence ON quality_dispositions;
DROP FUNCTION require_qa_disposition_evidence();
DROP TRIGGER package_qa_conservation ON inventory_package_operations;
DROP FUNCTION reconcile_package_qa_disposition();
DROP TRIGGER package_scope ON inventory_package_issue_draws;
DROP TRIGGER package_scope ON inventory_package_issue_allocations;
CREATE TRIGGER package_scope BEFORE INSERT ON inventory_package_issue_draws FOR EACH ROW EXECUTE FUNCTION guard_package_issue_scope_v3();
CREATE TRIGGER package_scope BEFORE INSERT ON inventory_package_issue_allocations FOR EACH ROW EXECUTE FUNCTION guard_package_issue_scope_v3();
DROP FUNCTION guard_package_issue_scope_v4();
DROP TRIGGER package_issue_conservation ON inventory_package_operations;
CREATE CONSTRAINT TRIGGER package_issue_conservation AFTER INSERT ON inventory_package_operations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.evidence_kind IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL')) EXECUTE FUNCTION reconcile_package_movement_v3();
DROP FUNCTION reconcile_package_movement_v4();
DROP TRIGGER package_operation_lineage ON inventory_package_operations;
CREATE TRIGGER package_operation_lineage BEFORE INSERT ON inventory_package_operations
FOR EACH ROW EXECUTE FUNCTION guard_package_operation_lineage_v6();
DROP FUNCTION guard_package_operation_lineage_v7();
SQL);
        DB::statement('DROP INDEX package_operation_qa_unique');
        Schema::table('inventory_package_operations', function (Blueprint $t): void {
            $t->dropForeign('package_operation_quality_disposition');
            $t->dropColumn('quality_disposition_id');
        });
        Schema::table('inventory_package_issue_draws', function (Blueprint $t): void {
            $t->dropColumn('availability');
        });
        DB::statement('ALTER TABLE inventory_package_states DROP CONSTRAINT inventory_package_states_check');
        DB::statement("ALTER TABLE inventory_package_states ADD CONSTRAINT inventory_package_states_check CHECK (stock_quantity > 0 AND availability IN ('HELD', 'USABLE') AND ((form = 'SEALED' AND item_packaging_id IS NOT NULL AND sealed_count IS NOT NULL AND content_quantity IS NOT NULL AND sealed_count > 0 AND content_quantity > 0 AND stock_quantity = sealed_count * content_quantity AND packaging_code IS NOT NULL AND packaging_name IS NOT NULL) OR (form = 'OPEN_LOOSE' AND item_packaging_id IS NULL AND sealed_count IS NULL AND content_quantity IS NULL AND packaging_code IS NULL AND packaging_name IS NULL)))");
        $this->restoreKindCheck();
    }

    private function restoreKindCheck(): void
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
        DB::statement("ALTER TABLE inventory_package_operations ADD CONSTRAINT package_operation_kind CHECK (evidence_kind IN ('COUNTED','RECEIPT','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE','RECEIPT_REVERSAL') AND length(trim(reason))>0 AND length(trim(request_key))>0 AND payload_hash ~ '^[0-9a-f]{64}$' AND ((evidence_kind='COUNTED' AND source_receipt_line_id IS NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL) OR (evidence_kind='RECEIPT' AND source_receipt_line_id IS NOT NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL AND physical_count_line_id IS NULL) OR (evidence_kind IN ('OPEN','UNPACK') AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NOT NULL AND selected_count IS NOT NULL AND selected_count>0 AND stock_move_id IS NULL AND physical_count_line_id IS NULL) OR (evidence_kind='ISSUE' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='TRANSFER_OUT' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='TRANSFER_IN' AND source_receipt_line_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='RECEIPT_IN' AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='COUNT_VARIANCE' AND source_receipt_line_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NOT NULL) OR (evidence_kind='RECEIPT_REVERSAL' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL)) AND (reverses_operation_id IS NULL OR evidence_kind='RECEIPT_REVERSAL'))");
    }
};
