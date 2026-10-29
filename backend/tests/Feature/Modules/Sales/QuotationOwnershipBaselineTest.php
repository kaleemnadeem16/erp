<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Actions\CaptureQuotationAccessBaseline;
use App\Modules\Sales\Actions\ReassignQuotationOwner;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationOwnershipEvent;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Quotation\QuotationOwnershipOutcome;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

/**
 * QA1a — the internal baseline capture: legacy families stay UNRESOLVED (no backfill, no guess from created_by) until an approved mapping is applied, and capturing
 * is truthful, rerunnable and never automatic.
 */
class QuotationOwnershipBaselineTest extends TestCase
{
    use BuildsQuotationOwnership;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    private function capture(QuotationFamily $family, int $ownerId, ?int $unitId = null, string $source = 'Legacy owner sheet 2026-10', string $approval = 'APPROVAL-17', ?string $key = null, ?User $by = null): QuotationOwnershipOutcome
    {
        return app(CaptureQuotationAccessBaseline::class)($by ?? $this->assigner, $family->id, $ownerId, $unitId, $source, $approval, $key ?? $this->key(), 'Adopting a legacy quotation');
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            return (string) $refusal->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    public function test_existing_families_are_unresolved_and_nothing_backfills_them(): void
    {
        $legacy = $this->legacyFamily();

        $this->assertSame(['UNRESOLVED', null, null, 0, 0], [$legacy->access_baseline_state, $legacy->responsible_owner_id, $legacy->access_unit_id, $legacy->ownership_generation, $legacy->access_generation]);
        $this->assertSame(0, DB::table('quotation_ownership_events')->count());
        $this->assertSame($this->preparer->id, $legacy->created_by);
    }

    public function test_an_approved_mapping_is_captured_with_its_true_time_source_and_no_invented_history(): void
    {
        $legacy = $this->legacyFamily($this->preparer);
        $unit = $this->unit();
        $before = now()->subSecond();
        $outcome = $this->capture($legacy, $this->colleague->id, $unit);

        $this->assertSame([$this->colleague->id, $unit, 1, 1, true, false], [$outcome->ownerId, $outcome->accessUnitId, $outcome->ownershipGeneration, $outcome->accessGeneration, $outcome->changed, $outcome->replayed]);
        $legacy->refresh();
        $this->assertSame(['CAPTURED', $this->colleague->id, $unit], [$legacy->access_baseline_state, $legacy->responsible_owner_id, $legacy->access_unit_id]);
        $this->assertSame($this->preparer->id, $legacy->created_by, 'the creator is provenance, not the guessed owner');
        $event = $legacy->ownershipEvents()->sole();
        $this->assertSame([QuotationOwnershipEvent::CAPTURED_BASELINE, 1, null, null, null, $this->colleague->id, $unit, 1, 'Legacy owner sheet 2026-10', 'APPROVAL-17', $this->assigner->id],
            [$event->kind, $event->generation, $event->previous_event_id, $event->old_owner_id, $event->old_unit_id, $event->new_owner_id, $event->new_unit_id, $event->access_generation,
                $event->baseline_source_reference, $event->approval_reference, $event->actor_id]);
        $this->assertTrue($event->recorded_at->greaterThanOrEqualTo($before), 'the capture time is the actual time, not a historic one');
    }

    public function test_capture_is_rerunnable_and_contradictions_are_refused(): void
    {
        $legacy = $this->legacyFamily();
        $key = $this->key();
        $first = $this->capture($legacy, $this->colleague->id, null, key: $key);

        // Same key: replay. A fresh key with the identical mapping: idempotent, nothing new recorded.
        $this->assertTrue(app(CaptureQuotationAccessBaseline::class)($this->assigner, $legacy->id, $this->colleague->id, null, 'Legacy owner sheet 2026-10', 'APPROVAL-17', $key, 'Adopting a legacy quotation')->replayed);
        $again = $this->capture($legacy, $this->colleague->id);
        $this->assertFalse($again->changed);
        $this->assertSame($first->ownershipGeneration, $again->ownershipGeneration);
        $this->assertSame(1, DB::table('quotation_ownership_events')->count());

        // Another mapping on a captured family contradicts it; so does a different approval reference.
        $this->assertSame('BASELINE_CONFLICT', $this->refusal(fn () => $this->capture($legacy, $this->assigner->id)));
        $this->assertSame('BASELINE_CONFLICT', $this->refusal(fn () => $this->capture($legacy, $this->colleague->id, approval: 'APPROVAL-18')));
        $this->assertSame('REQUEST_KEY_REUSED', $this->refusal(fn () => $this->capture($legacy, $this->assigner->id, key: $key)));
        $this->assertSame(1, DB::table('quotation_ownership_events')->count());
    }

    public function test_a_native_family_has_nothing_to_capture(): void
    {
        $native = $this->nativeFamily();
        $before = DB::table('quotation_ownership_events')->count();

        $this->assertSame('BASELINE_NOT_APPLICABLE', $this->refusal(fn () => $this->capture($native, $this->colleague->id)));
        $this->assertSame($before, DB::table('quotation_ownership_events')->count());
        $this->assertSame('NATIVE', $native->refresh()->access_baseline_state);
    }

    public function test_an_unapproved_unknown_or_ineligible_mapping_leaves_the_family_unresolved(): void
    {
        $legacy = $this->legacyFamily();
        foreach ([$this->leaver->id, $this->otherUser, 9999999] as $owner) {
            $this->assertSame('OWNER_NOT_ELIGIBLE', $this->refusal(fn () => $this->capture($legacy, $owner)));
        }
        foreach ([$this->unit('OLD', 'ARCHIVED'), $this->unit('THEIRS', 'ACTIVE', $this->otherCompany), 9999999] as $unit) {
            $this->assertSame('ACCESS_UNIT_NOT_FOUND', $this->refusal(fn () => $this->capture($legacy, $this->colleague->id, $unit)));
        }
        foreach ([['', 'APPROVAL-17'], ['   ', 'APPROVAL-17'], ['Sheet', ''], ['Sheet', '  '], [str_repeat('x', 501), 'A']] as [$source, $approval]) {
            $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->capture($legacy, $this->colleague->id, source: $source, approval: $approval)));
        }
        $this->assertSame('QUOTATION_NOT_FOUND', $this->refusal(fn () => app(CaptureQuotationAccessBaseline::class)($this->assigner, 9999999, $this->colleague->id, null, 's', 'a', $this->key(), 'r')));
        $this->assertSame(['UNRESOLVED', 0], [$legacy->refresh()->access_baseline_state, DB::table('quotation_ownership_events')->count()]);
    }

    public function test_capture_needs_the_same_independent_authority_and_is_refused_to_everyone_while_staged(): void
    {
        $legacy = $this->legacyFamily();
        foreach ([$this->assignOnly, $this->viewOnly, $this->preparer] as $holder) {
            try {
                $this->capture($legacy, $this->colleague->id, by: $holder);
                $this->fail('Expected an authorisation refusal.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('UNRESOLVED', $legacy->refresh()->access_baseline_state);

        $this->app->bind(SalesDutyManifest::class, fn () => SalesDutyManifest::shipped());
        $this->expectException(AuthorizationException::class);
        $this->capture($legacy, $this->colleague->id);
    }

    public function test_a_captured_family_then_follows_the_ordinary_ownership_protocol(): void
    {
        $legacy = $this->legacyFamily();
        $this->capture($legacy, $this->colleague->id);
        $outcome = app(ReassignQuotationOwner::class)($this->assigner, $legacy->id, $this->assigner->id, 1, 1, $this->key(), 'After adoption');

        $this->assertSame([$this->assigner->id, 2, 1], [$outcome->ownerId, $outcome->ownershipGeneration, $outcome->accessGeneration]);
        $kinds = $legacy->refresh()->ownershipEvents()->pluck('kind')->all();
        $this->assertSame([QuotationOwnershipEvent::CAPTURED_BASELINE, QuotationOwnershipEvent::REASSIGNED], $kinds);
    }

    public function test_capture_is_internal_nothing_calls_it_automatically(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => str_contains($u, 'baseline') || str_contains($u, 'ownership'))->values()->all());

        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/CaptureQuotationAccessBaseline::class|new CaptureQuotationAccessBaseline|Actions\\\\CaptureQuotationAccessBaseline;/', (string) file_get_contents($file->getPathname())) === 1 && ! str_ends_with($file->getFilename(), 'CaptureQuotationAccessBaseline.php')) {
                $callers[] = $file->getFilename();
            }
        }
        $this->assertSame([], $callers, 'no command, controller, provider or migration references the capture');
        $this->assertStringNotContainsString('CaptureQuotationAccessBaseline', (string) file_get_contents(base_path('database/migrations/2026_10_27_100000_add_quotation_ownership_and_evidence.php')));
    }
}
