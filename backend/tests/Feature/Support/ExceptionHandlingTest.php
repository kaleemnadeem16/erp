<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the global QueryException(23514) handler in bootstrap/app.php turns a
 * raw CHECK constraint violation into a clean 422, not an uncaught 500 — the
 * backstop for a genuine race no PHP-level DomainInvariantException guard saw
 * coming. Every real Phase 2 table pairs a CHECK/trigger with an Action-level
 * guard, so there's no natural, non-racy scenario to reach this through a real
 * endpoint; this uses disposable scaffolding instead, the same technique
 * Tests\Architecture\CompanyScopedForeignKeyTest uses for its own guard.
 */
class ExceptionHandlingTest extends TestCase
{
    public function test_a_check_constraint_violation_returns_422_not_500(): void
    {
        Schema::create('architecture_check_violation_targets', function (Blueprint $table) {
            $table->id();
            $table->decimal('qty', 19, 4);
        });
        DB::statement('ALTER TABLE architecture_check_violation_targets ADD CONSTRAINT architecture_check_violation_qty_check CHECK (qty >= 0)');
        Route::middleware('api')->get('/api/v1/__test/check-violation', function () {
            DB::table('architecture_check_violation_targets')->insert(['qty' => '-1.0000']);
        });

        try {
            $user = User::factory()->create();
            $this->actingAs($user)->getJson('/api/v1/__test/check-violation')
                ->assertStatus(422)
                ->assertJsonPath('message', 'This action violates a data integrity rule and cannot be completed.');
        } finally {
            Schema::dropIfExists('architecture_check_violation_targets');
        }
    }
}
