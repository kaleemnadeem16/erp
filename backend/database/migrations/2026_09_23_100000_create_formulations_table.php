<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('output_item_id');
            $table->string('code');
            $table->string('name');
            $table->decimal('output_qty', 19, 6)->default('1');
            $table->unsignedBigInteger('output_uom_id');
            // NULLABLE — the customer-override mechanism. NULL = company
            // default; see docs/domain/formulations.md "Customer-specific
            // formulations".
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status')->default('DRAFT');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'code']);
        });

        DB::statement('ALTER TABLE formulations ADD CONSTRAINT formulations_output_qty_check CHECK (output_qty > 0)');
        // items_company_id_id_unique and uoms_company_id_id_unique already exist
        // (2026_09_02_090000_create_item_suppliers_table.php,
        // 2026_08_21_090000_enforce_company_scoped_foreign_keys.php).
        DB::statement('ALTER TABLE formulations ADD CONSTRAINT formulations_output_item_company_fk FOREIGN KEY (company_id, output_item_id) REFERENCES items (company_id, id)');
        DB::statement('ALTER TABLE formulations ADD CONSTRAINT formulations_output_uom_company_fk FOREIGN KEY (company_id, output_uom_id) REFERENCES uoms (company_id, id)');
        DB::statement('ALTER TABLE customer_accounts ADD CONSTRAINT customer_accounts_company_id_id_unique UNIQUE (company_id, id)');
        DB::statement('ALTER TABLE formulations ADD CONSTRAINT formulations_customer_company_fk FOREIGN KEY (company_id, customer_id) REFERENCES customer_accounts (company_id, id)');
        // One formulation per (output item, customer) — customer_id NULL is the
        // company default slot. A plain unique() can't express "NULL is also a
        // distinct, unique value" the way this needs, hence a partial index for
        // the NULL case alongside the ordinary composite one.
        DB::statement('CREATE UNIQUE INDEX formulations_one_per_item_customer ON formulations (company_id, output_item_id, customer_id) WHERE customer_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX formulations_one_company_default_per_item ON formulations (company_id, output_item_id) WHERE customer_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('formulations');
        DB::statement('ALTER TABLE customer_accounts DROP CONSTRAINT customer_accounts_company_id_id_unique');
    }
};
