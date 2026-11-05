<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Sales\Actions\ChangeQuotationAccessUnit;
use App\Modules\Sales\Actions\ReassignQuotationOwner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\Support\AssertsPopulatedRollbackRefusal;
use Tests\Support\BuildsQuotationShareStorage;
use Tests\TestCase;

class QuotationAccessLedgerStorageTest extends TestCase
{
    use AssertsPopulatedRollbackRefusal, BuildsQuotationOwnership, BuildsQuotationShareStorage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    public function test_units_grants_revocations_and_owner_changes_preserve_both_exact_chains(): void
    {
        $family = $this->nativeFamily();
        app(ChangeQuotationAccessUnit::class)($this->assigner, $family->id, $this->unit(), 1, 0, $this->key(), 'Unit fixture');
        $before = DB::table('quotation_ownership_events')->where('family_id', $family->id)->orderBy('generation')->get()->toJson();
        $grant = $this->rawGrant($family, $this->preparer, $this->colleague);
        $this->rawRevoke($family, $grant, $this->assigner);
        $family->refresh();
        $this->assertSame([2, 3], [$family->ownership_generation, $family->access_generation]);
        $this->assertSame($before, DB::table('quotation_ownership_events')->where('family_id', $family->id)->orderBy('generation')->get()->toJson());
        app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->colleague->id, 2, 3, $this->key(), 'Owner fixture after share');
        app(ChangeQuotationAccessUnit::class)($this->assigner, $family->id, null, 3, 3, $this->key(), 'Unit clear fixture after share');
        $family->refresh();
        $events = DB::table('quotation_access_events')->where('family_id', $family->id)->orderBy('generation')->get();
        $this->assertSame([1, 2, 3, 4], $events->pluck('generation')->all());
        $this->assertSame(['OWNERSHIP_CHANGE', 'SHARE_GRANTED', 'SHARE_REVOKED', 'OWNERSHIP_CHANGE'], $events->pluck('kind')->all());
        $this->assertSame([null, $events[0]->id, $events[1]->id, $events[2]->id], $events->pluck('previous_event_id')->all());
        $this->assertSame([4, 4, $this->colleague->id, null], [$family->ownership_generation, $family->access_generation, $family->responsible_owner_id, $family->access_unit_id]);
        $this->assertSame(4, DB::table('quotation_ownership_events')->where('family_id', $family->id)->count(), 'Shares never fabricate ownership events.');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_projection_only_skipped_duplicate_and_wrong_family_ticks_are_refused(): void
    {
        $family = $this->nativeFamily();
        $this->assertStorageRefuses(fn () => DB::table('quotation_families')->where('id', $family->id)->update(['access_generation' => 1]));
        $grant = $this->rawGrant($family, $this->preparer, $this->colleague);
        $tail = DB::table('quotation_access_events')->where('family_id', $family->id)->first();
        $row = ['company_id' => 1, 'family_id' => $family->id, 'previous_event_id' => $tail->id, 'kind' => 'SHARE_GRANTED', 'grant_id' => $grant, 'origin' => 'LIVE'];
        $this->assertStorageRefuses(fn () => DB::table('quotation_access_events')->insert($row + ['generation' => 3]));
        $this->assertStorageRefuses(fn () => DB::table('quotation_access_events')->insert($row + ['generation' => 2]), ['23505']);
        $other = $this->nativeFamily();
        $this->assertStorageRefuses(fn () => DB::table('quotation_access_events')->insert(array_replace($row, ['family_id' => $other->id, 'previous_event_id' => null, 'generation' => 1])), ['23505']);
        $this->assertStorageRefuses(fn () => $this->rawRevoke($other, $grant, $this->assigner));
        $this->assertSame(1, $family->fresh()->access_generation);
        $this->assertSame(1, DB::table('quotation_access_events')->count());
    }

    public function test_wrong_company_actor_recipient_and_stale_epoch_pins_refuse_without_partial_evidence(): void
    {
        $family = $this->nativeFamily();
        $foreign = User::withoutGlobalScopes()->findOrFail($this->otherUser);
        $this->assertStorageRefuses(fn () => $this->rawGrant($family, $foreign, $this->colleague));
        $this->assertStorageRefuses(fn () => $this->rawGrant($family, $this->preparer, $foreign));
        $this->assertStorageRefuses(fn () => $this->rawGrant($family, $this->preparer, $this->preparer));
        $epoch = DB::table('company_access_generations')->where('company_id', 1)->first();
        $badPins = [
            'issuer_authorization_generation' => 1 + (int) DB::table('users')->where('id', $this->preparer->id)->value('authorization_generation'),
            'recipient_authorization_generation' => 1 + (int) DB::table('users')->where('id', $this->colleague->id)->value('authorization_generation'),
            'organisation_generation' => 1 + $epoch->organisation_generation, 'capability_generation' => 1 + $epoch->capability_generation,
            'ownership_generation' => 1 + $family->ownership_generation,
        ];
        foreach ($badPins as $field => $value) {
            $this->assertStorageRefuses(fn () => $this->rawGrant($family, $this->preparer, $this->colleague, [$field => $value]));
        }
        $this->assertSame(0, DB::table('quotation_access_grants')->count());
        $this->assertSame(0, DB::table('quotation_access_events')->count());
        $this->assertSame(0, $family->fresh()->access_generation);
    }

    public function test_scheduled_overlap_is_refused_but_revoked_or_epoch_invalid_sources_do_not_block_replacement(): void
    {
        $family = $this->nativeFamily();
        $dates = ['starts_at' => '2099-01-01T00:00:00Z', 'ends_at' => '2099-02-01T00:00:00Z'];
        $first = $this->rawGrant($family, $this->preparer, $this->colleague, $dates);
        $this->assertStorageRefuses(fn () => $this->rawGrant($family, $this->preparer, $this->colleague, $dates));
        $this->rawRevoke($family, $first, $this->assigner);
        $second = $this->rawGrant($family, $this->preparer, $this->colleague, $dates);
        DB::table('users')->where('id', $this->colleague->id)->update(['status' => 'SUSPENDED']);
        DB::table('users')->where('id', $this->colleague->id)->update(['status' => 'ACTIVE']);
        $third = $this->rawGrant($family, $this->preparer, $this->colleague, $dates);
        $this->assertNotSame($second, $third);
        $this->assertSame(3, DB::table('quotation_access_grants')->count());
        $this->assertSame(4, $family->fresh()->access_generation);
    }

    public function test_revoke_after_expiry_is_append_only_and_populated_rollback_refuses(): void
    {
        $family = $this->nativeFamily();
        $grant = $this->rawGrant($family, $this->preparer, $this->colleague, ['starts_at' => '2000-01-01T00:00:00Z', 'ends_at' => '2000-01-02T00:00:00Z']);
        $before = DB::table('quotation_access_grants')->where('id', $grant)->first();
        $this->rawRevoke($family, $grant, $this->assigner);
        $this->assertEquals($before, DB::table('quotation_access_grants')->where('id', $grant)->first());
        $this->assertSame(2, $family->fresh()->access_generation);
        $this->assertPopulatedRollbackRefused('2026_11_10_020000_create_quotation_access_events_and_shares.php', ['quotation_access_events', 'quotation_access_grants', 'quotation_access_revocations']);
    }

    public function test_parent_cascading_truncate_reaches_the_ownership_guard_not_a_foreign_key_error(): void
    {
        $this->nativeFamily();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        try {
            DB::transaction(fn () => DB::statement('TRUNCATE TABLE quotation_ownership_events CASCADE'));
            $this->fail('Cascading truncate must reach the append-only guard and refuse.');
        } catch (QueryException $refusal) {
            $this->assertSame('23514', $refusal->errorInfo[0]);
            $this->assertStringContainsString('Quotation ownership events are append-only', $refusal->getMessage());
        }
        $this->assertSame(1, DB::table('quotation_ownership_events')->count());
    }

    public function test_every_new_evidence_table_rejects_update_delete_and_cascading_truncate(): void
    {
        $family = $this->nativeFamily();
        $grant = $this->rawGrant($family, $this->preparer, $this->colleague);
        $this->rawRevoke($family, $grant, $this->assigner);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        foreach (['quotation_access_grants', 'quotation_access_revocations', 'quotation_access_events'] as $table) {
            $before = DB::table($table)->orderBy('id')->get()->toJson();
            $column = $table === 'quotation_access_events' ? 'origin' : 'reason';
            $this->assertStorageRefuses(fn () => DB::statement("UPDATE {$table} SET {$column} = {$column}"));
            $this->assertStorageRefuses(fn () => DB::statement('DELETE FROM '.$table));
            $this->assertStorageRefuses(fn () => DB::statement('TRUNCATE '.$table.' CASCADE'));
            $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }
}
