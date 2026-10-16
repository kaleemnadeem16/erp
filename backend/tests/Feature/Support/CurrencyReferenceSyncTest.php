<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\Currency;
use App\Support\Currency\IsoCurrencyDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** PX5 (ADR 0143): the ISO 4217 reference data is synchronised by an idempotent, dry-run-first command that never edits a used minor unit. */
class CurrencyReferenceSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $options */
    private function sync(array $options = []): int
    {
        return Artisan::call('erp:sync-currencies', $options);
    }

    /** @return array<string, string|int> */
    private function apply(array $extra = []): array
    {
        return ['--apply' => true, '--operator' => 'Test Operator', '--reason' => 'release test'] + $extra;
    }

    private function supportedCount(): int
    {
        return count(array_filter(IsoCurrencyDataset::load()->currencies, fn (array $row) => IsoCurrencyDataset::supports($row['minor_units'])));
    }

    public function test_the_dataset_is_the_iso_list_with_the_expected_minor_units(): void
    {
        $dataset = IsoCurrencyDataset::load();
        $units = array_column($dataset->currencies, 'minor_units', 'code');

        $this->assertSame('2026-09-17', $dataset->version);
        $this->assertCount(178, $dataset->currencies);
        $this->assertSame(0, $units['JPY']);
        $this->assertSame(2, $units['PKR']);
        $this->assertSame(2, $units['USD']);
        $this->assertSame(3, $units['KWD']);
        $this->assertSame(3, $units['BHD']);
        $this->assertSame(4, $units['CLF']);
        $this->assertNull($units['XAU'], 'a metal has no minor unit and is unsupported');
        $this->assertSame([], array_filter($dataset->currencies, fn (array $row) => $row['minor_units'] !== null && $row['minor_units'] > 4));
    }

    public function test_a_dry_run_reports_and_writes_nothing(): void
    {
        $this->assertSame(0, $this->sync());
        $output = Artisan::output();

        $this->assertSame(0, Currency::query()->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'currency-reference')->count());
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('would insert', $output);
    }

    public function test_applying_needs_a_named_operator_and_a_reason(): void
    {
        $this->assertSame(2, $this->sync(['--apply' => true]));
        $this->assertSame(2, $this->sync(['--apply' => true, '--operator' => 'Someone']));
        $this->assertSame(2, $this->sync(['--refresh-names' => true]));
        $this->assertSame(0, Currency::query()->count());
    }

    public function test_applying_inserts_the_supported_currencies_and_repeating_changes_nothing(): void
    {
        $this->assertSame(0, $this->sync($this->apply()));

        $this->assertSame($this->supportedCount(), Currency::query()->count());
        $this->assertSame(0, (int) Currency::query()->where('code', 'JPY')->value('decimal_places'));
        $this->assertSame(3, (int) Currency::query()->where('code', 'KWD')->value('decimal_places'));
        $this->assertSame('KWD', Currency::query()->where('code', 'KWD')->value('symbol'), 'ISO has no symbols, so a new row carries its code');
        $this->assertFalse(Currency::query()->where('code', 'XAU')->exists());

        $stamps = fn (): array => array_map(fn ($stamp) => (string) $stamp, Currency::query()->orderBy('id')->pluck('updated_at', 'code')->all());
        $before = $stamps();
        $this->assertSame(0, $this->sync($this->apply()));
        $this->assertSame($this->supportedCount(), Currency::query()->count());
        $this->assertSame($before, $stamps());
    }

    public function test_every_apply_leaves_an_audit_record_naming_the_operator(): void
    {
        $this->sync($this->apply());

        $record = Activity::query()->where('log_name', 'currency-reference')->sole();
        $this->assertSame('currency reference data synchronised', $record->description);
        $this->assertSame('Test Operator', $record->properties['operator']);
        $this->assertSame('release test', $record->properties['reason']);
        $this->assertSame('2026-09-17', $record->properties['dataset_version']);
        $this->assertSame($this->supportedCount(), $record->properties['inserted']);
    }

    public function test_a_differing_minor_unit_is_a_conflict_that_is_reported_and_not_applied(): void
    {
        Currency::create(['code' => 'JPY', 'name' => 'Yen', 'symbol' => '¥', 'decimal_places' => 2]);

        $this->assertSame(3, $this->sync(['--json' => true]));
        $plan = json_decode(Artisan::output(), true)['plan'];
        $this->assertSame('JPY', $plan['conflict'][0]['code']);
        $this->assertFalse($plan['conflict'][0]['used']);

        $this->assertSame(3, $this->sync($this->apply()), 'the conflict is signalled even though the inserts were applied');
        $this->assertSame(2, (int) Currency::query()->where('code', 'JPY')->value('decimal_places'));
        $this->assertSame($this->supportedCount(), Currency::query()->count());
    }

    public function test_an_unused_currency_can_be_corrected_only_on_request(): void
    {
        Currency::create(['code' => 'JPY', 'name' => 'Yen', 'symbol' => '¥', 'decimal_places' => 2]);

        $this->assertSame(0, $this->sync($this->apply(['--correct-unused' => true])));
        $this->assertSame(0, (int) Currency::query()->where('code', 'JPY')->value('decimal_places'));
        $this->assertSame(['JPY'], Activity::query()->where('log_name', 'currency-reference')->sole()->properties['corrected']);
    }

    public function test_a_used_currency_is_never_changed_whatever_the_options(): void
    {
        $jpy = Currency::create(['code' => 'JPY', 'name' => 'Yen', 'symbol' => '¥', 'decimal_places' => 2]);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $jpy->id]);

        $this->assertSame(3, $this->sync($this->apply(['--correct-unused' => true, '--refresh-names' => true])));

        $this->assertSame(2, (int) $jpy->refresh()->decimal_places);
        $this->assertSame('JPY', Activity::query()->where('log_name', 'currency-reference')->sole()->properties['conflicts'][0]);
        $this->sync(['--json' => true]);
        $conflict = json_decode(Artisan::output(), true)['plan']['conflict'][0];
        $this->assertTrue($conflict['used']);
        $this->assertContains('companies.base_currency_id', $conflict['used_by']);
    }

    public function test_use_by_another_company_or_a_rate_also_protects_a_currency(): void
    {
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 3]);
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2]);
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('exchange_rates')->insert(['company_id' => $otherCompany, 'from_currency_id' => $usd->id, 'to_currency_id' => $eur->id,
            'rate' => '0.920000000', 'rate_date' => '2026-10-01', 'rate_type' => 'SPOT', 'created_at' => now(), 'updated_at' => now()]);

        $this->sync($this->apply(['--correct-unused' => true]));

        $this->assertSame(3, (int) $usd->refresh()->decimal_places, 'a rate in another company still marks the currency used');
    }

    public function test_a_differing_name_is_information_and_is_refreshed_only_on_request(): void
    {
        Currency::create(['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);

        $this->assertSame(0, $this->sync($this->apply()));
        $this->assertSame('Pakistani Rupee', Currency::query()->where('code', 'PKR')->value('name'));

        $this->assertSame(0, $this->sync($this->apply(['--refresh-names' => true])));
        $this->assertSame('Pakistan Rupee', Currency::query()->where('code', 'PKR')->value('name'));
        $this->assertSame('Rs', Currency::query()->where('code', 'PKR')->value('symbol'), 'a chosen symbol is never overwritten');
    }

    public function test_a_currency_the_dataset_does_not_know_is_left_alone_and_reported(): void
    {
        Currency::create(['code' => 'ZZZ', 'name' => 'Local token', 'symbol' => 'z', 'decimal_places' => 2]);

        $this->sync($this->apply());
        $this->sync(['--json' => true]);

        $this->assertTrue(Currency::query()->where('code', 'ZZZ')->exists());
        $this->assertContains('ZZZ', json_decode(Artisan::output(), true)['plan']['local_only']);
    }

    public function test_a_malformed_dataset_stops_before_anything_is_written(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'iso');
        file_put_contents($path, json_encode(['dataset_version' => 'x', 'currencies' => [
            ['code' => 'AAA', 'name' => 'A', 'minor_units' => 2], ['code' => 'AAA', 'name' => 'A again', 'minor_units' => 2],
        ]]));

        $this->assertSame(1, $this->sync($this->apply(['--dataset' => $path])));
        $this->assertStringContainsString('more than once', Artisan::output());
        $this->assertSame(0, Currency::query()->count());

        file_put_contents($path, json_encode(['dataset_version' => 'x', 'currencies' => [['code' => 'AAA', 'name' => 'A', 'minor_units' => 7]]]));
        $this->sync(['--dataset' => $path, '--json' => true]);
        $this->assertSame('AAA', json_decode(Artisan::output(), true)['plan']['unsupported'][0]['code'], 'five or more places cannot be settled and is reported, not stored');
        unlink($path);
    }
}
