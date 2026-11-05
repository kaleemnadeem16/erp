<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Actions\CorrectManualQuotationDelivery;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * Correcting or withdrawing a recorded manual delivery: always a NEW record that names the old one and says why; the old record is never changed; the current record of a chain
 * is the only one that can be corrected; a withdrawal is final; same key and content is the same record. A statement by a person, never receipt by the customer.
 */
class QuotationDeliveryCorrectionTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private const DUTIES = [P::DOCUMENT_GENERATE, P::DOCUMENT_VIEW, P::DOCUMENT_DOWNLOAD, P::DOCUMENT_REPAIR, P::DELIVERY_VIEW, P::DELIVERY_RECORD, P::QUOTATION_VIEW];

    private User $clerk;

    private int $revision;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
        $this->setUpIssuing();
        foreach (self::DUTIES as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->clerk = User::factory()->create();
        $this->clerk->givePermissionTo(self::DUTIES);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $draft = $this->preparedDraft();
        app(IssueQuotation::class)($this->checker, $draft->id, QuotationRevision::findOrFail($draft->id)->input_generation, $this->issueKey());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->revision = $draft->id;
        $names = [...self::DUTIES, P::QUOTATION_PREPARE, P::QUOTATION_ISSUE];
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $names, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate, $d->scope) : $d, SalesDutyManifest::shipped()->all())));
        Sanctum::actingAs($this->clerk);
        $this->postJson($this->url('/document'), [], ['Idempotency-Key' => 'gen'])->assertOk();
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/quotations/{$this->revision}{$tail}";
    }

    /** @return array<string, mixed> */
    private function body(array $over = []): array
    {
        return ['channel' => 'HAND', 'delivered_on' => now()->format('Y-m-d'), 'recipient_name' => 'Mr Customer', 'note' => 'copy kept', ...$over];
    }

    private function record(string $key = 'rec-1'): int
    {
        return (int) $this->postJson($this->url('/deliveries'), $this->body(), ['Idempotency-Key' => $key])->assertCreated()->json('data.id');
    }

    public function test_a_correction_appends_a_record_with_a_reason_and_leaves_the_original_untouched(): void
    {
        $first = $this->record();
        $before = (array) DB::table('quotation_document_deliveries')->where('id', $first)->first();

        $fix = $this->postJson($this->url("/deliveries/{$first}/correction"), $this->body(['recipient_name' => 'Ms Customer', 'channel' => 'COURIER', 'reason' => 'Wrong recipient']), ['Idempotency-Key' => 'fix-1'])
            ->assertCreated()->assertJsonPath('data.kind', 'CORRECTION')->assertJsonPath('data.corrects_id', $first)->assertJsonPath('data.correction_reason', 'Wrong recipient')
            ->assertJsonPath('data.is_current', true);
        $this->postJson($this->url("/deliveries/{$first}/correction"), $this->body(['recipient_name' => 'Ms Customer', 'channel' => 'COURIER', 'reason' => 'Wrong recipient']), ['Idempotency-Key' => 'fix-1'])
            ->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $fix->json('data.id'));

        $this->assertSame($before, (array) DB::table('quotation_document_deliveries')->where('id', $first)->first(), 'the corrected record is unchanged');
        $list = $this->getJson($this->url('/deliveries'))->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$fix->json('data.id'), null], [$list->json('data.0.superseded_by_id'), $list->json('data.1.superseded_by_id')]);
        $this->assertSame([false, true], [$list->json('data.0.is_current'), $list->json('data.1.is_current')]);
        $this->assertSame('RECORD', $list->json('data.0.kind'));
    }

    public function test_a_withdrawal_repeats_the_values_is_final_and_a_correction_target_must_be_current(): void
    {
        $first = $this->record();
        $withdrawal = $this->postJson($this->url("/deliveries/{$first}/correction"), ['withdraw' => true, 'reason' => 'It was never handed over'], ['Idempotency-Key' => 'wd-1'])
            ->assertCreated()->assertJsonPath('data.kind', 'WITHDRAWAL')->assertJsonPath('data.recipient_name', 'Mr Customer')->assertJsonPath('data.is_current', false);
        $id = (int) $withdrawal->json('data.id');

        $this->postJson($this->url("/deliveries/{$first}/correction"), $this->body(['reason' => 'again']), ['Idempotency-Key' => 'x-1'])->assertStatus(409)->assertJsonPath('reason_code', 'DELIVERY_ALREADY_CORRECTED');
        $this->postJson($this->url("/deliveries/{$id}/correction"), $this->body(['reason' => 'undo']), ['Idempotency-Key' => 'x-2'])->assertStatus(409)->assertJsonPath('reason_code', 'DELIVERY_WITHDRAWN');
        $this->postJson($this->url('/deliveries'), $this->body(), ['Idempotency-Key' => 'rec-2'])->assertCreated();
        $this->assertSame(3, DB::table('quotation_document_deliveries')->count());
    }

    public function test_a_reason_a_known_record_and_a_matching_key_are_required(): void
    {
        $first = $this->record();
        $this->postJson($this->url("/deliveries/{$first}/correction"), $this->body(), ['Idempotency-Key' => 'k-1'])->assertStatus(422);
        $this->postJson($this->url("/deliveries/{$first}/correction"), $this->body(['reason' => '   ']), ['Idempotency-Key' => 'k-2'])->assertStatus(422);
        $this->postJson($this->url("/deliveries/{$first}/correction"), $this->body(['reason' => 'ok', 'delivered_on' => now()->addDays(9)->format('Y-m-d')]), ['Idempotency-Key' => 'k-3'])->assertStatus(422);
        $this->postJson($this->url('/deliveries/999999/correction'), $this->body(['reason' => 'ok']), ['Idempotency-Key' => 'k-4'])->assertNotFound();
        $this->postJson($this->url("/deliveries/{$first}/correction"), $this->body(['reason' => 'ok', 'recipient_name' => 'A']), ['Idempotency-Key' => 'k-5'])->assertCreated();
        $this->postJson($this->url("/deliveries/{$first}/correction"), $this->body(['reason' => 'ok', 'recipient_name' => 'B']), ['Idempotency-Key' => 'k-5'])
            ->assertStatus(409)->assertJsonPath('reason_code', 'DELIVERY_KEY_REUSED');
        $this->assertSame(2, DB::table('quotation_document_deliveries')->count());
    }

    public function test_the_database_refuses_edits_and_inconsistent_withdrawals(): void
    {
        $first = $this->record();
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('quotation_document_deliveries')->where('id', $first)->update(['recipient_name' => 'Changed'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('quotation_document_deliveries')->where('id', $first)->delete()), QueryException::class);
        $row = (array) DB::table('quotation_document_deliveries')->where('id', $first)->first();
        unset($row['id']);
        $base = [...$row, 'corrects_id' => $first, 'operation_key' => 'raw-1', 'intent_digest' => str_repeat('a', 64)];
        // a withdrawal that does not repeat the record, a reason without a correction, a withdrawal without a reason
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('quotation_document_deliveries')->insert([...$base, 'is_withdrawal' => true, 'correction_reason' => 'x', 'recipient_name' => 'Someone else'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('quotation_document_deliveries')->insert([...$base, 'is_withdrawal' => true, 'correction_reason' => null])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('quotation_document_deliveries')->insert([...$base, 'corrects_id' => null, 'correction_reason' => 'x', 'operation_key' => 'raw-2'])), QueryException::class);
    }

    public function test_authority_is_the_record_duty_and_quotation_view_and_a_stranger_sees_nothing(): void
    {
        $first = $this->record();
        $before = DB::table('quotation_document_deliveries')->count();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([P::DELIVERY_VIEW, P::QUOTATION_VIEW]);
        $noView = User::factory()->create();
        $noView->givePermissionTo([P::DELIVERY_RECORD]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([$viewer, $noView] as $user) {
            Sanctum::actingAs($user);
            $this->postJson($this->url("/deliveries/{$first}/correction"), $this->body(['reason' => 'x']), ['Idempotency-Key' => 'a-'.$user->id])->assertForbidden();
            $this->assertThrows(fn () => app(CorrectManualQuotationDelivery::class)($user, $this->revision, $first, null, 'x', 'direct-'.$user->id), AuthorizationException::class);
        }
        $this->assertSame($before, DB::table('quotation_document_deliveries')->count());
        $this->assertThrows(fn () => app(CorrectManualQuotationDelivery::class)($this->clerk, 999999, $first, null, 'x', 'direct-unknown'), DomainInvariantException::class);
    }

    public function test_the_document_history_shows_only_allowlisted_detail_fields(): void
    {
        $this->get($this->url('/document/download'))->assertOk();
        $this->get($this->url('/document/download?intent=print'))->assertOk();
        $history = $this->getJson($this->url('/document/history'))->assertOk();
        $this->assertSame(['GENERATED', 'DOWNLOAD_DELIVERED', 'PRINT_DELIVERED'], array_column($history->json('data'), 'event'));
        $this->assertSame(['pages'], array_keys($history->json('data.0.detail')));
        $this->assertSame(['actor_id'], array_keys($history->json('data.1.detail')));
        $this->assertStringNotContainsString('renderer', (string) $history->getContent());
        $this->assertStringNotContainsString('document-pdf', (string) $history->getContent());
    }
}
