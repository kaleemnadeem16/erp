<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_package_operations', function (Blueprint $t): void {
            $t->unsignedBigInteger('predecessor_id')->nullable();
            $t->unsignedBigInteger('selected_state_id')->nullable();
            $t->unsignedInteger('selected_count')->nullable();
            $t->foreign(['company_id', 'predecessor_id'], 'package_operation_predecessor')->references(['company_id', 'id'])->on('inventory_package_operations');
            $t->foreign(['company_id', 'selected_state_id'], 'package_operation_selection')->references(['company_id', 'id'])->on('inventory_package_states');
        });
        Schema::table('inventory_package_states', function (Blueprint $t): void {
            $t->unsignedBigInteger('origin_state_id')->nullable();
            $t->foreign(['company_id', 'origin_state_id'], 'package_state_origin')->references(['company_id', 'id'])->on('inventory_package_states');
        });
        $this->dropKindChecks();
        DB::statement("ALTER TABLE inventory_package_operations ADD CONSTRAINT package_operation_kind CHECK (evidence_kind IN ('COUNTED','RECEIPT','OPEN','UNPACK') AND length(trim(reason))>0 AND length(trim(request_key))>0 AND payload_hash ~ '^[0-9a-f]{64}$' AND ((evidence_kind='COUNTED' AND source_receipt_line_id IS NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL) OR (evidence_kind='RECEIPT' AND source_receipt_line_id IS NOT NULL AND predecessor_id IS NULL AND selected_state_id IS NULL AND selected_count IS NULL) OR (evidence_kind IN ('OPEN','UNPACK') AND source_receipt_line_id IS NULL AND predecessor_id IS NOT NULL AND selected_state_id IS NOT NULL AND selected_count IS NOT NULL AND selected_count>0)))");
        DB::statement('CREATE UNIQUE INDEX package_operation_successor ON inventory_package_operations(company_id,predecessor_id) WHERE predecessor_id IS NOT NULL');
        $this->scopeFunction(true);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_package_operation_lineage() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent inventory_package_operations; chosen inventory_package_states;
BEGIN
 IF NEW.predecessor_id IS NOT NULL THEN
  PERFORM id FROM locations WHERE id=NEW.location_id FOR UPDATE;
  SELECT * INTO STRICT parent FROM inventory_package_operations WHERE id=NEW.predecessor_id;
  SELECT * INTO STRICT chosen FROM inventory_package_states WHERE id=NEW.selected_state_id;
  IF parent.company_id<>NEW.company_id OR parent.item_id<>NEW.item_id OR parent.location_id<>NEW.location_id
   OR parent.batch_id IS DISTINCT FROM NEW.batch_id OR parent.stock_move_watermark IS DISTINCT FROM NEW.stock_move_watermark
   OR parent.evidence_kind NOT IN ('COUNTED','OPEN','UNPACK') OR chosen.operation_id<>parent.id
   OR chosen.form<>'SEALED' OR chosen.availability<>'USABLE' OR NEW.selected_count>chosen.sealed_count
   OR parent.id<>(SELECT max(id) FROM inventory_package_operations WHERE company_id=NEW.company_id
    AND item_id=NEW.item_id AND location_id=NEW.location_id AND batch_id IS NOT DISTINCT FROM NEW.batch_id
    AND evidence_kind IN ('COUNTED','OPEN','UNPACK'))
  THEN RAISE EXCEPTION 'Opening lineage does not match predecessor scope and sealed selection'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER package_operation_lineage BEFORE INSERT ON inventory_package_operations
FOR EACH ROW EXECUTE FUNCTION guard_package_operation_lineage();
CREATE OR REPLACE FUNCTION reconcile_package_operation_lineage() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE chosen inventory_package_states; outer_pack item_packagings; inner_pack item_packagings;
BEGIN
 IF NEW.predecessor_id IS NOT NULL THEN
  IF NOT EXISTS (SELECT 1 FROM inventory_package_states WHERE operation_id=NEW.id) THEN
   RAISE EXCEPTION 'Opening successor requires a complete composition';
  END IF;
  IF EXISTS (
   WITH original AS (
    SELECT c.stock_layer_id,sum(c.quantity) quantity FROM inventory_package_states s
    JOIN inventory_package_contributions c ON c.state_id=s.id WHERE s.operation_id=NEW.predecessor_id GROUP BY c.stock_layer_id
   ), successor AS (
    SELECT c.stock_layer_id,sum(c.quantity) quantity FROM inventory_package_states s
    JOIN inventory_package_contributions c ON c.state_id=s.id WHERE s.operation_id=NEW.id GROUP BY c.stock_layer_id
   ) SELECT 1 FROM original FULL JOIN successor USING(stock_layer_id)
   WHERE original.quantity IS DISTINCT FROM successor.quantity
  ) THEN RAISE EXCEPTION 'Opening successor must preserve every stock layer contribution'; END IF;
  SELECT * INTO STRICT chosen FROM inventory_package_states WHERE id=NEW.selected_state_id;
  IF NEW.evidence_kind='UNPACK' THEN
   SELECT * INTO STRICT outer_pack FROM item_packagings WHERE id=chosen.item_packaging_id;
   SELECT * INTO inner_pack FROM item_packagings WHERE id=outer_pack.inner_packaging_id;
   IF inner_pack.id IS NULL OR inner_pack.company_id<>NEW.company_id OR inner_pack.item_id<>NEW.item_id
    OR outer_pack.inner_count IS NULL OR inner_pack.stock_quantity*outer_pack.inner_count<>chosen.content_quantity
   THEN RAISE EXCEPTION 'Unpacking requires exactly reconciling inner sealed contents'; END IF;
  END IF;
  IF EXISTS (
   WITH changes AS (
    SELECT form,item_packaging_id,availability,stock_quantity quantity FROM inventory_package_states WHERE operation_id=NEW.predecessor_id
    UNION ALL SELECT chosen.form,chosen.item_packaging_id,chosen.availability,-chosen.content_quantity*NEW.selected_count
    UNION ALL SELECT CASE WHEN NEW.evidence_kind='OPEN' THEN 'OPEN_LOOSE' ELSE 'SEALED' END,
     CASE WHEN NEW.evidence_kind='OPEN' THEN NULL ELSE inner_pack.id END,chosen.availability,chosen.content_quantity*NEW.selected_count
   ), expected AS (
    SELECT form,item_packaging_id,availability,sum(quantity) quantity FROM changes
    GROUP BY form,item_packaging_id,availability HAVING sum(quantity)<>0
   ), actual AS (
    SELECT form,item_packaging_id,availability,sum(stock_quantity) quantity FROM inventory_package_states
    WHERE operation_id=NEW.id GROUP BY form,item_packaging_id,availability
   ) SELECT 1 FROM expected FULL JOIN actual ON expected.form=actual.form
    AND expected.item_packaging_id IS NOT DISTINCT FROM actual.item_packaging_id AND expected.availability=actual.availability
    WHERE expected.quantity IS DISTINCT FROM actual.quantity
  ) THEN RAISE EXCEPTION 'Opening successor composition does not match selected transformation'; END IF;
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER package_operation_conservation AFTER INSERT ON inventory_package_operations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reconcile_package_operation_lineage();
SQL);
    }

    public function down(): void
    {
        // Evidence cannot be discarded just to downgrade the schema.
        if (DB::table('inventory_package_operations')->whereIn('evidence_kind', ['OPEN', 'UNPACK'])->exists()) {
            throw new RuntimeException('Package opening evidence exists; restore a compatible application instead of discarding history.');
        }
        DB::statement('DROP TRIGGER package_operation_lineage ON inventory_package_operations');
        DB::statement('DROP TRIGGER package_operation_conservation ON inventory_package_operations');
        DB::statement('DROP FUNCTION guard_package_operation_lineage(), reconcile_package_operation_lineage()');
        $this->scopeFunction(false);
        $this->dropKindChecks();
        DB::statement('DROP INDEX package_operation_successor');
        Schema::table('inventory_package_states', function (Blueprint $t): void {
            $t->dropForeign('package_state_origin');
            $t->dropColumn('origin_state_id');
        });
        Schema::table('inventory_package_operations', function (Blueprint $t): void {
            $t->dropForeign('package_operation_predecessor');
            $t->dropForeign('package_operation_selection');
            $t->dropColumn(['predecessor_id', 'selected_state_id', 'selected_count']);
        });
        DB::statement("ALTER TABLE inventory_package_operations ADD CHECK (evidence_kind IN ('COUNTED','RECEIPT') AND length(trim(reason))>0 AND length(trim(request_key))>0 AND payload_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE inventory_package_operations ADD CHECK ((evidence_kind='COUNTED' AND source_receipt_line_id IS NULL) OR (evidence_kind='RECEIPT' AND source_receipt_line_id IS NOT NULL))");
    }

    private function dropKindChecks(): void
    {
        // Original checks were unnamed; discover by their referenced column, not generated names.
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

    private function scopeFunction(bool $withOrigin): void
    {
        $originGuard = $withOrigin ? <<<'SQL'
  IF NEW.origin_state_id IS NOT NULL THEN
   IF NOT EXISTS (
    SELECT 1 FROM inventory_package_states prior WHERE prior.id=NEW.origin_state_id
    AND prior.company_id=NEW.company_id AND prior.operation_id=op.predecessor_id
    AND prior.item_packaging_id IS NOT DISTINCT FROM NEW.item_packaging_id
    AND prior.form=NEW.form AND prior.availability=NEW.availability
    AND prior.packaging_code IS NOT DISTINCT FROM NEW.packaging_code
    AND prior.packaging_name IS NOT DISTINCT FROM NEW.packaging_name
    AND prior.content_quantity IS NOT DISTINCT FROM NEW.content_quantity
   ) THEN RAISE EXCEPTION 'Inherited package identity must match the predecessor state'; END IF;
  ELSE
SQL : '';
        $originEnd = $withOrigin ? 'END IF;' : '';
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION guard_package_kernel_scope() RETURNS trigger LANGUAGE plpgsql AS \$\$
DECLARE op inventory_package_operations; layer stock_layers;
BEGIN
 IF TG_TABLE_NAME='inventory_package_operations' THEN
  IF NOT EXISTS (SELECT 1 FROM locations l JOIN warehouses w ON w.id=l.warehouse_id WHERE l.id=NEW.location_id AND w.company_id=NEW.company_id) THEN
   RAISE EXCEPTION 'Package location belongs to another company'; END IF;
  IF NEW.batch_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM stock_batches b WHERE b.id=NEW.batch_id AND b.item_id=NEW.item_id) THEN
   RAISE EXCEPTION 'Package batch belongs to another item'; END IF;
 ELSIF TG_TABLE_NAME='inventory_package_states' THEN
  SELECT * INTO STRICT op FROM inventory_package_operations WHERE id=NEW.operation_id;
  {$originGuard}
  IF NEW.item_packaging_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item_packagings p WHERE p.id=NEW.item_packaging_id AND p.item_id=op.item_id AND p.stock_quantity=NEW.content_quantity AND p.code=NEW.packaging_code AND p.name=NEW.packaging_name) THEN
   RAISE EXCEPTION 'Package identity does not match operation item and content'; END IF;
  {$originEnd}
 ELSE
  SELECT o.* INTO STRICT op FROM inventory_package_operations o JOIN inventory_package_states s ON s.operation_id=o.id WHERE s.id=NEW.state_id;
  SELECT * INTO STRICT layer FROM stock_layers WHERE id=NEW.stock_layer_id;
  IF layer.company_id<>NEW.company_id OR layer.item_id<>op.item_id OR layer.location_id<>op.location_id OR layer.batch_id IS DISTINCT FROM op.batch_id OR layer.serial_no IS NOT NULL THEN
   RAISE EXCEPTION 'Package contribution does not match stock scope'; END IF;
 END IF;
 RETURN NEW;
END \$\$;
SQL);
    }
};
