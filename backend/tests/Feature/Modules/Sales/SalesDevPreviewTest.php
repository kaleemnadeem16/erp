<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * M1 — the local-development-only way to switch the staged quotation duties on. Nothing about the shipped catalogue changes; the flag is impossible outside
 * `local`; and the duties confer nothing until a named login is explicitly granted them.
 */
class SalesDevPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = PermissionVocabulary::QUOTATION_VIEW;

    private const PREPARE = PermissionVocabulary::QUOTATION_PREPARE;

    private function environment(string $environment, bool $flag): void
    {
        $this->app['env'] = $environment;
        config(['sales.dev_preview.quotation_duties' => $flag]);
        $this->app->forgetInstance(SalesDutyManifest::class);
    }

    public function test_the_flag_is_off_by_default_and_the_shipped_catalogue_stays_staged(): void
    {
        $this->assertFalse((bool) config('sales.dev_preview.quotation_duties'));
        $this->assertFalse(SalesDevPreview::enabled());
        $shipped = SalesDutyManifest::shipped();
        $this->assertSame(DutyStatus::Staged, $shipped->find(self::VIEW)->status);
        $this->assertSame(DutyStatus::Staged, $shipped->find(self::PREPARE)->status);
        $this->assertFalse(app(SalesDutyManifest::class)->isActive(self::PREPARE));
    }

    #[DataProvider('nonLocalEnvironments')]
    public function test_the_flag_is_ignored_and_reported_outside_a_local_environment(string $environment): void
    {
        Log::spy();
        $this->environment($environment, true);

        $manifest = app(SalesDutyManifest::class);
        $this->assertFalse(SalesDevPreview::enabled());
        $this->assertFalse($manifest->isActive(self::VIEW));
        $this->assertFalse($manifest->isActive(self::PREPARE));
        $this->assertSame(SalesDutyManifest::shipped()->activeNames(), $manifest->activeNames(), 'Nothing but the eight CRM duties is active.');
        Log::shouldHaveReceived('critical')->once();
    }

    /** @return array<string, array{0: string}> */
    public static function nonLocalEnvironments(): array
    {
        return ['production' => ['production'], 'staging' => ['staging'], 'testing' => ['testing'], 'an unnamed environment' => ['dev']];
    }

    public function test_in_a_local_environment_only_view_and_prepare_become_active_and_the_shipped_manifest_is_unchanged(): void
    {
        $this->environment('local', true);

        $manifest = app(SalesDutyManifest::class);
        $this->assertTrue(SalesDevPreview::enabled());
        $this->assertTrue($manifest->isActive(self::VIEW));
        $this->assertTrue($manifest->isActive(self::PREPARE));
        foreach ([PermissionVocabulary::FINANCIAL_CONFIGURE, PermissionVocabulary::DOCUMENT_REPAIR, 'sales.quotation.issue', 'sales.quotation.assign'] as $other) {
            $this->assertFalse($manifest->isActive($other), "{$other} stays off");
        }
        $this->assertFalse(SalesDutyManifest::shipped()->isActive(self::VIEW), 'The shipped manifest is untouched.');
        $this->assertNotContains(self::VIEW, PermissionVocabulary::all(), 'The shipped vocabulary helper is unchanged.');
        $registry = app(PermissionRegistry::class);
        $this->assertContains(self::VIEW, $registry->all());
        $this->assertContains(self::PREPARE, $registry->all());
        $this->assertContains(self::PREPARE, $registry->notAutomaticallyGranted(), 'Never part of an automatic grant.');
        $this->assertContains(self::VIEW, $registry->notAutomaticallyGranted(), 'Never part of an automatic grant.');
    }

    public function test_activation_by_the_manifest_helper_is_limited_to_staged_duties(): void
    {
        $shipped = SalesDutyManifest::shipped();

        foreach (['sales.pipeline.view', 'sales.delivery.send', 'sales.does.not-exist'] as $name) {
            try {
                $shipped->withActivated([$name]);
                $this->fail("{$name} should not be activatable");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFalse($shipped->isActive(self::VIEW));
    }

    public function test_the_grant_command_refuses_unless_the_preview_is_enabled(): void
    {
        $user = User::factory()->create();
        foreach ([['production', true], ['local', false], ['testing', true]] as [$environment, $flag]) {
            $this->environment($environment, $flag);
            $this->artisan('sales:dev-grant-quotation', ['email' => $user->email])->assertFailed();
        }

        $this->assertSame([], $user->fresh()->getAllPermissions()->pluck('name')->all());
    }

    public function test_the_grant_command_grants_only_the_preview_duties_to_the_named_login_and_can_revoke(): void
    {
        $this->environment('local', true);
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->artisan('sales:dev-grant-quotation', ['email' => $user->email])->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->assertEqualsCanonicalizing(SalesDevPreview::all(), $user->fresh()->getAllPermissions()->pluck('name')->all());
        $this->assertSame([], $other->fresh()->getAllPermissions()->pluck('name')->all());
        $this->assertTrue(SalesAccess::may($user->fresh(), self::PREPARE));

        $this->artisan('sales:dev-grant-quotation', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertSame([], $user->fresh()->getAllPermissions()->pluck('name')->all());
        $this->artisan('sales:dev-grant-quotation', ['email' => 'nobody@example.test'])->assertFailed();
    }

    public function test_a_granted_developer_can_use_the_quotation_endpoints_end_to_end_and_an_ungranted_one_cannot(): void
    {
        $this->environment('local', true);
        $pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $pkr->id]);
        $party = Party::create(['code' => 'P', 'name' => 'P Ltd']);
        $developer = User::factory()->create();
        $ungranted = User::factory()->create();
        $this->artisan('sales:dev-grant-quotation', ['email' => $developer->email])->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);

        Sanctum::actingAs($ungranted->fresh());
        $this->postJson('/api/v1/quotations', ['recipient_party_id' => $party->id], ['Idempotency-Key' => 'dev-0'])->assertForbidden();

        Sanctum::actingAs($developer->fresh());
        $created = $this->postJson('/api/v1/quotations', ['recipient_party_id' => $party->id], ['Idempotency-Key' => 'dev-1'])->assertCreated();
        $revision = $created->json('data.revision_id');
        $this->getJson("/api/v1/quotations/{$revision}")->assertOk()->assertJsonPath('data.state', 'DRAFT');
        $this->getJson("/api/v1/quotations/{$revision}/preview")->assertOk();
        $this->assertSame(1, QuotationRevision::query()->count());
    }
}
