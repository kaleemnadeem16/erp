<?php

namespace Tests\Feature\Modules\Imports;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Imports\Actions\CreateImportProfile;
use App\Modules\Imports\Actions\StageImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Support\ImportPurpose;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryCutoverImportContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_opening_rows_are_mapped_staged_and_validated_without_writing_stock(): void
    {
        $profile = app(CreateImportProfile::class)(
            'Legacy stock close',
            ImportPurpose::InventoryCutover,
            'OPENING_STATE',
            $this->mappings(),
        );

        $batch = app(StageImportBatch::class)(
            $profile,
            ImportPurpose::InventoryCutover,
            'legacy-close-2026-09-01',
            'stock.csv',
            [$this->validSourceRow()],
            User::factory()->create(),
        );
        $batch = app(ValidateImportBatch::class)($batch);

        $this->assertSame('VALIDATED', $batch->status->value);
        $this->assertSame('VALID', $batch->rows->sole()->status->value);
        $this->assertSame('SKU-100', $batch->rows->sole()->mapped_data['item_code']);
        $this->assertSame('AVCO', $batch->rows->sole()->mapped_data['source_valuation_method']);
        $this->assertDatabaseCount('stock_moves', 0);
        $this->assertDatabaseCount('stock_layers', 0);
        $this->assertDatabaseCount('avco_pools', 0);
    }

    public function test_validation_retains_per_row_errors_for_preview(): void
    {
        $profile = app(CreateImportProfile::class)(
            'Legacy stock errors', ImportPurpose::InventoryCutover, 'OPENING_STATE', $this->mappings(),
        );
        $row = $this->validSourceRow();
        $row['method'] = 'LIFO';
        $row['qty'] = 'not-a-number';

        $batch = app(StageImportBatch::class)(
            $profile, ImportPurpose::InventoryCutover, 'legacy-errors', 'stock.csv', [$row], User::factory()->create(),
        );
        $batch = app(ValidateImportBatch::class)($batch);

        $staged = $batch->rows->sole();
        $this->assertSame('INVALID', $staged->status->value);
        $this->assertArrayHasKey('source_valuation_method', $staged->errors);
        $this->assertArrayHasKey('quantity', $staged->errors);
        $this->assertEqualsCanonicalizing($row, $staged->raw_data);
    }

    public function test_an_approved_default_location_is_applied_and_retained_as_visible_staging_evidence(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.cutover.approve');
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.cutover.approve');
        $profile = app(CreateImportProfile::class)(
            'Legacy warehouse-only stock',
            ImportPurpose::InventoryCutover,
            'OPENING_STATE',
            $this->mappings(),
            ['location_code' => [
                'type' => 'DEFAULT_IF_MISSING',
                'value' => 'OPENING-BIN',
                'reason' => 'The legacy ERP stores one stock balance per warehouse and the company approved its opening bin.',
            ]],
            $approver,
        );
        $source = $this->validSourceRow();
        unset($source['location']);

        $batch = app(StageImportBatch::class)(
            $profile, ImportPurpose::InventoryCutover, 'explicit-default-location', 'stock.csv',
            [$source], User::factory()->create(),
        );
        $batch = app(ValidateImportBatch::class)($batch);
        $row = $batch->rows->sole();

        $this->assertSame('VALID', $row->status->value);
        $this->assertSame('OPENING-BIN', $row->mapped_data['location_code']);
        $this->assertArrayNotHasKey('location', $row->raw_data);
        $decision = $row->mapped_data['_transformations'][0];
        $this->assertSame('location_code', $decision['target_field']);
        $this->assertSame($approver->id, $decision['approved_by']);
        $this->assertNotEmpty($decision['reason']);
    }

    public function test_critical_inventory_facts_cannot_be_defaulted(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.cutover.approve');
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.cutover.approve');

        $this->expectException(DomainInvariantException::class);
        app(CreateImportProfile::class)(
            'Unsafe quantity default', ImportPurpose::InventoryCutover, 'OPENING_STATE', $this->mappings(),
            ['quantity' => ['type' => 'DEFAULT_IF_MISSING', 'value' => '100.0000', 'reason' => 'Unknown quantity']],
            $approver,
        );
    }

    public function test_missing_warehouse_and_location_remain_validation_errors_without_explicit_mapping_decisions(): void
    {
        $profile = app(CreateImportProfile::class)(
            'Unallocated company total', ImportPurpose::InventoryCutover, 'OPENING_STATE', $this->mappings(),
        );
        $source = $this->validSourceRow();
        unset($source['warehouse'], $source['location']);
        $batch = app(StageImportBatch::class)(
            $profile, ImportPurpose::InventoryCutover, 'unallocated-company-total', 'stock.csv',
            [$source], User::factory()->create(),
        );
        $batch = app(ValidateImportBatch::class)($batch);

        $row = $batch->rows->sole();
        $this->assertSame('INVALID', $row->status->value);
        $this->assertArrayHasKey('warehouse_code', $row->errors);
        $this->assertArrayHasKey('location_code', $row->errors);
    }

    public function test_missing_optional_metadata_does_not_block_staging_validation(): void
    {
        $profile = app(CreateImportProfile::class)(
            'Minimal trusted opening', ImportPurpose::InventoryCutover, 'OPENING_STATE', $this->mappings(),
        );
        $source = $this->validSourceRow();
        unset($source['unit_cost'], $source['currency'], $source['fx'], $source['batch'], $source['serial'], $source['effective_at']);
        $batch = app(StageImportBatch::class)(
            $profile, ImportPurpose::InventoryCutover, 'minimal-opening', 'stock.csv',
            [$source], User::factory()->create(),
        );
        $batch = app(ValidateImportBatch::class)($batch);

        $this->assertSame('VALID', $batch->rows->sole()->status->value);
    }

    public function test_external_batch_identity_is_idempotent_but_cannot_be_reused_for_different_source(): void
    {
        $profile = app(CreateImportProfile::class)(
            'Legacy stock retry', ImportPurpose::InventoryCutover, 'OPENING_STATE', $this->mappings(),
        );
        $actor = User::factory()->create();
        $stage = app(StageImportBatch::class);
        $first = $stage($profile, ImportPurpose::InventoryCutover, 'retry-1', 'stock.csv', [$this->validSourceRow()], $actor);
        $retry = $stage($profile, ImportPurpose::InventoryCutover, 'retry-1', 'stock.csv', [$this->validSourceRow()], $actor);

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('import_batch_rows', 1);

        $changed = $this->validSourceRow();
        $changed['value'] = '999.0000';
        $this->expectException(DomainInvariantException::class);
        $stage($profile, ImportPurpose::InventoryCutover, 'retry-1', 'stock.csv', [$changed], $actor);
    }

    public function test_inventory_schema_cannot_be_used_by_a_standard_import_batch(): void
    {
        $this->expectException(DomainInvariantException::class);
        app(CreateImportProfile::class)(
            'Wrong purpose', ImportPurpose::Standard, 'OPENING_STATE', $this->mappings(),
        );
    }

    public function test_staged_source_and_mapping_snapshots_are_immutable(): void
    {
        $profile = app(CreateImportProfile::class)(
            'Legacy immutable stock', ImportPurpose::InventoryCutover, 'OPENING_STATE', $this->mappings(),
        );
        $batch = app(StageImportBatch::class)(
            $profile, ImportPurpose::InventoryCutover, 'immutable-1', 'stock.csv', [$this->validSourceRow()], User::factory()->create(),
        );

        $this->expectException(DomainInvariantException::class);
        $batch->rows->sole()->update(['mapped_data' => ['item_code' => 'CHANGED']]);
    }

    public function test_database_rejects_raw_mutation_of_staged_source_evidence(): void
    {
        $profile = app(CreateImportProfile::class)(
            'Legacy database guard', ImportPurpose::InventoryCutover, 'OPENING_STATE', $this->mappings(),
        );
        $batch = app(StageImportBatch::class)(
            $profile, ImportPurpose::InventoryCutover, 'raw-guard-1', 'stock.csv', [$this->validSourceRow()], User::factory()->create(),
        );

        $this->expectException(QueryException::class);
        DB::table('import_batch_rows')->where('id', $batch->rows->sole()->id)->update([
            'raw_data' => json_encode(['source_id' => 'REWRITTEN'], JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array<string, string> */
    private function mappings(): array
    {
        return [
            'source_id' => 'source_record_id',
            'sku' => 'item_code',
            'warehouse' => 'warehouse_code',
            'location' => 'location_code',
            'method' => 'source_valuation_method',
            'scope' => 'source_avco_scope',
            'pool' => 'source_pool_identity',
            'qty' => 'quantity',
            'uom' => 'uom_code',
            'unit_cost' => 'unit_cost',
            'value' => 'amount',
            'base_value' => 'base_amount',
            'currency' => 'currency_code',
            'fx' => 'fx_rate',
            'batch' => 'batch_no',
            'serial' => 'serial_no',
            'effective_at' => 'source_effective_at',
        ];
    }

    /** @return array<string, string|null> */
    private function validSourceRow(): array
    {
        return [
            'source_id' => 'ROW-1', 'sku' => 'SKU-100', 'warehouse' => 'MAIN', 'location' => 'MAIN',
            'method' => 'AVCO', 'scope' => 'COMPANY_ITEM', 'pool' => 'SKU-100',
            'qty' => '10.0000', 'uom' => 'EA', 'unit_cost' => '12.5000',
            'value' => '125.0000', 'base_value' => '125.0000',
            'currency' => 'PKR', 'fx' => '1.000000000', 'batch' => null,
            'serial' => null, 'effective_at' => '2026-09-01T00:00:00+05:00',
        ];
    }
}
