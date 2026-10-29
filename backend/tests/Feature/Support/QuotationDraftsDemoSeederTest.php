<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Actions\PreviewQuotation;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Database\Seeders\Demo\Sales\QuotationDraftsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * QuotationDraftsDemoSeeder: it does nothing while the quotation duties are STAGED (the shipped manifest), and with the duties activated (as the local preview does)
 * it creates five named drafts through the real Actions whose preview outcomes match what the seeder's comments claim; a rerun adds nothing.
 */
final class QuotationDraftsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function activate(): void
    {
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => in_array($duty->name, [PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW], true)
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    /** @return array<string, mixed> */
    private function preview(User $by, int $family): array
    {
        return app(PreviewQuotation::class)($by, QuotationRevision::query()->where('family_id', $family)->firstOrFail()->id);
    }

    public function test_it_does_nothing_while_the_quotation_duties_are_staged(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(0, QuotationFamily::query()->count());
        $this->assertDatabaseMissing('users', ['email' => QuotationDraftsDemoSeeder::PREPARER]);
    }

    public function test_the_named_drafts_behave_as_described_and_a_rerun_adds_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $this->activate();
        $this->seed(QuotationDraftsDemoSeeder::class);
        $this->seed(QuotationDraftsDemoSeeder::class);

        $this->assertSame(5, QuotationFamily::query()->count());
        $this->assertSame(10, QuotationDraftLine::query()->count());
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $preparer = User::query()->where('email', QuotationDraftsDemoSeeder::PREPARER)->firstOrFail();
        $revisions = QuotationRevision::query()->orderBy('id')->get();
        [$simple, $deal, $incomplete, $choices, $usd] = $revisions->all();

        $this->assertSame(['COMPLETE', '860000.0000'], [$this->preview($preparer, $simple->family_id)['totals']['net']['certainty'], $this->preview($preparer, $simple->family_id)['totals']['net']['amount']]);
        $this->assertNotNull($deal->family->current_opportunity_id, 'The deal draft is linked to the open Plant room refurbishment deal.');
        $partial = $this->preview($preparer, $deal->family_id)['totals']['net'];
        $this->assertSame(['PARTIAL', '320000.0000'], [$partial['certainty'], $partial['known_amount']]);
        $this->assertSame(4, $incomplete->input_generation);
        try {
            $this->preview($preparer, $incomplete->family_id);
            $this->fail('An incomplete draft cannot be previewed.');
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('RECIPIENT_REQUIRED', $refusal->reasonCode);
        }
        $this->assertEqualsCanonicalizing(['CHOOSE_ONE', 'CHOOSE_ONE', 'OPTIONAL'], $choices->lines()->pluck('membership')->all());
        $this->assertSame(1, $choices->lines()->where('is_baseline', true)->count());
        $line = $this->preview($preparer, $usd->family_id)['lines'][0];
        $this->assertSame('1250.0000', $line['unit_price']);
        $this->assertSame('350125.0000', $line['base_unit_price'], 'USD 1250 at the pinned 280.10 rate.');
    }
}
