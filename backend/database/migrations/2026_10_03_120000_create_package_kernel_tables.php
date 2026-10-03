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
        // Foundation only: no active tracking policy, API or stock writer uses these tables.
        Schema::create('inventory_package_operations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('location_id');
            $t->unsignedBigInteger('batch_id')->nullable();
            $t->string('request_key', 100);
            $t->string('payload_hash', 64);
            $t->string('evidence_kind', 20);
            $t->unsignedBigInteger('actor_id');
            $t->foreign(['company_id', 'actor_id'])->references(['company_id', 'id'])->on('users');
            $t->text('reason');
            $t->timestampTz('recorded_at');
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'request_key']);
            foreach (['item_id' => 'items', 'location_id' => 'locations', 'batch_id' => 'stock_batches'] as $field => $table) {
                // Locations inherit company ownership through their warehouse; validated below.
                if ($field === 'location_id') {
                    $t->foreign($field)->references('id')->on($table);
                } else {
                    $t->foreign(['company_id', $field])->references(['company_id', 'id'])->on($table);
                }
            }
            $t->index(['company_id', 'item_id', 'location_id', 'id'], 'package_operations_scope');
        });
        Schema::create('inventory_package_states', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('operation_id');
            $t->unsignedBigInteger('item_packaging_id')->nullable();
            $t->string('form', 20);
            $t->string('availability', 20);
            $t->string('packaging_code')->nullable();
            $t->string('packaging_name')->nullable();
            $t->unsignedInteger('sealed_count')->nullable();
            $t->decimal('content_quantity', 19, 4)->nullable();
            $t->decimal('stock_quantity', 19, 4);
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'operation_id'])->references(['company_id', 'id'])->on('inventory_package_operations');
            $t->foreign(['company_id', 'item_packaging_id'])->references(['company_id', 'id'])->on('item_packagings');
        });
        Schema::create('inventory_package_contributions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('state_id');
            $t->unsignedBigInteger('stock_layer_id');
            $t->unsignedInteger('position');
            $t->decimal('quantity', 19, 4);
            $t->unique(['company_id', 'state_id', 'stock_layer_id'], 'package_contribution_layer');
            $t->unique(['company_id', 'state_id', 'position'], 'package_contribution_position');
            $t->foreign(['company_id', 'state_id'])->references(['company_id', 'id'])->on('inventory_package_states');
            $t->foreign(['company_id', 'stock_layer_id'])->references(['company_id', 'id'])->on('stock_layers');
        });
        DB::statement("ALTER TABLE inventory_package_operations ADD CHECK (evidence_kind IN ('COUNTED', 'RECEIPT') AND length(trim(reason)) > 0 AND length(trim(request_key)) > 0 AND payload_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE inventory_package_states ADD CHECK (stock_quantity > 0 AND availability IN ('HELD', 'USABLE') AND ((form = 'SEALED' AND item_packaging_id IS NOT NULL AND sealed_count IS NOT NULL AND content_quantity IS NOT NULL AND sealed_count > 0 AND content_quantity > 0 AND stock_quantity = sealed_count * content_quantity AND packaging_code IS NOT NULL AND packaging_name IS NOT NULL) OR (form = 'OPEN_LOOSE' AND item_packaging_id IS NULL AND sealed_count IS NULL AND content_quantity IS NULL AND packaging_code IS NULL AND packaging_name IS NULL)))");
        DB::statement('ALTER TABLE inventory_package_contributions ADD CHECK (quantity > 0 AND position > 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_package_kernel_scope() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE op inventory_package_operations; layer stock_layers;
BEGIN
 IF TG_TABLE_NAME = 'inventory_package_operations' THEN
  IF NOT EXISTS (SELECT 1 FROM locations l JOIN warehouses w ON w.id=l.warehouse_id WHERE l.id=NEW.location_id AND w.company_id=NEW.company_id) THEN
   RAISE EXCEPTION 'Package location belongs to another company';
  END IF;
  IF NEW.batch_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM stock_batches b WHERE b.id=NEW.batch_id AND b.item_id=NEW.item_id) THEN
   RAISE EXCEPTION 'Package batch belongs to another item';
  END IF;
 ELSIF TG_TABLE_NAME = 'inventory_package_states' THEN
  SELECT * INTO STRICT op FROM inventory_package_operations WHERE id=NEW.operation_id;
  IF NEW.item_packaging_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM item_packagings p WHERE p.id=NEW.item_packaging_id AND p.item_id=op.item_id AND p.stock_quantity=NEW.content_quantity AND p.code=NEW.packaging_code AND p.name=NEW.packaging_name) THEN
   RAISE EXCEPTION 'Package identity does not match operation item and content';
  END IF;
 ELSE
  SELECT o.* INTO STRICT op FROM inventory_package_operations o JOIN inventory_package_states s ON s.operation_id=o.id WHERE s.id=NEW.state_id;
  SELECT * INTO STRICT layer FROM stock_layers WHERE id=NEW.stock_layer_id;
  IF layer.company_id<>NEW.company_id OR layer.item_id<>op.item_id OR layer.location_id<>op.location_id OR layer.batch_id IS DISTINCT FROM op.batch_id OR layer.serial_no IS NOT NULL THEN
   RAISE EXCEPTION 'Package contribution does not match stock scope';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION reject_package_kernel_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Package kernel evidence is immutable'; END $$;
SQL);
        foreach (['operations', 'states', 'contributions'] as $suffix) {
            $table = 'inventory_package_'.$suffix;
            DB::statement("CREATE TRIGGER package_scope BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION guard_package_kernel_scope()");
            DB::statement("CREATE TRIGGER package_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_package_kernel_mutation()");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reconcile_package_kernel_state() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expected numeric; actual numeric;
BEGIN
 SELECT stock_quantity INTO expected FROM inventory_package_states WHERE id=NEW.state_id;
 SELECT coalesce(sum(quantity),0) INTO actual FROM inventory_package_contributions WHERE state_id=NEW.state_id;
 IF actual<>expected THEN RAISE EXCEPTION 'Package contributions do not reconcile'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER package_contribution_total AFTER INSERT ON inventory_package_contributions
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reconcile_package_kernel_state();
CREATE OR REPLACE FUNCTION require_package_kernel_contributions() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM inventory_package_contributions WHERE state_id=NEW.id) THEN
  RAISE EXCEPTION 'Package state requires contributions';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER package_state_contributions AFTER INSERT ON inventory_package_states
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_package_kernel_contributions();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_package_contributions');
        Schema::dropIfExists('inventory_package_states');
        Schema::dropIfExists('inventory_package_operations');
        DB::statement('DROP FUNCTION IF EXISTS guard_package_kernel_scope(), reject_package_kernel_mutation(), reconcile_package_kernel_state(), require_package_kernel_contributions()');
    }
};
