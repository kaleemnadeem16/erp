<?php

namespace Tests\Feature\Support;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\RateType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExchangeRateApiTest extends TestCase
{
    use RefreshDatabase;

    private Currency $usd;

    private Currency $pkr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);
        $this->pkr = Currency::create(['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => 'Rs']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/exchange-rates')->assertUnauthorized();
    }

    public function test_a_rate_can_be_manually_entered(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/exchange-rates', [
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '280.5',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot->value,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.from_currency_id', $this->usd->id);
        $response->assertJsonPath('data.rate_type', 'SPOT');
        $response->assertJsonPath('data.source', 'manual');
        $this->assertDatabaseHas('exchange_rates', [
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate_date' => '2026-08-13',
            'source' => 'manual',
        ]);
    }

    public function test_source_cannot_be_set_by_the_client(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/exchange-rates', [
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '280.5',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot->value,
            'source' => 'some-api',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.source', 'manual');
    }

    public function test_a_rate_cannot_be_fabricated_between_a_currency_and_itself(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/exchange-rates', [
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->usd->id,
            'rate' => '1',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot->value,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('from_currency_id');
    }

    public function test_duplicate_pair_date_and_type_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();

        ExchangeRate::create([
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '280.5',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/exchange-rates', [
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '281.0',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot->value,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('rate_date');
    }

    public function test_a_concurrent_duplicate_is_rejected_with_a_422_not_a_500(): void
    {
        $inserted = false;

        ExchangeRate::creating(function (ExchangeRate $rate) use (&$inserted): void {
            if ($inserted) {
                return;
            }

            $inserted = true;

            DB::table('exchange_rates')->insert([
                'company_id' => 1,
                'from_currency_id' => $rate->from_currency_id,
                'to_currency_id' => $rate->to_currency_id,
                'rate' => $rate->rate,
                'rate_date' => $rate->rate_date,
                'rate_type' => $rate->rate_type->value,
                'source' => 'manual',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/exchange-rates', [
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '280.5',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot->value,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('rate_date');
    }

    public function test_rate_must_fit_the_database_decimal_precision(): void
    {
        $payload = [
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot->value,
        ];

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/exchange-rates', [...$payload, 'rate' => '12345678901'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate');

        $this->postJson('/api/v1/exchange-rates', [...$payload, 'rate' => '1.1234567890'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate');
    }

    public function test_index_lists_rates_and_can_be_filtered_by_rate_type(): void
    {
        $user = User::factory()->create();

        ExchangeRate::create([
            'from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id,
            'rate' => '280.5', 'rate_date' => '2026-08-13', 'rate_type' => RateType::Spot,
        ]);
        ExchangeRate::create([
            'from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id,
            'rate' => '281.0', 'rate_date' => '2026-08-13', 'rate_type' => RateType::Closing,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/exchange-rates?filter[rate_type]=CLOSING');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.rate_type', 'CLOSING');
    }

    public function test_index_rejects_invalid_filters_and_pagination(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/exchange-rates?filter[from_currency_id]=not-an-id')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filter.from_currency_id');

        $this->getJson('/api/v1/exchange-rates?filter[rate_date]=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filter.rate_date');

        $this->getJson('/api/v1/exchange-rates?per_page=-1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_show_can_include_the_currency_relations(): void
    {
        $user = User::factory()->create();

        $rate = ExchangeRate::create([
            'from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id,
            'rate' => '280.5', 'rate_date' => '2026-08-13', 'rate_type' => RateType::Spot,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/exchange-rates/{$rate->id}?include=from_currency,to_currency");

        $response->assertOk();
        $response->assertJsonPath('data.from_currency.code', 'USD');
        $response->assertJsonPath('data.to_currency.code', 'PKR');
    }

    public function test_only_the_rate_itself_can_be_updated(): void
    {
        $user = User::factory()->create();

        $rate = ExchangeRate::create([
            'from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id,
            'rate' => '280.5', 'rate_date' => '2026-08-13', 'rate_type' => RateType::Spot,
        ]);

        $response = $this->actingAs($user)->patchJson("/api/v1/exchange-rates/{$rate->id}", [
            'rate' => '282.0',
            'rate_date' => '2099-01-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.rate', '282.000000000');
        $response->assertJsonPath('data.rate_date', '2026-08-13');
    }

    public function test_a_rate_can_be_deleted(): void
    {
        $user = User::factory()->create();

        $rate = ExchangeRate::create([
            'from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id,
            'rate' => '280.5', 'rate_date' => '2026-08-13', 'rate_type' => RateType::Spot,
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/exchange-rates/{$rate->id}")->assertNoContent();

        $this->assertDatabaseMissing('exchange_rates', ['id' => $rate->id]);
    }

    public function test_another_companys_rate_is_not_visible(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Other Company', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $otherCompanyRate = ExchangeRate::create([
            'from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id,
            'rate' => '280.5', 'rate_date' => '2026-08-13', 'rate_type' => RateType::Spot,
        ]);

        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/api/v1/exchange-rates/{$otherCompanyRate->id}")->assertNotFound();
    }
}
