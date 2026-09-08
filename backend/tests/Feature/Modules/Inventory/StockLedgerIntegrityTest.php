<?php

namespace Tests\Feature\Modules\Inventory;

use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\StockValuationEntry;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Inventory\Concerns\BuildsOutboundRecalculation;
use Tests\TestCase;

/**
 * Phase 2 integrity audit: the stock ledger's historical-retention and
 * raw-write tenant guarantees hold at the database, not only in Eloquent's
 * model guards — raw SQL that bypasses every Action still cannot rewrite a
 * posted move, valuation entry or layer fact, and cannot smuggle another
 * company's location into a recalculation request.
 */
class StockLedgerIntegrityTest extends TestCase
{
    use BuildsOutboundRecalculation, RefreshDatabase;

    public function test_posted_moves_and_valuation_entries_are_database_immutable(): void
    {
        $this->fixture(ValuationMethod::Avco);
        $this->receive($this->location('a'), '5', '10', '2026-01-01', 1);
        $move = StockMove::query()->sole();
        $entry = StockValuationEntry::query()->sole();

        $this->assertDatabaseRejects(fn () => DB::table('stock_moves')->where('id', $move->id)->update(['qty' => '9.0000']));
        $this->assertDatabaseRejects(fn () => DB::table('stock_moves')->where('id', $move->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('stock_valuation_entries')->where('id', $entry->id)->update(['base_amount' => '0.0000']));
        $this->assertDatabaseRejects(fn () => DB::table('stock_valuation_entries')->where('id', $entry->id)->delete());

        $this->assertSame('5.0000', $move->refresh()->qty);
        $this->assertSame('50.0000', $entry->refresh()->base_amount);
    }

    public function test_layer_facts_are_database_immutable_but_remaining_quantity_may_be_consumed(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $this->receive($this->location('a'), '5', '10', '2026-01-01', 1);
        $layer = StockLayer::query()->sole();

        $this->assertDatabaseRejects(fn () => DB::table('stock_layers')->where('id', $layer->id)->update(['unit_cost' => '1.0000']));
        $this->assertDatabaseRejects(fn () => DB::table('stock_layers')->where('id', $layer->id)->update(['qty_received' => '50.0000']));
        $this->assertDatabaseRejects(fn () => DB::table('stock_layers')->where('id', $layer->id)->delete());

        DB::table('stock_layers')->where('id', $layer->id)->update(['qty_remaining' => '2.0000']);
        $this->assertSame('2.0000', $layer->refresh()->qty_remaining);
        $this->assertSame('5.0000', $layer->qty_received);
    }

    public function test_a_recalculation_request_cannot_reference_another_companys_location(): void
    {
        $this->fixture(ValuationMethod::Avco);
        $location = $this->location('a');
        $this->receive($location, '5', '10', '2026-01-01', 1);
        // A request only exists when it conflicts with later history — the 01-10 issue
        // is what makes the 01-05 request backdated at all.
        $this->issue($location, '1', '2026-01-10', 2);
        $request = $this->request($location, '1', '2026-01-05');

        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Ledger integrity other company', 'created_at' => now(), 'updated_at' => now()]);
        $otherWarehouse = DB::table('warehouses')->insertGetId(['company_id' => $otherCompany, 'code' => 'other', 'name' => 'Other', 'is_default' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $otherLocation = DB::table('locations')->insertGetId(['warehouse_id' => $otherWarehouse, 'code' => 'other', 'name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);

        // The same-company copy proves the copy technique itself is sound, so the
        // rejection below is attributable to the foreign location alone.
        DB::statement($this->copyRequestSql($request->id, ['location_id' => $location->id, 'source_id' => 998]));
        $this->assertSame(2, DB::table('recalculation_requests')->count());

        $this->assertDatabaseRejects(
            fn () => DB::statement($this->copyRequestSql($request->id, ['location_id' => $otherLocation, 'source_id' => 999])),
            '23503',
        );
        $this->assertDatabaseRejects(
            fn () => DB::statement($this->copyRequestSql($request->id, ['destination_location_id' => $otherLocation, 'source_id' => 997])),
            '23503',
        );
    }

    /**
     * Postgres aborts the whole transaction on a failed statement, and
     * RefreshDatabase wraps the test in one — so each rejected mutation runs
     * in its own savepoint, letting the test keep querying afterwards.
     */
    private function assertDatabaseRejects(callable $mutation, string $sqlState = '23514'): void
    {
        try {
            DB::transaction($mutation);
        } catch (QueryException $e) {
            $this->assertSame($sqlState, (string) $e->getCode());

            return;
        }

        $this->fail('Expected the database to reject the mutation.');
    }

    /** @param array<string, int> $overrides */
    private function copyRequestSql(int $sourceId, array $overrides): string
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')->where('table_name', 'recalculation_requests')->where('column_name', '<>', 'id')
            ->orderBy('ordinal_position')->pluck('column_name')->all();
        $select = array_map(fn (string $column): string => isset($overrides[$column]) ? ((string) $overrides[$column]).' as '.$column : $column, $columns);

        return 'insert into recalculation_requests ('.implode(', ', $columns).') select '.implode(', ', $select).' from recalculation_requests where id = '.$sourceId;
    }
}
