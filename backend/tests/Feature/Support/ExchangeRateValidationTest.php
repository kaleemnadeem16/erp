<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Actions\ManageExchangeRate;
use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/** PX5: a manual exchange rate is strictly positive and exactly representable, at the Action, the HTTP edge and the database. */
class ExchangeRateValidationTest extends TestCase
{
    use RefreshDatabase;

    private Currency $usd;

    private Currency $pkr;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2]);
        $this->pkr = Currency::create(['code' => 'PKR', 'name' => 'Pakistan Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        $this->actor = PermissionedActor::grant(User::factory()->create(), ['currency.exchange-rate.manage']);
    }

    /** @return array<string, string> */
    private function payload(string $rate, string $date = '2026-10-01'): array
    {
        return ['from_currency_id' => (string) $this->usd->id, 'to_currency_id' => (string) $this->pkr->id, 'rate' => $rate, 'rate_date' => $date, 'rate_type' => 'SPOT'];
    }

    private function code(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('rate', $refusal->field);

            return (string) $refusal->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    public function test_the_action_refuses_zero_and_malformed_rates_for_every_caller(): void
    {
        $manage = app(ManageExchangeRate::class);
        $attributes = fn (mixed $rate): array => ['from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id, 'rate' => $rate, 'rate_date' => '2026-10-01', 'rate_type' => 'SPOT'];

        $this->assertSame('EXCHANGE_RATE_NOT_POSITIVE', $this->code(fn () => $manage->create($this->actor, $attributes('0'))));
        $this->assertSame('EXCHANGE_RATE_NOT_POSITIVE', $this->code(fn () => $manage->create($this->actor, $attributes('0.000000000'))));
        $this->assertSame('EXCHANGE_RATE_INVALID', $this->code(fn () => $manage->create($this->actor, $attributes('-1'))));
        $this->assertSame('EXCHANGE_RATE_INVALID', $this->code(fn () => $manage->create($this->actor, $attributes('1.0000000001'))));
        $this->assertSame('EXCHANGE_RATE_INVALID', $this->code(fn () => $manage->create($this->actor, $attributes('12345678901'))));
        $this->assertSame('EXCHANGE_RATE_INVALID', $this->code(fn () => $manage->create($this->actor, $attributes(1.5))));
        $this->assertSame('EXCHANGE_RATE_INVALID', $this->code(fn () => $manage->create($this->actor, $attributes(null))));
        $this->assertSame('EXCHANGE_RATE_INVALID', $this->code(fn () => $manage->create($this->actor, $attributes('1e3'))));
        $this->assertSame(0, ExchangeRate::query()->count());
    }

    public function test_valid_rates_are_stored_exactly_up_to_nine_places(): void
    {
        $manage = app(ManageExchangeRate::class);
        foreach (['280.123456789', '0.000000001', '9999999999.999999999', '1'] as $index => $rate) {
            $manage->create($this->actor, $this->payload($rate, '2026-10-0'.($index + 1)));
        }
        $this->assertSame('280.123456789', ExchangeRate::query()->whereDate('rate_date', '2026-10-01')->value('rate'));
        $this->assertSame('0.000000001', ExchangeRate::query()->whereDate('rate_date', '2026-10-02')->value('rate'));
        $this->assertSame('9999999999.999999999', ExchangeRate::query()->whereDate('rate_date', '2026-10-03')->value('rate'));
    }

    public function test_updating_a_rate_is_judged_the_same_way_and_a_foreign_rate_is_not_found(): void
    {
        $manage = app(ManageExchangeRate::class);
        $rate = $manage->create($this->actor, $this->payload('280.5'));

        $this->assertSame('EXCHANGE_RATE_NOT_POSITIVE', $this->code(fn () => $manage->update($this->actor, $rate, ['rate' => '0'])));
        $this->assertSame('280.500000000', $rate->refresh()->rate);
        $this->assertSame('281.000000000', $manage->update($this->actor, $rate, ['rate' => '281'])->refresh()->rate);

        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $foreignId = DB::table('exchange_rates')->insertGetId(['company_id' => $otherCompany, 'from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id,
            'rate' => '1.000000000', 'rate_date' => '2026-10-01', 'rate_type' => 'SPOT', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = ExchangeRate::query()->withoutGlobalScopes()->findOrFail($foreignId);
        $this->expectException(ModelNotFoundException::class);
        $manage->update($this->actor, $foreign, ['rate' => '2']);
    }

    public function test_the_http_edge_refuses_a_zero_rate_that_the_string_size_rule_used_to_let_through(): void
    {
        $this->actingAs($this->actor);

        foreach (['0', '0.000000000', '-5', '1.0000000001', 'abc'] as $rate) {
            $this->postJson('/api/v1/exchange-rates', $this->payload($rate))->assertUnprocessable()->assertJsonValidationErrors('rate');
        }
        $id = $this->postJson('/api/v1/exchange-rates', $this->payload('280.123456789'))->assertCreated()->assertJsonPath('data.rate', '280.123456789')->json('data.id');
        $this->patchJson("/api/v1/exchange-rates/{$id}", ['rate' => '0'])->assertUnprocessable()->assertJsonValidationErrors('rate');
        $this->patchJson("/api/v1/exchange-rates/{$id}", ['rate' => '281.5'])->assertOk()->assertJsonPath('data.rate', '281.500000000');
    }

    public function test_the_database_refuses_a_non_positive_rate_behind_the_action(): void
    {
        $row = ['company_id' => 1, 'from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id, 'rate_date' => '2026-10-01', 'rate_type' => 'SPOT', 'created_at' => now(), 'updated_at' => now()];
        foreach (['0', '-1.5'] as $rate) {
            try {
                DB::transaction(fn () => DB::table('exchange_rates')->insert($row + ['rate' => $rate]));
                $this->fail("The database must refuse a rate of {$rate}.");
            } catch (QueryException $error) {
                $this->assertStringContainsString('exchange_rates_rate_positive_check', $error->getMessage());
            }
        }
    }

    public function test_the_migration_stops_without_touching_stored_rates_when_one_is_not_positive(): void
    {
        $migration = require database_path('migrations/2026_10_16_110000_require_positive_exchange_rates.php');
        $migration->down();
        DB::table('exchange_rates')->insert(['company_id' => 1, 'from_currency_id' => $this->usd->id, 'to_currency_id' => $this->pkr->id,
            'rate' => '0', 'rate_date' => '2026-10-01', 'rate_type' => 'SPOT', 'created_at' => now(), 'updated_at' => now()]);

        try {
            $migration->up();
            $this->fail('The migration must stop on a stored zero rate.');
        } catch (\RuntimeException $stop) {
            $this->assertStringContainsString('zero or negative', $stop->getMessage());
        }
        $this->assertSame('0.000000000', ExchangeRate::query()->value('rate'), 'evidence is reported, never rewritten');
    }
}
