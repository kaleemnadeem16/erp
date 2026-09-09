<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The stock ledger's read APIs both default to newest-first with no filter —
 * `GET /api/v1/stock-moves` (the Stock Ledger screen's own first page) orders
 * by `moved_at DESC, id` and `GET /api/v1/stock-layers` by `received_at DESC,
 * id`. Every existing index on these tables leads with `item_id` after
 * `company_id`, so neither default could be served by one: both were a full
 * scan and sort of the largest tables in the system.
 *
 * invariants.md — dbvm has one vCPU and is shared with three other
 * applications, so a slow query here degrades other products, not just ours.
 * Column order matches the cursor paginator's own key, including its tiebreak
 * on id, so it satisfies the ordering without a sort node.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX stock_moves_company_moved_at_id ON stock_moves (company_id, moved_at DESC, id DESC)');
        DB::statement('CREATE INDEX stock_layers_company_received_at_id ON stock_layers (company_id, received_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stock_layers_company_received_at_id');
        DB::statement('DROP INDEX IF EXISTS stock_moves_company_moved_at_id');
    }
};
