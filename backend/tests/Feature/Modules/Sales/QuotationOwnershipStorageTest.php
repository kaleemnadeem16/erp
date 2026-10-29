<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

/**
 * QA1a — the ownership storage over the real database, by raw SQL: composite company keys, the immutable chain, the family/event agreement checked at COMMIT
 * (SET CONSTRAINTS ALL IMMEDIATE runs exactly those deferred checks inside a test transaction), the database's own clock, and a rollback that refuses to erase evidence.
 */
class QuotationOwnershipStorageTest extends TestCase
{
    use BuildsQuotationOwnership;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    private function assertRefused(Closure $write, string $contains): void
    {
        try {
            DB::transaction(function () use ($write): void {
                $write();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            $this->assertTrue(collect(explode('|', $contains))->contains(fn (string $needle) => str_contains($e->getMessage(), $needle)), "Expected one of [{$contains}] in: ".$e->getMessage());

            return;
        } finally {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
        $this->fail("The database accepted a write that should have been refused ({$contains}).");
    }

    private function commits(Closure $write): void
    {
        DB::transaction(function () use ($write): void {
            $write();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->addToAssertionCount(1);
    }

    /** @param array<string, mixed> $over */
    private function event(int $family, array $over = []): array
    {
        return ['company_id' => 1, 'family_id' => $family, 'generation' => 2, 'previous_event_id' => DB::table('quotation_ownership_events')->where('family_id', $family)->orderByDesc('generation')->value('id'), 'kind' => 'REASSIGNED',
            'old_owner_id' => $this->preparer->id, 'new_owner_id' => $this->colleague->id, 'old_unit_id' => null, 'new_unit_id' => null, 'access_generation' => 0, 'actor_id' => $this->assigner->id,
            'reason' => 'Handover', 'recorded_at' => now(), ...$over];
    }

    private function moveFamily(int $family, int $owner, int $generation, ?int $unit = null, int $access = 0): void
    {
        DB::table('quotation_families')->where('id', $family)->update(['responsible_owner_id' => $owner, 'access_unit_id' => $unit, 'ownership_generation' => $generation, 'access_generation' => $access]);
    }

    public function test_the_creator_and_company_of_a_family_stay_immutable_and_the_new_columns_default_to_unresolved(): void
    {
        $legacy = $this->legacyFamily();
        $row = DB::table('quotation_families')->find($legacy->id);
        $this->assertSame(['UNRESOLVED', null, null, 0, 0], [$row->access_baseline_state, $row->responsible_owner_id, $row->access_unit_id, $row->ownership_generation, $row->access_generation]);

        $native = $this->nativeFamily();
        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $native->id)->update(['created_by' => $this->colleague->id]), 'keeps its company and creator');
        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $native->id)->update(['company_id' => $this->otherCompany]), 'keeps its company and creator|violates foreign key');
        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $native->id)->delete(), 'cannot be deleted|violates foreign key');
    }

    public function test_the_family_ownership_shape_is_checked(): void
    {
        $base = ['company_id' => 1, 'created_by' => $this->preparer->id, 'created_at' => now(), 'updated_at' => now()];
        foreach ([
            ['access_baseline_state' => 'GUESSED'],
            ['access_baseline_state' => 'NATIVE'],
            ['access_baseline_state' => 'NATIVE', 'responsible_owner_id' => $this->preparer->id],
            ['access_baseline_state' => 'UNRESOLVED', 'responsible_owner_id' => $this->preparer->id, 'ownership_generation' => 1],
            ['access_baseline_state' => 'UNRESOLVED', 'ownership_generation' => 1],
            ['access_baseline_state' => 'UNRESOLVED', 'access_generation' => 1],
            ['access_baseline_state' => 'NATIVE', 'responsible_owner_id' => $this->preparer->id, 'ownership_generation' => 0],
        ] as $bad) {
            $this->assertRefused(fn () => DB::table('quotation_families')->insert([...$base, ...$bad]), 'quotation_families_ownership_shape');
        }
        $this->assertRefused(fn () => DB::table('quotation_families')->insert([...$base, 'access_baseline_state' => 'NATIVE', 'responsible_owner_id' => $this->otherUser, 'ownership_generation' => 1]), 'quotation_families_owner_fk');
        $this->assertRefused(fn () => DB::table('quotation_families')->insert([...$base, 'access_baseline_state' => 'NATIVE', 'responsible_owner_id' => $this->preparer->id, 'ownership_generation' => 1,
            'access_unit_id' => $this->unit('X', 'ACTIVE', $this->otherCompany)]), 'quotation_families_access_unit_fk');
    }

    public function test_cross_company_and_cross_family_references_are_refused(): void
    {
        $family = $this->nativeFamily();
        $other = $this->nativeFamily($this->preparer);
        $theirs = $this->foreignFamilyId();
        $ours = $this->unit('MINE');
        $foreignUnit = $this->unit('THEIRS', 'ACTIVE', $this->otherCompany);

        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id, ['family_id' => $theirs])), 'quotation_ownership_events_family_fk|first ownership event');
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id, ['company_id' => $this->otherCompany])), 'quotation_ownership_events_family_fk|first ownership event');
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id, ['new_owner_id' => $this->otherUser])), 'quotation_ownership_events_new_owner_fk');
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id, ['actor_id' => $this->otherUser])), 'quotation_ownership_events_actor_fk');
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id, ['old_owner_id' => $this->otherUser])), 'quotation_ownership_events_old_owner_fk|continue the chain');
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id, ['kind' => 'UNIT_CHANGED', 'old_owner_id' => $this->preparer->id, 'new_owner_id' => $this->preparer->id,
            'new_unit_id' => $foreignUnit, 'access_generation' => 1])), 'quotation_ownership_events_new_unit_fk');
        // A predecessor in another family of the same company is refused by the same-family key.
        $otherEvent = DB::table('quotation_ownership_events')->where('family_id', $other->id)->value('id');
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id, ['previous_event_id' => $otherEvent])), 'quotation_ownership_events_previous_fk|continue the chain');
        $this->assertSame(0, DB::table('quotation_ownership_events')->where('new_unit_id', $ours)->count());
    }

    public function test_the_chain_is_contiguous_and_old_values_equal_the_previous_new_values(): void
    {
        $family = $this->nativeFamily();
        $initial = DB::table('quotation_ownership_events')->where('family_id', $family->id)->value('id');

        foreach ([
            ['generation' => 3],                                   // skipped
            ['generation' => 1],                                   // repeated
            ['previous_event_id' => null, 'generation' => 2],      // no predecessor
            ['old_owner_id' => $this->colleague->id],               // not the previous new owner
            ['old_unit_id' => $this->unit('GHOST')],               // not the previous new unit
            ['access_generation' => 1],                            // an owner change does not move the access generation
            ['kind' => 'INITIAL', 'generation' => 2],              // a second first event
            ['kind' => 'CAPTURED_BASELINE', 'generation' => 2],
        ] as $bad) {
            $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id, $bad)), 'continue the chain|quotation_ownership_events_shape|moves with an access unit change|unique');
        }
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id, ['previous_event_id' => $initial + 999])), 'quotation_ownership_events_previous_fk|continue the chain');
        // The first event must be an INITIAL or a CAPTURED_BASELINE of generation 1.
        $legacy = $this->legacyFamily();
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($legacy->id, ['generation' => 1, 'previous_event_id' => null, 'old_owner_id' => null, 'kind' => 'REASSIGNED'])), 'quotation_ownership_events_shape|first ownership event');
    }

    public function test_each_kind_has_its_own_shape(): void
    {
        $family = $this->nativeFamily();
        $unit = $this->unit();
        $bad = [
            ['kind' => 'REASSIGNED', 'new_owner_id' => $this->preparer->id],                                        // no actual change
            ['kind' => 'REASSIGNED', 'new_unit_id' => $unit],                                                      // a reassignment cannot move the unit
            ['kind' => 'REASSIGNED', 'baseline_source_reference' => 'x'],
            ['kind' => 'UNIT_CHANGED', 'new_owner_id' => $this->colleague->id, 'new_unit_id' => $unit, 'access_generation' => 1], // a unit change cannot move the owner
            ['kind' => 'UNIT_CHANGED', 'new_owner_id' => $this->preparer->id, 'new_unit_id' => null, 'access_generation' => 1],     // no actual change
            ['reason' => ' '],
            ['reason' => ''],
            ['baseline_source_reference' => ' '],
        ];
        foreach ($bad as $over) {
            $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id, $over)), 'quotation_ownership_events_shape|moves with an access unit change|continue the chain');
        }
        $legacy = $this->legacyFamily();
        $captured = ['generation' => 1, 'previous_event_id' => null, 'old_owner_id' => null, 'kind' => 'CAPTURED_BASELINE', 'access_generation' => 1, 'baseline_source_reference' => 'sheet', 'approval_reference' => 'A-1'];
        foreach ([['baseline_source_reference' => null], ['approval_reference' => null], ['access_generation' => 0], ['old_owner_id' => $this->preparer->id], ['old_unit_id' => $unit]] as $over) {
            $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($legacy->id, [...$captured, ...$over])), 'quotation_ownership_events_shape|first ownership event');
        }
    }

    public function test_the_events_are_append_only_and_truncate_guarded(): void
    {
        $family = $this->nativeFamily();
        $this->settleFixtures();
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->update(['reason' => 'rewritten']), 'append-only');
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->delete(), 'append-only');
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE quotation_ownership_events'), 'append-only');
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE quotation_families CASCADE'), 'append-only|cannot truncate');
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE users CASCADE'), 'append-only|cannot truncate');
        $this->assertSame(1, DB::table('quotation_ownership_events')->where('family_id', $family->id)->count());
        $guards = DB::select("select (t.tgtype & 32) <> 0 as is_truncate from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
            where not t.tgisinternal and c.relname = 'quotation_ownership_events' and p.proname = 'quotation_ownership_append_only_guard'");
        $this->assertCount(2, $guards, 'a row guard and a truncate guard');
    }

    public function test_the_event_time_is_the_database_clock(): void
    {
        $family = $this->nativeFamily();
        foreach (['1999-01-01 00:00:00+00', '2099-01-01 00:00:00+00'] as $client) {
            DB::transaction(function () use ($family, $client): void {
                $id = DB::table('quotation_ownership_events')->insertGetId($this->event($family->id, ['generation' => (int) DB::table('quotation_ownership_events')->where('family_id', $family->id)->max('generation') + 1,
                    'previous_event_id' => DB::table('quotation_ownership_events')->where('family_id', $family->id)->orderByDesc('generation')->value('id'),
                    'old_owner_id' => DB::table('quotation_families')->where('id', $family->id)->value('responsible_owner_id'),
                    'new_owner_id' => DB::table('quotation_families')->where('id', $family->id)->value('responsible_owner_id') === $this->colleague->id ? $this->preparer->id : $this->colleague->id, 'recorded_at' => $client]));
                $stored = DB::table('quotation_ownership_events')->find($id);
                $distance = (float) DB::selectOne('select abs(extract(epoch from (clock_timestamp() - ?::timestamptz))) as d', [$stored->recorded_at])->d;
                $this->assertLessThan(5.0, $distance, "the supplied {$client} was discarded");
                DB::table('quotation_families')->where('id', $family->id)->update(['responsible_owner_id' => $stored->new_owner_id, 'ownership_generation' => $stored->generation]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    public function test_the_family_and_its_latest_event_must_agree_at_commit(): void
    {
        $family = $this->nativeFamily();
        $this->settleFixtures();

        // An event with no family change, and a family change with no event.
        $this->assertRefused(fn () => DB::table('quotation_ownership_events')->insert($this->event($family->id)), 'must equal its latest recorded ownership event|must be reflected');
        $this->assertRefused(fn () => $this->moveFamily($family->id, $this->colleague->id, 2), 'must equal its latest recorded ownership event');
        // A silent owner or unit edit, a backwards generation, and an owner change that did not advance the generation.
        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $family->id)->update(['responsible_owner_id' => $this->colleague->id]), 'changes only with an ownership event');
        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $family->id)->update(['access_unit_id' => $this->unit()]), 'changes only with an ownership event');
        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $family->id)->update(['ownership_generation' => 0]), 'only move forward|quotation_families_ownership_shape');
        // Projection that disagrees with the event: wrong owner, wrong unit, wrong access generation.
        $this->assertRefused(function () use ($family): void {
            DB::table('quotation_ownership_events')->insert($this->event($family->id));
            $this->moveFamily($family->id, $this->assigner->id, 2);
        }, 'must equal its latest recorded ownership event');
        $this->assertRefused(function () use ($family): void {
            DB::table('quotation_ownership_events')->insert($this->event($family->id));
            $this->moveFamily($family->id, $this->colleague->id, 2, null, 1);
        }, 'must equal its latest recorded ownership event');
        // A family that claims an owner without any event.
        $legacy = $this->legacyFamily();
        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $legacy->id)->update(['responsible_owner_id' => $this->preparer->id, 'ownership_generation' => 1, 'access_baseline_state' => 'CAPTURED']), 'must equal its latest recorded ownership event|without ownership events|only from UNRESOLVED');
        // A coherent step commits, in either insertion order.
        $this->commits(function () use ($family): void {
            DB::table('quotation_ownership_events')->insert($this->event($family->id));
            $this->moveFamily($family->id, $this->colleague->id, 2);
        });
        $this->commits(function () use ($family): void {
            $this->moveFamily($family->id, $this->preparer->id, 3);
            DB::table('quotation_ownership_events')->insert($this->event($family->id, ['generation' => 3, 'old_owner_id' => $this->colleague->id, 'new_owner_id' => $this->preparer->id,
                'previous_event_id' => DB::table('quotation_ownership_events')->where('family_id', $family->id)->orderByDesc('generation')->value('id')]));
        });
    }

    private function settleFixtures(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function test_the_baseline_is_final_once_it_leaves_unresolved(): void
    {
        $native = $this->nativeFamily();
        $legacy = $this->legacyFamily();
        $this->settleFixtures();

        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $native->id)->update(['access_baseline_state' => 'CAPTURED']), 'moves only from UNRESOLVED to CAPTURED');
        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $native->id)->update(['access_baseline_state' => 'UNRESOLVED']), 'moves only from UNRESOLVED to CAPTURED|quotation_families_ownership_shape');
        // A legacy family cannot become NATIVE (native creation cannot masquerade as a capture, nor the reverse).
        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $legacy->id)->update(['access_baseline_state' => 'NATIVE', 'responsible_owner_id' => $this->preparer->id, 'ownership_generation' => 1]),
            'moves only from UNRESOLVED to CAPTURED');
        $this->commits(function () use ($legacy): void {
            DB::table('quotation_ownership_events')->insert(['company_id' => 1, 'family_id' => $legacy->id, 'generation' => 1, 'previous_event_id' => null, 'kind' => 'CAPTURED_BASELINE', 'old_owner_id' => null,
                'new_owner_id' => $this->colleague->id, 'old_unit_id' => null, 'new_unit_id' => null, 'access_generation' => 1, 'baseline_source_reference' => 'sheet', 'approval_reference' => 'A-1',
                'actor_id' => $this->assigner->id, 'reason' => 'Adopt', 'recorded_at' => now()]);
            DB::table('quotation_families')->where('id', $legacy->id)->update(['responsible_owner_id' => $this->colleague->id, 'ownership_generation' => 1, 'access_generation' => 1, 'access_baseline_state' => 'CAPTURED']);
        });
        $this->assertSame('CAPTURED', DB::table('quotation_families')->where('id', $legacy->id)->value('access_baseline_state'));
        $this->assertRefused(fn () => DB::table('quotation_families')->where('id', $legacy->id)->update(['access_baseline_state' => 'UNRESOLVED']), 'moves only from UNRESOLVED to CAPTURED|quotation_families_ownership_shape');
    }

    public function test_a_referenced_user_or_unit_cannot_be_hard_deleted(): void
    {
        $family = $this->nativeFamily();
        $unit = $this->unit();
        $this->assertSame(1, $family->refresh()->ownership_generation);
        $this->assertRefused(fn () => DB::table('users')->where('id', $this->preparer->id)->delete(), 'violates foreign key');
        $this->commits(function () use ($family, $unit): void {
            DB::table('quotation_ownership_events')->insert($this->event($family->id, ['kind' => 'UNIT_CHANGED', 'old_owner_id' => $this->preparer->id, 'new_owner_id' => $this->preparer->id, 'new_unit_id' => $unit, 'access_generation' => 1]));
            $this->moveFamily($family->id, $this->preparer->id, 2, $unit, 1);
        });
        $this->assertRefused(fn () => DB::table('organisation_units')->where('id', $unit)->delete(), 'violates foreign key');
    }

    public function test_rollback_refuses_while_evidence_exists_and_works_when_empty(): void
    {
        $migration = require base_path('database/migrations/2026_10_27_100000_add_quotation_ownership_and_evidence.php');
        $legacy = $this->legacyFamily();
        $this->settleFixtures();

        // A legacy-only store (no events, everything UNRESOLVED) rolls back and applies again, keeping the family.
        $migration->down();
        $this->assertFalse(Schema::hasTable('quotation_ownership_events'));
        $this->assertFalse(Schema::hasColumn('quotation_families', 'responsible_owner_id'));
        $this->assertSame(1, DB::table('quotation_families')->where('id', $legacy->id)->count());
        $migration->up();
        $this->assertTrue(Schema::hasTable('quotation_ownership_events'));
        $this->assertSame('UNRESOLVED', DB::table('quotation_families')->where('id', $legacy->id)->value('access_baseline_state'));

        $this->nativeFamily();
        $this->settleFixtures();
        try {
            $migration->down();
            $this->fail('Rolling back with ownership evidence present must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Plan a backout first', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('quotation_ownership_events'));
        $this->assertSame(1, DB::table('quotation_ownership_events')->count());
    }

    public function test_existing_unrelated_quotation_guards_are_untouched(): void
    {
        $functions = collect(DB::select("select proname from pg_proc where proname like 'quotation_%' order by 1"))->pluck('proname')->all();
        foreach (['quotation_identity_guard', 'quotation_association_event_guard', 'quotation_family_association_check', 'quotation_revision_guard'] as $existing) {
            $this->assertContains($existing, $functions);
        }
        $family = $this->nativeFamily($this->preparer);
        // The pre-existing association chain still advances alongside ownership.
        $this->assertSame(0, (int) DB::table('quotation_families')->where('id', $family->id)->value('association_generation'));
        $this->assertInstanceOf(User::class, $this->preparer);
    }
}
