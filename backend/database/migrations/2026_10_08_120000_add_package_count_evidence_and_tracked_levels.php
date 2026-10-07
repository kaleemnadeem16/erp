<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0092: package-aware physical count posting, and (its section 1a) tracked package levels fixed at activation. A grouped count line carries a
 * counted composition and captures the evidence it was taken against. Posting writes either a movement-free COUNTED recount or a COUNT_VARIANCE
 * successor anchored on the governed variance move; the database re-proves conservation at commit. After activation no package state of the
 * item may name a definition the activation does not track. Quantity-only items and existing evidence are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_package_tracked_levels', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('activation_id');
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('item_packaging_id');
            $t->unique(['activation_id', 'item_packaging_id'], 'package_tracked_level_unique');
            $t->foreign(['company_id', 'activation_id'])->references(['company_id', 'id'])->on('inventory_package_activations');
            $t->foreign(['company_id', 'item_packaging_id'])->references(['company_id', 'id'])->on('item_packagings');
        });
        DB::statement('CREATE TRIGGER package_immutable BEFORE UPDATE OR DELETE ON inventory_package_tracked_levels FOR EACH ROW EXECUTE FUNCTION reject_package_kernel_mutation()');
        // Existing activations: what their opening count used, closed over the definitions inside it.
        DB::unprepared(<<<'SQL'
INSERT INTO inventory_package_tracked_levels (company_id, activation_id, item_id, item_packaging_id)
WITH RECURSIVE used AS (
 SELECT DISTINCT a.company_id, a.id activation_id, a.item_id, st.item_packaging_id
 FROM inventory_package_activations a JOIN inventory_package_activation_scopes s ON s.activation_id=a.id
 JOIN inventory_package_states st ON st.operation_id=s.operation_id WHERE st.item_packaging_id IS NOT NULL
), closed AS (
 SELECT company_id, activation_id, item_id, item_packaging_id FROM used
 UNION
 SELECT c.company_id, c.activation_id, c.item_id, p.inner_packaging_id FROM closed c JOIN item_packagings p ON p.id=c.item_packaging_id WHERE p.inner_packaging_id IS NOT NULL
) SELECT company_id, activation_id, item_id, item_packaging_id FROM closed;
SQL);

        Schema::create('physical_count_line_compositions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('physical_count_line_id');
            $t->unsignedInteger('line_no');
            $t->unsignedBigInteger('item_packaging_id')->nullable();
            $t->unsignedInteger('whole_count')->nullable();
            $t->decimal('quantity', 19, 4);
            $t->unique(['physical_count_line_id', 'line_no']);
            $t->foreign(['company_id', 'physical_count_line_id'])->references(['company_id', 'id'])->on('physical_count_lines');
            $t->foreign(['company_id', 'item_packaging_id'])->references(['company_id', 'id'])->on('item_packagings');
        });
        DB::statement('ALTER TABLE physical_count_line_compositions ADD CONSTRAINT pclc_shape CHECK (quantity>0 AND line_no>0 AND ((item_packaging_id IS NOT NULL AND whole_count>0) OR (item_packaging_id IS NULL AND whole_count IS NULL)))');
        Schema::table('physical_count_lines', function (Blueprint $t): void {
            $t->boolean('grouped')->default(false);
            $t->unsignedBigInteger('package_operation_id')->nullable();
            $t->unsignedBigInteger('package_watermark')->nullable();
            $t->foreign(['company_id', 'package_operation_id'], 'pcl_package_operation_fk')->references(['company_id', 'id'])->on('inventory_package_operations');
        });
        Schema::table('inventory_package_operations', function (Blueprint $t): void {
            $t->unsignedBigInteger('physical_count_line_id')->nullable();
            $t->foreign(['company_id', 'physical_count_line_id'], 'package_operation_count_line')->references(['company_id', 'id'])->on('physical_count_lines');
        });
        DB::statement('CREATE UNIQUE INDEX package_operation_count_line_unique ON inventory_package_operations(company_id,physical_count_line_id) WHERE physical_count_line_id IS NOT NULL');
        DB::unprepared(<<<'SQL'
DO $$ DECLARE constraint_name text;
BEGIN
 FOR constraint_name IN SELECT conname FROM pg_constraint
 WHERE conrelid='inventory_package_operations'::regclass AND contype='c'
 AND pg_get_constraintdef(oid) LIKE '%evidence_kind%'
 LOOP EXECUTE format('ALTER TABLE inventory_package_operations DROP CONSTRAINT %I',constraint_name); END LOOP;
END $$;
SQL);
        DB::statement("ALTER TABLE inventory_package_operations ADD CONSTRAINT package_operation_kind CHECK (evidence_kind IN ('COUNTED','RECEIPT','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE') AND length(trim(reason))>0 AND length(trim(request_key))>0 AND payload_hash ~ '^[0-9a-f]{64}$' AND ((evidence_kind='COUNTED' AND source_receipt_line_id IS NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL) OR (evidence_kind='RECEIPT' AND source_receipt_line_id IS NOT NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL AND physical_count_line_id IS NULL) OR (evidence_kind IN ('OPEN','UNPACK') AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NOT NULL AND selected_count IS NOT NULL AND selected_count>0 AND stock_move_id IS NULL AND physical_count_line_id IS NULL) OR (evidence_kind='ISSUE' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='TRANSFER_OUT' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='TRANSFER_IN' AND source_receipt_line_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='RECEIPT_IN' AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NULL) OR (evidence_kind='COUNT_VARIANCE' AND source_receipt_line_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id AND physical_count_line_id IS NOT NULL)))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_physical_count_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE count_status text; count_warehouse bigint; location_warehouse bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'physical count lines cannot be deleted' USING ERRCODE = '23514'; END IF;
    IF TG_OP = 'INSERT' THEN
        SELECT status, warehouse_id INTO count_status, count_warehouse FROM physical_counts WHERE id = NEW.physical_count_id;
        SELECT w.id INTO location_warehouse FROM locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.id = NEW.location_id;
        IF count_status <> 'DRAFT' THEN RAISE EXCEPTION 'lines can only be added to a draft physical count' USING ERRCODE = '23514'; END IF;
        IF location_warehouse IS DISTINCT FROM count_warehouse THEN RAISE EXCEPTION 'physical count line location must belong to the count warehouse' USING ERRCODE = '23514'; END IF;
        RETURN NEW;
    END IF;
    SELECT status INTO count_status FROM physical_counts WHERE id = NEW.physical_count_id;
    IF NEW.company_id <> OLD.company_id OR NEW.physical_count_id <> OLD.physical_count_id
       OR NEW.item_id <> OLD.item_id OR NEW.location_id <> OLD.location_id
       OR NEW.batch_id IS DISTINCT FROM OLD.batch_id OR NEW.serial_no IS DISTINCT FROM OLD.serial_no
       OR (NEW.book_qty <> OLD.book_qty AND NOT (OLD.grouped IS TRUE AND count_status = 'DRAFT'))
    THEN RAISE EXCEPTION 'physical count line identity is immutable' USING ERRCODE = '23514'; END IF;
    IF OLD.posted_stock_move_id IS NOT NULL AND NEW.posted_stock_move_id IS DISTINCT FROM OLD.posted_stock_move_id
    THEN RAISE EXCEPTION 'a posted physical count line cannot be re-posted' USING ERRCODE = '23514'; END IF;
    IF (NEW.counted_qty IS DISTINCT FROM OLD.counted_qty OR NEW.found_unit_cost IS DISTINCT FROM OLD.found_unit_cost) THEN
        IF count_status <> 'DRAFT' THEN RAISE EXCEPTION 'a line can only be counted while its physical count is draft' USING ERRCODE = '23514'; END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION guard_package_tracked_level() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE op inventory_package_operations; activation bigint;
BEGIN
 IF NEW.item_packaging_id IS NULL THEN RETURN NEW; END IF;
 SELECT * INTO STRICT op FROM inventory_package_operations WHERE id=NEW.operation_id;
 SELECT i.package_tracking_activation_id INTO activation FROM items i WHERE i.id=op.item_id;
 IF activation IS NULL THEN RETURN NEW; END IF;
 IF NOT EXISTS (SELECT 1 FROM inventory_package_tracked_levels t WHERE t.activation_id=activation AND t.item_packaging_id=NEW.item_packaging_id) THEN
  RAISE EXCEPTION 'This item does not track that package level; changing tracked levels is a governed configuration change, not a stock operation';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER package_tracked_level BEFORE INSERT ON inventory_package_states FOR EACH ROW EXECUTE FUNCTION guard_package_tracked_level();

CREATE OR REPLACE FUNCTION freeze_item_package_policy_v3() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE act inventory_package_activations; scope record;
BEGIN
 IF NEW.package_tracking_source IS DISTINCT FROM OLD.package_tracking_source
 OR NEW.package_tracking_source_category_id IS DISTINCT FROM OLD.package_tracking_source_category_id THEN
  RAISE EXCEPTION 'Item package policy provenance is pinned';
 END IF;
 IF NEW.package_tracking_mode IS DISTINCT FROM OLD.package_tracking_mode
 OR NEW.package_tracking_activation_id IS DISTINCT FROM OLD.package_tracking_activation_id THEN
  IF NOT (OLD.package_tracking_mode='QUANTITY_ONLY' AND OLD.package_tracking_activation_id IS NULL
          AND NEW.package_tracking_mode='PACKAGE_BALANCE' AND NEW.package_tracking_activation_id IS NOT NULL) THEN
   RAISE EXCEPTION 'Item package policy changes only through activation, and activation cannot be undone';
  END IF;
  SELECT * INTO act FROM inventory_package_activations WHERE id=NEW.package_tracking_activation_id AND company_id=NEW.company_id AND item_id=NEW.id;
  IF act.id IS NULL THEN RAISE EXCEPTION 'Activation does not belong to this item'; END IF;
  IF NEW.tracking NOT IN ('NONE','BATCH') OR NEW.valuation_method NOT IN ('FIFO','AVCO') OR NEW.is_stocked IS NOT TRUE THEN
   RAISE EXCEPTION 'Grouped package tracking supports stocked NONE/BATCH items valued FIFO or AVCO only'; END IF;
  -- Every scope that holds stock must be covered by fresh, reconciled evidence: the latest operation, whose watermark is the scope's newest move.
  FOR scope IN SELECT l.location_id, l.batch_id, sum(l.qty_remaining) quantity, bool_or(l.serial_no IS NOT NULL) serial
               FROM stock_layers l WHERE l.item_id=NEW.id AND l.qty_remaining>0 GROUP BY l.location_id, l.batch_id LOOP
   IF scope.serial THEN RAISE EXCEPTION 'Serial stock cannot be grouped'; END IF;
   IF NOT EXISTS (
    SELECT 1 FROM inventory_package_activation_scopes s JOIN inventory_package_operations o ON o.id=s.operation_id
    WHERE s.activation_id=act.id AND s.location_id=scope.location_id AND s.batch_id IS NOT DISTINCT FROM scope.batch_id
     AND o.item_id=NEW.id AND o.location_id=scope.location_id AND o.batch_id IS NOT DISTINCT FROM scope.batch_id
     AND o.id=(SELECT max(id) FROM inventory_package_operations x WHERE x.company_id=o.company_id AND x.item_id=o.item_id
        AND x.location_id=o.location_id AND x.batch_id IS NOT DISTINCT FROM o.batch_id
        AND x.evidence_kind IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN'))
     AND o.evidence_kind IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN')
     AND o.stock_move_watermark IS NOT NULL
     AND o.stock_move_watermark=(SELECT max(m.id) FROM stock_moves m WHERE m.item_id=NEW.id AND m.serial_no IS NULL AND m.batch_id IS NOT DISTINCT FROM scope.batch_id
        AND (m.from_location_id=scope.location_id OR m.to_location_id=scope.location_id))
     AND (SELECT coalesce(sum(st.stock_quantity),0) FROM inventory_package_states st WHERE st.operation_id=o.id)=scope.quantity
   ) THEN RAISE EXCEPTION 'Activation needs fresh reconciled package evidence for every location and batch that holds stock'; END IF;
  END LOOP;
  IF EXISTS (SELECT 1 FROM inventory_package_activation_scopes s WHERE s.activation_id=act.id
     AND NOT EXISTS (SELECT 1 FROM stock_layers l WHERE l.item_id=NEW.id AND l.location_id=s.location_id AND l.batch_id IS NOT DISTINCT FROM s.batch_id AND l.qty_remaining>0)) THEN
   RAISE EXCEPTION 'Activation lists a scope that holds no stock'; END IF;
 IF EXISTS (SELECT 1 FROM inventory_package_activation_scopes s JOIN inventory_package_states st ON st.operation_id=s.operation_id
     WHERE s.activation_id=act.id AND st.item_packaging_id IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM inventory_package_tracked_levels t WHERE t.activation_id=act.id AND t.item_packaging_id=st.item_packaging_id)) THEN
   RAISE EXCEPTION 'The opening count uses a package level the activation does not track'; END IF;
  IF EXISTS (SELECT 1 FROM inventory_package_tracked_levels t JOIN item_packagings p ON p.id=t.item_packaging_id
     WHERE t.activation_id=act.id AND p.inner_packaging_id IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM inventory_package_tracked_levels u WHERE u.activation_id=act.id AND u.item_packaging_id=p.inner_packaging_id)) THEN
   RAISE EXCEPTION 'Tracking a package level requires tracking the level inside it'; END IF;
 END IF;
 RETURN NEW;
END $$;
DROP TRIGGER item_package_policy_pinned ON items;
CREATE TRIGGER item_package_policy_pinned BEFORE UPDATE ON items FOR EACH ROW EXECUTE FUNCTION freeze_item_package_policy_v3();

CREATE OR REPLACE FUNCTION guard_package_operation_lineage_v5() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent inventory_package_operations; chosen inventory_package_states; mv stock_moves;
BEGIN
 IF NEW.evidence_kind IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE') THEN
  PERFORM id FROM locations WHERE id=NEW.location_id FOR UPDATE;
  SELECT * INTO STRICT mv FROM stock_moves WHERE id=NEW.stock_move_id;
  IF mv.company_id<>NEW.company_id OR mv.item_id<>NEW.item_id OR mv.batch_id IS DISTINCT FROM NEW.batch_id OR mv.serial_no IS NOT NULL
   OR (NEW.evidence_kind='ISSUE' AND (mv.movement_kind<>'ISSUE' OR mv.from_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='TRANSFER_OUT' AND (mv.movement_kind<>'TRANSFER' OR mv.from_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='TRANSFER_IN' AND (mv.movement_kind<>'TRANSFER' OR mv.to_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='RECEIPT_IN' AND (mv.movement_kind<>'RECEIPT' OR mv.to_location_id IS DISTINCT FROM NEW.location_id))
   OR (NEW.evidence_kind='COUNT_VARIANCE' AND NOT ((mv.movement_kind='ISSUE' AND mv.from_location_id IS NOT DISTINCT FROM NEW.location_id) OR (mv.movement_kind='RECEIPT' AND mv.to_location_id IS NOT DISTINCT FROM NEW.location_id)))
  THEN RAISE EXCEPTION 'Movement evidence does not match its stock move'; END IF;
  IF NEW.predecessor_id IS NULL THEN
   -- Only a destination that held nothing may start its chain from an incoming transfer.
   IF NOT (NEW.evidence_kind IN ('TRANSFER_IN','RECEIPT_IN') OR (NEW.evidence_kind='COUNT_VARIANCE' AND mv.movement_kind='RECEIPT')) OR EXISTS (SELECT 1 FROM stock_layers l WHERE l.company_id=NEW.company_id AND l.item_id=NEW.item_id
     AND l.location_id=NEW.location_id AND l.batch_id IS NOT DISTINCT FROM NEW.batch_id AND l.serial_no IS NULL AND l.qty_remaining>0
     AND NOT (l.source_type=mv.source_type AND l.source_id=mv.source_id))
   THEN RAISE EXCEPTION 'A destination holding stock needs fresh package evidence before it can receive stock'; END IF;
  ELSE
   SELECT * INTO STRICT parent FROM inventory_package_operations WHERE id=NEW.predecessor_id;
   IF parent.company_id<>NEW.company_id OR parent.item_id<>NEW.item_id OR parent.location_id<>NEW.location_id
    OR parent.batch_id IS DISTINCT FROM NEW.batch_id OR parent.evidence_kind NOT IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE')
    OR parent.id<>(SELECT max(id) FROM inventory_package_operations WHERE company_id=NEW.company_id
     AND item_id=NEW.item_id AND location_id=NEW.location_id AND batch_id IS NOT DISTINCT FROM NEW.batch_id
     AND evidence_kind IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE'))
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
   OR parent.batch_id IS DISTINCT FROM NEW.batch_id OR parent.evidence_kind NOT IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE')
   OR parent.id<>(SELECT max(id) FROM inventory_package_operations WHERE company_id=NEW.company_id
    AND item_id=NEW.item_id AND location_id=NEW.location_id AND batch_id IS NOT DISTINCT FROM NEW.batch_id
    AND evidence_kind IN ('COUNTED','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE'))
   OR parent.stock_move_watermark IS DISTINCT FROM NEW.stock_move_watermark OR chosen.operation_id<>parent.id
   OR chosen.form<>'SEALED' OR chosen.availability<>'USABLE' OR NEW.selected_count>chosen.sealed_count
  THEN RAISE EXCEPTION 'Opening lineage does not match predecessor scope and sealed selection'; END IF;
 END IF;
 RETURN NEW;
END $$;
DROP TRIGGER package_operation_lineage ON inventory_package_operations;
CREATE TRIGGER package_operation_lineage BEFORE INSERT ON inventory_package_operations
FOR EACH ROW EXECUTE FUNCTION guard_package_operation_lineage_v5();

CREATE OR REPLACE FUNCTION reconcile_package_movement_v2() RETURNS trigger LANGUAGE plpgsql AS $$
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
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.evidence_kind IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE')) EXECUTE FUNCTION reconcile_package_movement_v2();

CREATE OR REPLACE FUNCTION guard_package_issue_scope_v2() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE op inventory_package_operations; layer stock_layers;
BEGIN
 SELECT * INTO STRICT op FROM inventory_package_operations WHERE id=NEW.operation_id;
 IF op.evidence_kind NOT IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN','COUNT_VARIANCE') THEN RAISE EXCEPTION 'Movement draws and allocations belong to movement evidence only'; END IF;
 IF TG_TABLE_NAME='inventory_package_issue_draws' THEN
  IF op.evidence_kind='COUNT_VARIANCE' THEN RAISE EXCEPTION 'A count variance states a counted composition, not a draw'; END IF;
  IF NEW.item_packaging_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item_packagings p WHERE p.id=NEW.item_packaging_id AND p.item_id=op.item_id) THEN
   RAISE EXCEPTION 'Issue draw names a package of another item'; END IF;
 ELSE
  SELECT * INTO STRICT layer FROM stock_layers WHERE id=NEW.stock_layer_id;
  IF layer.company_id<>NEW.company_id OR layer.item_id<>op.item_id OR layer.location_id<>op.location_id OR layer.batch_id IS DISTINCT FROM op.batch_id OR layer.serial_no IS NOT NULL THEN
   RAISE EXCEPTION 'Issue allocation does not match stock scope'; END IF;
 END IF;
 RETURN NEW;
END $$;
DROP TRIGGER package_scope ON inventory_package_issue_draws;
DROP TRIGGER package_scope ON inventory_package_issue_allocations;
CREATE TRIGGER package_scope BEFORE INSERT ON inventory_package_issue_draws FOR EACH ROW EXECUTE FUNCTION guard_package_issue_scope_v2();
CREATE TRIGGER package_scope BEFORE INSERT ON inventory_package_issue_allocations FOR EACH ROW EXECUTE FUNCTION guard_package_issue_scope_v2();

CREATE OR REPLACE FUNCTION require_authoritative_move_evidence_v2() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE after_id bigint; present boolean;
BEGIN
 SELECT a.after_stock_move_id INTO after_id FROM inventory_package_activations a JOIN items i ON i.package_tracking_activation_id=a.id WHERE i.id=NEW.item_id;
 IF after_id IS NULL OR NEW.id<=after_id THEN RETURN NULL; END IF;
 IF NEW.movement_kind='ISSUE' THEN
  present := EXISTS (SELECT 1 FROM inventory_package_operations o WHERE o.company_id=NEW.company_id AND o.stock_move_id=NEW.id AND o.evidence_kind IN ('ISSUE','COUNT_VARIANCE'));
 ELSIF NEW.movement_kind='TRANSFER' THEN
  present := EXISTS (SELECT 1 FROM inventory_package_operations o WHERE o.company_id=NEW.company_id AND o.stock_move_id=NEW.id AND o.evidence_kind='TRANSFER_OUT')
         AND EXISTS (SELECT 1 FROM inventory_package_operations o WHERE o.company_id=NEW.company_id AND o.stock_move_id=NEW.id AND o.evidence_kind='TRANSFER_IN');
 ELSIF NEW.movement_kind='RECEIPT' THEN
  present := EXISTS (SELECT 1 FROM inventory_package_operations o WHERE o.company_id=NEW.company_id AND o.stock_move_id=NEW.id AND o.evidence_kind IN ('RECEIPT_IN','COUNT_VARIANCE'));
 ELSE
  present := false;
 END IF;
 IF NOT present THEN RAISE EXCEPTION 'A stock move of an item with grouped package tracking needs package evidence'; END IF;
 RETURN NULL;
END $$;
DROP TRIGGER authoritative_move_evidence ON stock_moves;
CREATE CONSTRAINT TRIGGER authoritative_move_evidence AFTER INSERT ON stock_moves
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_authoritative_move_evidence_v2();
SQL);
    }

    public function down(): void
    {
        if (DB::table('inventory_package_operations')->whereNotNull('physical_count_line_id')->exists()) {
            throw new RuntimeException('Package count evidence exists; restore a compatible application instead of discarding history.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER authoritative_move_evidence ON stock_moves;
CREATE CONSTRAINT TRIGGER authoritative_move_evidence AFTER INSERT ON stock_moves
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_authoritative_move_evidence();
DROP FUNCTION require_authoritative_move_evidence_v2();
DROP TRIGGER package_scope ON inventory_package_issue_draws;
DROP TRIGGER package_scope ON inventory_package_issue_allocations;
CREATE TRIGGER package_scope BEFORE INSERT ON inventory_package_issue_draws FOR EACH ROW EXECUTE FUNCTION guard_package_issue_scope();
CREATE TRIGGER package_scope BEFORE INSERT ON inventory_package_issue_allocations FOR EACH ROW EXECUTE FUNCTION guard_package_issue_scope();
DROP FUNCTION guard_package_issue_scope_v2();
DROP TRIGGER package_issue_conservation ON inventory_package_operations;
CREATE CONSTRAINT TRIGGER package_issue_conservation AFTER INSERT ON inventory_package_operations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.evidence_kind IN ('ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN')) EXECUTE FUNCTION reconcile_package_movement();
DROP FUNCTION reconcile_package_movement_v2();
DROP TRIGGER package_operation_lineage ON inventory_package_operations;
CREATE TRIGGER package_operation_lineage BEFORE INSERT ON inventory_package_operations
FOR EACH ROW EXECUTE FUNCTION guard_package_operation_lineage_v4();
DROP FUNCTION guard_package_operation_lineage_v5();
DROP TRIGGER item_package_policy_pinned ON items;
CREATE TRIGGER item_package_policy_pinned BEFORE UPDATE ON items FOR EACH ROW EXECUTE FUNCTION freeze_item_package_policy_v2();
DROP FUNCTION freeze_item_package_policy_v3();
CREATE OR REPLACE FUNCTION guard_physical_count_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE count_status text; count_warehouse bigint; location_warehouse bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'physical count lines cannot be deleted' USING ERRCODE = '23514'; END IF;
    IF TG_OP = 'INSERT' THEN
        SELECT status, warehouse_id INTO count_status, count_warehouse FROM physical_counts WHERE id = NEW.physical_count_id;
        SELECT w.id INTO location_warehouse FROM locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.id = NEW.location_id;
        IF count_status <> 'DRAFT' THEN RAISE EXCEPTION 'lines can only be added to a draft physical count' USING ERRCODE = '23514'; END IF;
        IF location_warehouse IS DISTINCT FROM count_warehouse THEN RAISE EXCEPTION 'physical count line location must belong to the count warehouse' USING ERRCODE = '23514'; END IF;
        RETURN NEW;
    END IF;
    IF NEW.company_id <> OLD.company_id OR NEW.physical_count_id <> OLD.physical_count_id
       OR NEW.item_id <> OLD.item_id OR NEW.location_id <> OLD.location_id
       OR NEW.batch_id IS DISTINCT FROM OLD.batch_id OR NEW.serial_no IS DISTINCT FROM OLD.serial_no
       OR NEW.book_qty <> OLD.book_qty
    THEN RAISE EXCEPTION 'physical count line identity is immutable' USING ERRCODE = '23514'; END IF;
    IF OLD.posted_stock_move_id IS NOT NULL AND NEW.posted_stock_move_id IS DISTINCT FROM OLD.posted_stock_move_id
    THEN RAISE EXCEPTION 'a posted physical count line cannot be re-posted' USING ERRCODE = '23514'; END IF;
    IF (NEW.counted_qty IS DISTINCT FROM OLD.counted_qty OR NEW.found_unit_cost IS DISTINCT FROM OLD.found_unit_cost) THEN
        SELECT status INTO count_status FROM physical_counts WHERE id = NEW.physical_count_id;
        IF count_status <> 'DRAFT' THEN RAISE EXCEPTION 'a line can only be counted while its physical count is draft' USING ERRCODE = '23514'; END IF;
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER package_tracked_level ON inventory_package_states;
DROP FUNCTION guard_package_tracked_level();
DO $$ DECLARE constraint_name text;
BEGIN
 FOR constraint_name IN SELECT conname FROM pg_constraint
 WHERE conrelid='inventory_package_operations'::regclass AND contype='c'
 AND pg_get_constraintdef(oid) LIKE '%evidence_kind%'
 LOOP EXECUTE format('ALTER TABLE inventory_package_operations DROP CONSTRAINT %I',constraint_name); END LOOP;
END $$;
SQL);
        DB::statement("ALTER TABLE inventory_package_operations ADD CONSTRAINT package_operation_kind CHECK (evidence_kind IN ('COUNTED','RECEIPT','OPEN','UNPACK','ISSUE','TRANSFER_OUT','TRANSFER_IN','RECEIPT_IN') AND length(trim(reason))>0 AND length(trim(request_key))>0 AND payload_hash ~ '^[0-9a-f]{64}$' AND ((evidence_kind='COUNTED' AND source_receipt_line_id IS NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL) OR (evidence_kind='RECEIPT' AND source_receipt_line_id IS NOT NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL) OR (evidence_kind IN ('OPEN','UNPACK') AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NOT NULL AND selected_count IS NOT NULL AND selected_count>0 AND stock_move_id IS NULL) OR (evidence_kind='ISSUE' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id) OR (evidence_kind='TRANSFER_OUT' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id) OR (evidence_kind='TRANSFER_IN' AND source_receipt_line_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id) OR (evidence_kind='RECEIPT_IN' AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id)))");
        DB::statement('DROP INDEX package_operation_count_line_unique');
        Schema::table('inventory_package_operations', function (Blueprint $t): void {
            $t->dropForeign('package_operation_count_line');
            $t->dropColumn('physical_count_line_id');
        });
        Schema::table('physical_count_lines', function (Blueprint $t): void {
            $t->dropForeign('pcl_package_operation_fk');
            $t->dropColumn(['grouped', 'package_operation_id', 'package_watermark']);
        });
        Schema::dropIfExists('physical_count_line_compositions');
        Schema::dropIfExists('inventory_package_tracked_levels');
    }
};
