<?php

namespace Tests\Feature\Modules\Imports;

use App\Models\User;
use App\Modules\Imports\Actions\StageImportBatch;
use App\Modules\Imports\Models\ImportProfile;
use App\Modules\Imports\Support\ImportPurpose;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportProfileTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_rejects_a_hydrated_foreign_profile_before_reading_its_mapping(): void
    {
        $actor = User::factory()->create();
        DB::table('companies')->insert(['id' => 2, 'name' => 'Foreign company']);
        $id = DB::table('import_profiles')->insertGetId(['company_id' => 2, 'name' => 'Foreign mapping', 'entity_type' => 'OPENING_STATE']);
        $profile = ImportProfile::withoutGlobalScopes()->findOrFail($id);
        try {
            app(StageImportBatch::class)($profile, ImportPurpose::InventoryCutover, 'foreign', 'foreign.csv', [['item' => 'secret']], $actor);
            $this->fail('A foreign profile was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertFalse($profile->relationLoaded('mappings'));
            $this->assertDatabaseCount('import_batches', 0);
            $this->assertDatabaseCount('import_batch_rows', 0);
        }
    }
}
