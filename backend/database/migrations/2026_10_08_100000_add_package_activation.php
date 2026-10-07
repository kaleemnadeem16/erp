<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0091: grouped package tracking is activated by one atomic transition. The item's mode becomes PACKAGE_BALANCE only together with an
 * immutable activation record, and the database re-proves at the flip that every scope holding stock has fresh, reconciled evidence.
 * There is no pending state and no deactivation. Existing items are untouched: their mode stays QUANTITY_ONLY and the triggers exit early.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_package_activations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('item_id');
            $t->string('request_key', 100);
            $t->string('payload_hash', 64);
            $t->unsignedBigInteger('actor_id');
            $t->text('reason');
            $t->string('prior_mode', 30);
            $t->string('new_mode', 30);
            // Stock moves up to this id predate activation; every later move of the item must carry package evidence.
            $t->unsignedBigInteger('after_stock_move_id');
            $t->timestampTz('activated_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            $t->unique(['company_id', 'item_id']);
            $t->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items');
            $t->foreign(['company_id', 'actor_id'])->references(['company_id', 'id'])->on('users');
        });
        DB::statement("ALTER TABLE inventory_package_activations ADD CONSTRAINT package_activation_shape CHECK (prior_mode='QUANTITY_ONLY' AND new_mode='PACKAGE_BALANCE' AND length(trim(reason))>0 AND length(trim(request_key))>0 AND payload_hash ~ '^[0-9a-f]{64}$' AND after_stock_move_id>=0)");
        Schema::create('inventory_package_activation_scopes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('activation_id');
            $t->unsignedBigInteger('location_id');
            $t->unsignedBigInteger('batch_id')->nullable();
            $t->unsignedBigInteger('operation_id');
            $t->decimal('quantity', 19, 4);
            $t->foreign(['company_id', 'activation_id'])->references(['company_id', 'id'])->on('inventory_package_activations');
            $t->foreign(['company_id', 'operation_id'])->references(['company_id', 'id'])->on('inventory_package_operations');
            $t->foreign('location_id')->references('id')->on('locations');
        });
        DB::statement('ALTER TABLE inventory_package_activation_scopes ADD CONSTRAINT package_activation_scope_positive CHECK (quantity>0)');
        DB::statement('CREATE UNIQUE INDEX package_activation_scope_unique ON inventory_package_activation_scopes(activation_id,location_id,coalesce(batch_id,0))');
        foreach (['inventory_package_activations', 'inventory_package_activation_scopes'] as $table) {
            DB::statement("CREATE TRIGGER package_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_package_kernel_mutation()");
        }

        Schema::table('items', function (Blueprint $t): void {
            $t->unsignedBigInteger('package_tracking_activation_id')->nullable();
            $t->foreign(['company_id', 'package_tracking_activation_id'])->references(['company_id', 'id'])->on('inventory_package_activations');
        });
        DB::statement('ALTER TABLE items DROP CONSTRAINT item_package_mode_gate');
        DB::statement("ALTER TABLE items ADD CONSTRAINT item_package_mode_gate CHECK ((package_tracking_mode='QUANTITY_ONLY' AND package_tracking_activation_id IS NULL) OR (package_tracking_mode='PACKAGE_BALANCE' AND package_tracking_activation_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION freeze_item_package_policy_v2() RETURNS trigger LANGUAGE plpgsql AS $$
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
 END IF;
 RETURN NEW;
END $$;
DROP TRIGGER item_package_policy_pinned ON items;
CREATE TRIGGER item_package_policy_pinned BEFORE UPDATE ON items FOR EACH ROW EXECUTE FUNCTION freeze_item_package_policy_v2();
SQL);
    }

    public function down(): void
    {
        if (DB::table('inventory_package_activations')->exists()) {
            throw new RuntimeException('Package activations exist and cannot be undone; restore a compatible application instead of discarding them.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER item_package_policy_pinned ON items;
CREATE TRIGGER item_package_policy_pinned BEFORE UPDATE ON items FOR EACH ROW EXECUTE FUNCTION freeze_item_package_policy();
DROP FUNCTION freeze_item_package_policy_v2();
ALTER TABLE items DROP CONSTRAINT item_package_mode_gate;
ALTER TABLE items ADD CONSTRAINT item_package_mode_gate CHECK (package_tracking_mode = 'QUANTITY_ONLY');
SQL);
        Schema::table('items', function (Blueprint $t): void {
            $t->dropForeign(['company_id', 'package_tracking_activation_id']);
            $t->dropColumn('package_tracking_activation_id');
        });
        Schema::dropIfExists('inventory_package_activation_scopes');
        Schema::dropIfExists('inventory_package_activations');
    }
};
