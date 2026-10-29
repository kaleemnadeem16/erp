<?php

namespace Tests\Feature\Modules\Files;

use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Files F0 — the four tables over the real database, by raw SQL: composite company keys, CHECK shapes, immutable blobs that move only by legal health transitions
 * (REJECTED is final), append-only evidence and receipts, a guarded intent state machine whose COMMITTED state is unreachable, TRUNCATE refused everywhere, the database's
 * own clock, and a rollback that refuses to erase evidence.
 */
class FilesStorageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $otherCompany;

    private int $otherUser;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherUser = DB::table('users')->insertGetId(['company_id' => $this->otherCompany, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function assertRefused(Closure $write, string $contains): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            $this->assertTrue(collect(explode('|', $contains))->contains(fn (string $needle) => str_contains($e->getMessage(), $needle)), "Expected one of [{$contains}] in: ".$e->getMessage());

            return;
        }
        $this->fail("The database accepted a write that should have been refused ({$contains}).");
    }

    /** @param array<string, mixed> $over */
    private function blobRow(array $over = []): array
    {
        return ['company_id' => 1, 'storage_disk' => 'files', 'storage_key' => bin2hex(random_bytes(20)), 'sha256' => hash('sha256', 'x'.++$this->n), 'size_bytes' => 10, 'detected_media_type' => 'image/png',
            'declared_media_type' => 'image/png', 'health' => 'STAGED', 'scan_state' => 'NOT_CONFIGURED', 'created_by' => $this->user->id, 'created_at' => now(), ...$over];
    }

    private function blob(array $over = []): int
    {
        return DB::table('file_blobs')->insertGetId($this->blobRow($over));
    }

    /** @param array<string, mixed> $over */
    private function intentRow(array $over = []): array
    {
        return ['company_id' => 1, 'owner_type_key' => 'testing.owner', 'owner_binding_identity' => 'company:1', 'purpose_key' => 'testing.company-logo', 'actor_id' => $this->user->id,
            'idempotency_key' => 'key-'.++$this->n, 'intent_digest' => str_repeat('a', 64), 'expected_size' => 10, 'state' => 'PENDING', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(), ...$over];
    }

    private function intent(array $over = []): int
    {
        return DB::table('file_upload_intents')->insertGetId($this->intentRow($over));
    }

    private function event(int $blob, array $over = []): array
    {
        return ['company_id' => 1, 'blob_id' => $blob, 'event' => 'STAGED', 'from_health' => null, 'to_health' => 'STAGED', 'actor_id' => $this->user->id, 'evidence' => '{}', 'occurred_at' => now(), ...$over];
    }

    // ---- blobs ---------------------------------------------------------------------------------------------------------------------------------------

    public function test_blob_shape_keys_and_tenancy(): void
    {
        $blob = $this->blob();
        $row = $this->blobRow();
        $this->assertRefused(fn () => DB::table('file_blobs')->insert([...$row, 'storage_key' => DB::table('file_blobs')->where('id', $blob)->value('storage_key')]), 'file_blobs_storage_unique');
        $this->assertRefused(fn () => DB::table('file_blobs')->insert([...$row, 'created_by' => $this->otherUser]), 'file_blobs_created_by_fk');
        $this->assertRefused(fn () => DB::table('file_blobs')->insert([...$row, 'company_id' => $this->otherCompany]), 'file_blobs_created_by_fk');
        foreach ([['storage_key' => 'not-a-key'], ['storage_key' => strtoupper(bin2hex(random_bytes(20)))], ['storage_key' => '../../etc/passwd'], ['sha256' => 'ABC'], ['sha256' => strtoupper(str_repeat('a', 64))],
            ['health' => 'DELETED'], ['scan_state' => 'MAYBE'], ['storage_disk' => ' '], ['detected_media_type' => ''], ['declared_media_type' => ' ']] as $bad) {
            $this->assertRefused(fn () => DB::table('file_blobs')->insert([...$row, ...$bad]), 'file_blobs_shape');
        }
        // No deduplication: the same bytes uploaded twice, even in two companies, are two blobs (D9).
        $same = hash('sha256', 'same');
        $this->blob(['sha256' => $same]);
        $this->blob(['sha256' => $same]);
        $this->assertSame(2, DB::table('file_blobs')->where('sha256', $same)->count());
    }

    public function test_a_blob_is_an_immutable_identity_that_moves_only_by_legal_health_transitions(): void
    {
        $id = $this->blob();
        $move = fn (array $values) => fn () => DB::table('file_blobs')->where('id', $id)->update($values);

        foreach ([['sha256' => str_repeat('b', 64)], ['size_bytes' => 11], ['storage_key' => bin2hex(random_bytes(20))], ['storage_disk' => 'other'], ['detected_media_type' => 'application/pdf'],
            ['declared_media_type' => null], ['created_by' => null], ['company_id' => $this->otherCompany], ['created_at' => '1999-01-01']] as $bad) {
            $this->assertRefused($move($bad), 'keeps its identity|violates foreign key');
        }
        $this->assertRefused($move(['health' => 'MISSING']), 'cannot move from STAGED to MISSING');
        $this->assertRefused(fn () => DB::table('file_blobs')->where('id', $id)->delete(), 'cannot be deleted');

        DB::table('file_blobs')->where('id', $id)->update(['health' => 'VERIFIED', 'scan_state' => 'CLEAN', 'verified_at' => '1999-01-01']);
        $row = DB::table('file_blobs')->find($id);
        $this->assertSame(['VERIFIED', 'CLEAN'], [$row->health, $row->scan_state]);
        $this->assertLessThan(5.0, (float) DB::selectOne('select abs(extract(epoch from (clock_timestamp() - ?::timestamptz))) as d', [$row->verified_at])->d, 'verified_at is the database clock, not the writer\'s');
        $this->assertRefused($move(['health' => 'STAGED']), 'cannot move from VERIFIED to STAGED');
        DB::table('file_blobs')->where('id', $id)->update(['health' => 'MISSING']);
        DB::table('file_blobs')->where('id', $id)->update(['health' => 'VERIFIED']);
        DB::table('file_blobs')->where('id', $id)->update(['health' => 'QUARANTINED', 'scan_state' => 'INFECTED']);
        DB::table('file_blobs')->where('id', $id)->update(['health' => 'REJECTED']);
        foreach (['STAGED', 'VERIFIED', 'MISSING', 'QUARANTINED'] as $to) {
            $this->assertRefused($move(['health' => $to]), 'cannot move from REJECTED');
        }
    }

    public function test_the_blob_clock_ignores_what_the_writer_supplied(): void
    {
        foreach (['1999-01-01 00:00:00+00', '2099-01-01 00:00:00+00'] as $client) {
            $id = $this->blob(['created_at' => $client, 'health' => 'VERIFIED', 'verified_at' => $client]);
            $row = DB::table('file_blobs')->find($id);
            foreach ([$row->created_at, $row->verified_at] as $stored) {
                $this->assertLessThan(5.0, (float) DB::selectOne('select abs(extract(epoch from (clock_timestamp() - ?::timestamptz))) as d', [$stored])->d, $client);
            }
        }
        $staged = DB::table('file_blobs')->find($this->blob(['verified_at' => now()]));
        $this->assertNull($staged->verified_at, 'a STAGED blob is not verified');
    }

    // ---- events and receipts -------------------------------------------------------------------------------------------------------------------------

    public function test_events_are_append_only_tenant_safe_shaped_and_database_timed(): void
    {
        $blob = $this->blob();
        $id = DB::table('file_blob_events')->insertGetId($this->event($blob, ['occurred_at' => '1999-01-01']));
        $this->assertLessThan(5.0, (float) DB::selectOne('select abs(extract(epoch from (clock_timestamp() - ?::timestamptz))) as d', [DB::table('file_blob_events')->find($id)->occurred_at])->d);
        DB::table('file_blob_events')->insert($this->event($blob, ['actor_id' => null, 'service' => 'files.verify', 'event' => 'VERIFIED', 'from_health' => 'STAGED', 'to_health' => 'VERIFIED']));

        $this->assertRefused(fn () => DB::table('file_blob_events')->update(['reason' => 'rewritten']), 'append-only');
        $this->assertRefused(fn () => DB::table('file_blob_events')->delete(), 'append-only');
        $this->assertRefused(fn () => DB::table('file_blob_events')->insert($this->event($blob, ['company_id' => $this->otherCompany, 'actor_id' => null, 'service' => 's'])), 'file_blob_events_blob_fk');
        $this->assertRefused(fn () => DB::table('file_blob_events')->insert($this->event($blob, ['actor_id' => $this->otherUser])), 'file_blob_events_actor_fk');
        foreach ([['event' => 'DELETED'], ['to_health' => 'GONE'], ['from_health' => 'GONE'], ['expected_sha256' => 'x'], ['observed_sha256' => strtoupper(str_repeat('a', 64))], ['actor_id' => null],
            ['actor_id' => null, 'service' => ' '], ['reason' => ' '], ['evidence' => '[]'], ['evidence' => json_encode(['x' => str_repeat('y', 5000)])]] as $bad) {
            $this->assertRefused(fn () => DB::table('file_blob_events')->insert($this->event($blob, $bad)), 'file_blob_events_shape');
        }
    }

    public function test_receipts_are_append_only_unique_and_tenant_safe(): void
    {
        $blob = $this->blob();
        $row = ['company_id' => 1, 'operation' => 'stage_upload', 'operation_key' => 'k1', 'intent_digest' => str_repeat('a', 64), 'blob_id' => $blob, 'actor_id' => $this->user->id, 'recorded_at' => '1999-01-01'];
        $id = DB::table('file_operation_receipts')->insertGetId($row);
        $this->assertLessThan(5.0, (float) DB::selectOne('select abs(extract(epoch from (clock_timestamp() - ?::timestamptz))) as d', [DB::table('file_operation_receipts')->find($id)->recorded_at])->d);
        $this->assertRefused(fn () => DB::table('file_operation_receipts')->insert($row), 'file_operation_receipts_key_unique');
        $this->assertRefused(fn () => DB::table('file_operation_receipts')->update(['operation' => 'x']), 'append-only');
        $this->assertRefused(fn () => DB::table('file_operation_receipts')->delete(), 'append-only');
        $this->assertRefused(fn () => DB::table('file_operation_receipts')->insert([...$row, 'operation_key' => 'k2', 'company_id' => $this->otherCompany, 'actor_id' => null]), 'file_operation_receipts_blob_fk');
        $this->assertRefused(fn () => DB::table('file_operation_receipts')->insert([...$row, 'operation_key' => 'k3', 'actor_id' => $this->otherUser]), 'file_operation_receipts_actor_fk');
        foreach ([['operation' => ' '], ['operation_key' => ''], ['intent_digest' => 'NOPE']] as $bad) {
            $this->assertRefused(fn () => DB::table('file_operation_receipts')->insert([...$row, 'operation_key' => 'k4', ...$bad]), 'file_operation_receipts_shape');
        }
    }

    // ---- intents -------------------------------------------------------------------------------------------------------------------------------------

    public function test_an_intent_is_born_pending_and_cannot_be_forged_into_another_state(): void
    {
        $blob = $this->blob();
        $row = $this->intentRow();
        foreach ([['state' => 'STAGED', 'blob_id' => $blob], ['state' => 'COMMITTED', 'blob_id' => $blob], ['state' => 'ABORTED', 'error_code' => 'x'], ['blob_id' => $blob], ['error_code' => 'x'],
            ['expires_at' => now()->subMinute()]] as $bad) {
            $this->assertRefused(fn () => DB::table('file_upload_intents')->insert([...$row, ...$bad]), 'created PENDING, without a blob|file_upload_intents_shape');
        }
        foreach ([['owner_type_key' => 'Bad Key'], ['purpose_key' => 'x'], ['owner_binding_identity' => ' '], ['idempotency_key' => ' '], ['intent_digest' => 'NOPE'], ['expected_sha256' => 'NOPE'],
            ['expected_size' => 0], ['state' => 'DONE']] as $bad) {
            $this->assertRefused(fn () => DB::table('file_upload_intents')->insert([...$row, ...$bad]), 'file_upload_intents_shape|created PENDING, without a blob');
        }
        $id = DB::table('file_upload_intents')->insertGetId($row);
        $this->assertRefused(fn () => DB::table('file_upload_intents')->insert([...$this->intentRow(), 'idempotency_key' => $row['idempotency_key']]), 'file_upload_intents_key_unique');
        $this->assertRefused(fn () => DB::table('file_upload_intents')->insert([...$this->intentRow(), 'actor_id' => $this->otherUser]), 'file_upload_intents_actor_fk');
        $this->assertRefused(fn () => DB::table('file_upload_intents')->insert([...$this->intentRow(), 'company_id' => $this->otherCompany]), 'file_upload_intents_actor_fk');
        $this->assertSame('PENDING', DB::table('file_upload_intents')->find($id)->state);
    }

    public function test_the_intent_state_machine_identity_and_unreachable_committed(): void
    {
        $id = $this->intent();
        $blob = $this->blob();
        $set = fn (array $values) => fn () => DB::table('file_upload_intents')->where('id', $id)->update($values);

        foreach ([['owner_type_key' => 'other.owner'], ['purpose_key' => 'other.purpose'], ['owner_binding_identity' => 'company:2'], ['idempotency_key' => 'other'], ['intent_digest' => str_repeat('b', 64)],
            ['expected_size' => 11], ['expected_sha256' => str_repeat('c', 64)], ['expires_at' => now()->addDay()], ['actor_id' => $this->otherUser], ['created_at' => '1999-01-01']] as $bad) {
            $this->assertRefused($set($bad), 'keeps its identity|violates foreign key');
        }
        $this->assertRefused($set(['state' => 'COMMITTED', 'blob_id' => $blob]), 'attachment slice');
        $this->assertRefused($set(['state' => 'STAGED']), 'file_upload_intents_shape');
        $this->assertRefused($set(['state' => 'ABORTED']), 'file_upload_intents_shape');
        $this->assertRefused($set(['error_code' => 'x']), 'file_upload_intents_shape');
        $this->assertRefused($set(['state' => 'SOMETIMES']), 'file_upload_intents_shape|cannot move from PENDING to SOMETIMES');
        $this->assertRefused(fn () => DB::table('file_upload_intents')->where('id', $id)->delete(), 'cannot be deleted');

        DB::table('file_upload_intents')->where('id', $id)->update(['state' => 'STAGED', 'blob_id' => $blob]);
        $row = DB::table('file_upload_intents')->find($id);
        $this->assertSame('STAGED', $row->state);
        foreach (['PENDING', 'EXPIRED', 'ABORTED'] as $to) {
            $this->assertRefused($set(['state' => $to, 'error_code' => $to === 'ABORTED' ? 'x' : null]), 'cannot move from STAGED|file_upload_intents_shape');
        }
        $this->assertRefused($set(['blob_id' => $this->blob()]), 'keeps its blob and outcome');
        $this->assertRefused($set(['state' => 'COMMITTED']), 'attachment slice');

        foreach (['EXPIRED' => null, 'ABORTED' => 'size_exceeded'] as $to => $code) {
            $other = $this->intent();
            DB::table('file_upload_intents')->where('id', $other)->update(['state' => $to, 'error_code' => $code]);
            $this->assertRefused(fn () => DB::table('file_upload_intents')->where('id', $other)->update(['state' => 'PENDING', 'error_code' => null]), 'cannot move from');
        }
    }

    // ---- truncate, rollback --------------------------------------------------------------------------------------------------------------------------

    public function test_every_table_refuses_truncate_alone_or_with_the_tables_that_reference_it(): void
    {
        $blob = $this->blob();
        DB::table('file_blob_events')->insert($this->event($blob));
        $intent = $this->intent(['state' => 'PENDING']);
        DB::table('file_operation_receipts')->insert(['company_id' => 1, 'operation' => 'x', 'operation_key' => 'k', 'intent_digest' => str_repeat('a', 64), 'intent_id' => $intent, 'blob_id' => $blob, 'recorded_at' => now()]);

        $all = 'file_operation_receipts, file_upload_intents, file_blob_events, file_blobs';
        foreach (['file_operation_receipts', 'file_blob_events', 'file_upload_intents, file_operation_receipts'] as $set) {
            $this->assertRefused(fn () => DB::statement("TRUNCATE TABLE {$set}"), 'append-only');
        }
        // Owners' own tables (Documents artefacts) reference blobs, so a set with blobs is refused by PostgreSQL itself or by the guard; either way it is refused.
        $this->assertRefused(fn () => DB::statement("TRUNCATE TABLE {$all}"), 'append-only|cannot truncate a table referenced');
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE users CASCADE'), 'append-only');
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE file_blobs'), 'append-only|cannot truncate a table referenced');
        $guards = DB::select("select c.relname as tbl from pg_trigger t join pg_class c on c.oid = t.tgrelid join pg_proc p on p.oid = t.tgfoid
            where not t.tgisinternal and (t.tgtype & 32) <> 0 and p.proname = 'files_append_only_guard' order by 1");
        $this->assertSame(['file_blob_events', 'file_blobs', 'file_operation_receipts', 'file_upload_intents'], collect($guards)->pluck('tbl')->all());
        $this->assertSame(1, DB::table('file_blobs')->count());
    }

    public function test_rollback_works_when_empty_and_refuses_while_any_row_exists(): void
    {
        // Documents artefacts reference blobs, so that owner's tables go first and come back last.
        $artefacts = require base_path('database/migrations/2026_10_30_100000_create_document_artefacts.php');
        $artefacts->down();
        $migration = require base_path('database/migrations/2026_10_29_100000_create_files_foundation.php');
        $migration->down();
        foreach (['file_blobs', 'file_blob_events', 'file_upload_intents', 'file_operation_receipts'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertSame(0, (int) DB::selectOne("select count(*) c from pg_proc where proname like 'files\\_%'")->c, 'every Files function was removed');
        $migration->up();
        $artefacts->up();
        $this->assertTrue(Schema::hasTable('file_blobs'));

        $this->blob();
        try {
            $migration->down();
            $this->fail('Rolling back with file evidence present must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Plan a backout first', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('file_blobs'));
        $this->assertSame(1, DB::table('file_blobs')->count());
    }
}
