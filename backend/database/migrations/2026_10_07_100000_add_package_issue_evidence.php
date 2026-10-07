<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0090: an ISSUE evidence kind. An issue operation is a complete successor snapshot anchored on the stock move it accompanies,
 * with the physical draw lines and the allocator's actual layer vector stored beside it. The database re-proves, at commit, that
 * quantity, composition and per-layer attribution all conserve. Grouped mode only: container identity stays a future additive
 * extension (a nullable container_id exists on draw lines but is forced empty now; tracking_mode is recorded and constrained).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_package_operations', function (Blueprint $t): void {
            $t->unsignedBigInteger('stock_move_id')->nullable();
            // Existing evidence was written by grouped (PACKAGE_BALANCE) operations only.
            $t->string('tracking_mode', 30)->default('PACKAGE_BALANCE');
            $t->foreign(['company_id', 'stock_move_id'], 'package_operation_stock_move')->references(['company_id', 'id'])->on('stock_moves');
        });
        DB::statement('CREATE UNIQUE INDEX package_operation_move ON inventory_package_operations(company_id,stock_move_id) WHERE stock_move_id IS NOT NULL');
        DB::statement("ALTER TABLE inventory_package_operations ADD CONSTRAINT package_operation_mode CHECK (tracking_mode IN ('PACKAGE_BALANCE'))");
        $this->dropKindChecks();
        DB::statement("ALTER TABLE inventory_package_operations ADD CONSTRAINT package_operation_kind CHECK (evidence_kind IN ('COUNTED','RECEIPT','OPEN','UNPACK','ISSUE') AND length(trim(reason))>0 AND length(trim(request_key))>0 AND payload_hash ~ '^[0-9a-f]{64}$' AND ((evidence_kind='COUNTED' AND source_receipt_line_id IS NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL) OR (evidence_kind='RECEIPT' AND source_receipt_line_id IS NOT NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NULL) OR (evidence_kind IN ('OPEN','UNPACK') AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NOT NULL AND selected_count IS NOT NULL AND selected_count>0 AND stock_move_id IS NULL) OR (evidence_kind='ISSUE' AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NULL AND selected_count IS NULL AND stock_move_id IS NOT NULL AND stock_move_watermark=stock_move_id)))");

        Schema::create('inventory_package_issue_draws', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('operation_id');
            $t->unsignedInteger('line_no');
            $t->string('kind', 10);
            $t->unsignedBigInteger('item_packaging_id')->nullable();
            $t->unsignedInteger('whole_count')->nullable();
            $t->decimal('quantity', 19, 4);
            // Reserved for container-level tracking (ADR 0090 §11). Grouped evidence never names a container.
            $t->unsignedBigInteger('container_id')->nullable();
            $t->unique(['operation_id', 'line_no']);
            $t->foreign(['company_id', 'operation_id'])->references(['company_id', 'id'])->on('inventory_package_operations');
            $t->foreign(['company_id', 'item_packaging_id'])->references(['company_id', 'id'])->on('item_packagings');
        });
        DB::statement("ALTER TABLE inventory_package_issue_draws ADD CONSTRAINT package_issue_draw_shape CHECK (quantity>0 AND line_no>0 AND container_id IS NULL AND ((kind='SEALED' AND item_packaging_id IS NOT NULL AND whole_count>0) OR (kind='LOOSE' AND item_packaging_id IS NULL AND whole_count IS NULL)))");
        Schema::create('inventory_package_issue_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('operation_id');
            $t->unsignedBigInteger('stock_layer_id');
            $t->decimal('quantity', 19, 4);
            $t->unique(['operation_id', 'stock_layer_id'], 'package_issue_allocation_layer');
            $t->foreign(['company_id', 'operation_id'])->references(['company_id', 'id'])->on('inventory_package_operations');
            $t->foreign(['company_id', 'stock_layer_id'])->references(['company_id', 'id'])->on('stock_layers');
        });
        DB::statement('ALTER TABLE inventory_package_issue_allocations ADD CONSTRAINT package_issue_allocation_positive CHECK (quantity>0)');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_package_issue_scope() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE op inventory_package_operations; layer stock_layers;
BEGIN
 SELECT * INTO STRICT op FROM inventory_package_operations WHERE id=NEW.operation_id;
 IF op.evidence_kind<>'ISSUE' THEN RAISE EXCEPTION 'Issue draws and allocations belong to ISSUE evidence only'; END IF;
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
SQL);
        foreach (['draws', 'allocations'] as $suffix) {
            $table = 'inventory_package_issue_'.$suffix;
            DB::statement("CREATE TRIGGER package_scope BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION guard_package_issue_scope()");
            DB::statement("CREATE TRIGGER package_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_package_kernel_mutation()");
        }

        // The lineage guard learns that ISSUE is a valid parent and the latest-evidence kind, and validates an ISSUE's anchor move.
        // The previous function stays in place so down() can restore it; the trigger is what is swapped.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_package_operation_lineage_v2() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent inventory_package_operations; chosen inventory_package_states; mv stock_moves;
BEGIN
 IF NEW.predecessor_id IS NOT NULL THEN
  PERFORM id FROM locations WHERE id=NEW.location_id FOR UPDATE;
  SELECT * INTO STRICT parent FROM inventory_package_operations WHERE id=NEW.predecessor_id;
  IF parent.company_id<>NEW.company_id OR parent.item_id<>NEW.item_id OR parent.location_id<>NEW.location_id
   OR parent.batch_id IS DISTINCT FROM NEW.batch_id
   OR parent.evidence_kind NOT IN ('COUNTED','OPEN','UNPACK','ISSUE')
   OR parent.id<>(SELECT max(id) FROM inventory_package_operations WHERE company_id=NEW.company_id
    AND item_id=NEW.item_id AND location_id=NEW.location_id AND batch_id IS NOT DISTINCT FROM NEW.batch_id
    AND evidence_kind IN ('COUNTED','OPEN','UNPACK','ISSUE'))
  THEN RAISE EXCEPTION 'Package lineage does not match predecessor scope'; END IF;
  IF NEW.evidence_kind='ISSUE' THEN
   SELECT * INTO STRICT mv FROM stock_moves WHERE id=NEW.stock_move_id;
   IF parent.stock_move_watermark IS NULL OR mv.company_id<>NEW.company_id OR mv.movement_kind<>'ISSUE'
    OR mv.item_id<>NEW.item_id OR mv.from_location_id IS DISTINCT FROM NEW.location_id OR mv.batch_id IS DISTINCT FROM NEW.batch_id
    OR mv.serial_no IS NOT NULL OR mv.id<=parent.stock_move_watermark
    OR EXISTS (SELECT 1 FROM stock_moves o WHERE o.company_id=NEW.company_id AND o.item_id=NEW.item_id AND o.batch_id IS NOT DISTINCT FROM NEW.batch_id
     AND o.serial_no IS NULL AND (o.from_location_id=NEW.location_id OR o.to_location_id=NEW.location_id)
     AND o.id>parent.stock_move_watermark AND o.id<>mv.id)
   THEN RAISE EXCEPTION 'Issue evidence must follow its predecessor with its own move as the only later movement'; END IF;
  ELSE
   SELECT * INTO STRICT chosen FROM inventory_package_states WHERE id=NEW.selected_state_id;
   IF parent.stock_move_watermark IS DISTINCT FROM NEW.stock_move_watermark OR chosen.operation_id<>parent.id
    OR chosen.form<>'SEALED' OR chosen.availability<>'USABLE' OR NEW.selected_count>chosen.sealed_count
   THEN RAISE EXCEPTION 'Opening lineage does not match predecessor scope and sealed selection'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
DROP TRIGGER package_operation_lineage ON inventory_package_operations;
CREATE TRIGGER package_operation_lineage BEFORE INSERT ON inventory_package_operations
FOR EACH ROW EXECUTE FUNCTION guard_package_operation_lineage_v2();
SQL);

        // The existing opening conservation check does not apply to ISSUE; ISSUE has its own.
        DB::unprepared(<<<'SQL'
DROP TRIGGER package_operation_conservation ON inventory_package_operations;
CREATE CONSTRAINT TRIGGER package_operation_conservation AFTER INSERT ON inventory_package_operations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.evidence_kind IN ('OPEN','UNPACK')) EXECUTE FUNCTION reconcile_package_operation_lineage();
CREATE OR REPLACE FUNCTION reconcile_package_issue() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE mv stock_moves; drawn numeric; allocated numeric;
BEGIN
 SELECT * INTO STRICT mv FROM stock_moves WHERE id=NEW.stock_move_id;
 SELECT coalesce(sum(quantity),0) INTO drawn FROM inventory_package_issue_draws WHERE operation_id=NEW.id;
 IF NOT EXISTS (SELECT 1 FROM inventory_package_states WHERE operation_id=NEW.id)
  AND (SELECT coalesce(sum(stock_quantity),0) FROM inventory_package_states WHERE operation_id=NEW.predecessor_id)<>drawn THEN
  RAISE EXCEPTION 'Issue successor requires a complete composition (it may be empty only when everything on hand was drawn)'; END IF;
 SELECT coalesce(sum(quantity),0) INTO allocated FROM inventory_package_issue_allocations WHERE operation_id=NEW.id;
 IF drawn<=0 OR drawn<>mv.qty OR allocated<>mv.qty THEN
  RAISE EXCEPTION 'Issue draw and allocation must each equal the posted stock quantity'; END IF;
 IF EXISTS (
  SELECT 1 FROM inventory_package_issue_draws d JOIN item_packagings p ON p.id=d.item_packaging_id
  WHERE d.operation_id=NEW.id AND d.kind='SEALED' AND d.quantity<>d.whole_count*p.stock_quantity
 ) THEN RAISE EXCEPTION 'A sealed draw must equal whole packages times their content'; END IF;
 IF EXISTS (
  WITH original AS (
   SELECT c.stock_layer_id,sum(c.quantity) quantity FROM inventory_package_states s
   JOIN inventory_package_contributions c ON c.state_id=s.id WHERE s.operation_id=NEW.predecessor_id GROUP BY c.stock_layer_id
  ), taken AS (
   SELECT stock_layer_id,quantity FROM inventory_package_issue_allocations WHERE operation_id=NEW.id
  ), successor AS (
   SELECT c.stock_layer_id,sum(c.quantity) quantity FROM inventory_package_states s
   JOIN inventory_package_contributions c ON c.state_id=s.id WHERE s.operation_id=NEW.id GROUP BY c.stock_layer_id
  ), layers AS (SELECT stock_layer_id FROM original UNION SELECT stock_layer_id FROM taken UNION SELECT stock_layer_id FROM successor)
  SELECT 1 FROM layers l LEFT JOIN original o USING(stock_layer_id) LEFT JOIN taken t USING(stock_layer_id) LEFT JOIN successor s USING(stock_layer_id)
  WHERE coalesce(o.quantity,0)-coalesce(t.quantity,0) IS DISTINCT FROM coalesce(s.quantity,0)
 ) THEN RAISE EXCEPTION 'Issue successor must equal the predecessor less the allocated layer quantities'; END IF;
 IF EXISTS (
  WITH changes AS (
   SELECT form,item_packaging_id,availability,stock_quantity quantity FROM inventory_package_states WHERE operation_id=NEW.predecessor_id
   UNION ALL SELECT 'SEALED',d.item_packaging_id,'USABLE',-d.quantity FROM inventory_package_issue_draws d WHERE d.operation_id=NEW.id AND d.kind='SEALED'
   UNION ALL SELECT 'OPEN_LOOSE',NULL,'USABLE',-d.quantity FROM inventory_package_issue_draws d WHERE d.operation_id=NEW.id AND d.kind='LOOSE'
  ), expected AS (
   SELECT form,item_packaging_id,availability,sum(quantity) quantity FROM changes GROUP BY form,item_packaging_id,availability HAVING sum(quantity)<>0
  ), actual AS (
   SELECT form,item_packaging_id,availability,sum(stock_quantity) quantity FROM inventory_package_states WHERE operation_id=NEW.id
   GROUP BY form,item_packaging_id,availability
  ) SELECT 1 FROM expected FULL JOIN actual ON expected.form=actual.form
   AND expected.item_packaging_id IS NOT DISTINCT FROM actual.item_packaging_id AND expected.availability=actual.availability
   WHERE expected.quantity IS DISTINCT FROM actual.quantity
 ) THEN RAISE EXCEPTION 'Issue successor composition does not match the physical draw'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER package_issue_conservation AFTER INSERT ON inventory_package_operations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.evidence_kind='ISSUE') EXECUTE FUNCTION reconcile_package_issue();
SQL);
    }

    public function down(): void
    {
        // Evidence cannot be discarded just to downgrade the schema.
        if (DB::table('inventory_package_operations')->where('evidence_kind', 'ISSUE')->exists()) {
            throw new RuntimeException('Package issue evidence exists; restore a compatible application instead of discarding history.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER package_issue_conservation ON inventory_package_operations;
DROP FUNCTION reconcile_package_issue();
DROP TRIGGER package_operation_conservation ON inventory_package_operations;
CREATE CONSTRAINT TRIGGER package_operation_conservation AFTER INSERT ON inventory_package_operations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reconcile_package_operation_lineage();
DROP TRIGGER package_operation_lineage ON inventory_package_operations;
CREATE TRIGGER package_operation_lineage BEFORE INSERT ON inventory_package_operations
FOR EACH ROW EXECUTE FUNCTION guard_package_operation_lineage();
DROP FUNCTION guard_package_operation_lineage_v2();
SQL);
        Schema::dropIfExists('inventory_package_issue_allocations');
        Schema::dropIfExists('inventory_package_issue_draws');
        DB::statement('DROP FUNCTION guard_package_issue_scope()');
        $this->dropKindChecks();
        DB::statement('DROP INDEX package_operation_move');
        DB::statement('ALTER TABLE inventory_package_operations DROP CONSTRAINT package_operation_mode');
        Schema::table('inventory_package_operations', function (Blueprint $t): void {
            $t->dropForeign('package_operation_stock_move');
            $t->dropColumn(['stock_move_id', 'tracking_mode']);
        });
        DB::statement("ALTER TABLE inventory_package_operations ADD CONSTRAINT package_operation_kind CHECK (evidence_kind IN ('COUNTED','RECEIPT','OPEN','UNPACK') AND length(trim(reason))>0 AND length(trim(request_key))>0 AND payload_hash ~ '^[0-9a-f]{64}$' AND ((evidence_kind='COUNTED' AND source_receipt_line_id IS NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL) OR (evidence_kind='RECEIPT' AND source_receipt_line_id IS NOT NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL) OR (evidence_kind IN ('OPEN','UNPACK') AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NOT NULL AND selected_count IS NOT NULL AND selected_count>0)))");
    }

    private function dropKindChecks(): void
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
    }
};
