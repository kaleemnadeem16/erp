<?php

namespace Tests\Feature\Support;

use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_currency_reference_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/currencies')->assertUnauthorized();
    }

    public function test_currency_reference_api_lists_searches_sorts_and_shows(): void
    {
        $pkr = Currency::create(['code' => 'PKR', 'name' => 'Pakistan Rupee', 'symbol' => 'Rs']);
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/currencies?filter[search]=rupee&sort=code')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'PKR');
        $this->getJson("/api/v1/currencies/{$pkr->id}")
            ->assertOk()->assertJsonPath('data.name', 'Pakistan Rupee');
        $this->getJson('/api/v1/currencies?sort=unknown')->assertUnprocessable();
    }
}
