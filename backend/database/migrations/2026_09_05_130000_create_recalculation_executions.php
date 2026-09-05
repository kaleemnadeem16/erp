<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0040 "Approved execution lifetime" + "Append-only linked correction
 * package": one immutable execution fact per RecalculationRequest (unique —
 * a request executes at most once, ever, since the first execution posts the
 * real StockMove that RequestStockRecalculation's own "source already has a
 * posted movement" guard then blocks a second request from ever targeting
 * again).
 *
 * This is the Inventory-owned slice of the eventual multi-owner
 * CORRECTION_PACKAGE_* machinery, not that machinery itself: Sales, Job
 * Execution and Accounting — the other potential owners of a correction
 * delta — don't exist yet, so every case this codebase can execute today is
 * "remaining stock," the one slice ADR 0040 lets Inventory own outright. No
 * lifecycle-state column, no deferred-obligation tracking: those describe
 * coordination with owners that aren't there to coordinate with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recalculation_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->unsignedBigInteger('recalculation_request_id');
            $table->unsignedBigInteger('impact_version_id');
            $table->unsignedBigInteger('stock_move_id');
            $table->unsignedBigInteger('executed_by');
            $table->timestampTz('executed_at');
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'recalculation_request_id']);
        });
        DB::statement('ALTER TABLE recalculation_executions ADD CONSTRAINT re_request_fk FOREIGN KEY (company_id, recalculation_request_id) REFERENCES recalculation_requests (company_id, id)');
        DB::statement('ALTER TABLE recalculation_executions ADD CONSTRAINT re_version_fk FOREIGN KEY (company_id, impact_version_id) REFERENCES recalculation_impact_versions (company_id, id)');
        DB::statement('ALTER TABLE recalculation_executions ADD CONSTRAINT re_move_fk FOREIGN KEY (company_id, stock_move_id) REFERENCES stock_moves (company_id, id)');
        DB::statement('ALTER TABLE recalculation_executions ADD CONSTRAINT re_actor_fk FOREIGN KEY (company_id, executed_by) REFERENCES users (company_id, id)');
        DB::statement('CREATE TRIGGER re_immutable BEFORE UPDATE OR DELETE ON recalculation_executions FOR EACH ROW EXECUTE FUNCTION prevent_recalculation_workflow_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('recalculation_executions');
    }
};
