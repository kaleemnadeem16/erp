<?php

namespace Tests\Feature\Modules\Numbering;

use App\Models\User;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Numbering\Actions\CreateSeries;
use App\Modules\Numbering\Actions\CreateSeriesRule;
use App\Modules\Numbering\Actions\EnsureDefaultSeries;
use App\Modules\Numbering\Actions\RaiseCounter;
use App\Modules\Numbering\Actions\SaveConstant;
use App\Modules\Numbering\Actions\SaveSeriesVersion;
use App\Modules\Numbering\Models\NumberingAllocation;
use App\Modules\Numbering\Models\NumberingCounter;
use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Models\NumberingSeriesVersion;
use App\Modules\Numbering\Services\NumberingPreview;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Numbering\Support\ScopePeriod;
use App\Modules\Numbering\Support\TemplateParser;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FakeNumberableDocument;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/** The framework against an invented document type: nothing here knows about purchasing, branches or customers (ADR 0079). */
class NumberingEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->register(new FakeNumberableDocument);
        $this->manager = PurchasingActor::make(['sales.numbering.manage', 'numbering.constant.manage', 'numbering.counter.raise']);
    }

    private function register(FakeNumberableDocument $type): void
    {
        app(NumberingRegistry::class)->register($type);
    }

    /** The company time zone is the existing accounting_timezone setting (default UTC). */
    private function timezone(string $zone = 'Asia/Karachi'): void
    {
        DB::table('companies')->where('id', 1)->update(['accounting_timezone' => $zone]);
    }

    private function allocate(string $at, string $branch = 'LHR', array $routing = [], ?string $series = null): string
    {
        return app(NumberingService::class)->allocate(FakeNumberableDocument::context($at, $branch, $routing, $series, $this->manager->id))->number;
    }

    private function series(?string $code = 'DEFAULT'): NumberingSeries
    {
        app(EnsureDefaultSeries::class)(app(NumberingRegistry::class)->get('SALES_INVOICE'));

        return NumberingSeries::query()->where('doc_type', 'SALES_INVOICE')->where('code', $code)->firstOrFail();
    }

    private function version(string $template, ScopePeriod $period = ScopePeriod::None, array $dimensions = [], Continuity $continuity = Continuity::AllowGaps, string $code = 'DEFAULT'): NumberingSeriesVersion
    {
        return app(SaveSeriesVersion::class)($this->series($code), $template, CounterScope::of($period, $dimensions), $continuity, 'test', $this->manager);
    }

    // ---- the template language -------------------------------------------------------------------------------------

    public function test_templates_are_parsed_never_evaluated(): void
    {
        $parser = new TemplateParser;
        $parsed = $parser->parse('INV-{BRANCH}-{YYYY}/{SEQ:6}');
        $this->assertSame(6, $parsed->seqWidth());
        $this->assertSame(['BRANCH', 'YYYY'], array_column($parsed->variables(), 'key'));

        foreach (['', 'NO-COUNTER', '{SEQ:4}{SEQ:4}', '{SEQ}', '{SEQ:0}', '{SEQ:13}', '{unknown}', 'A B{SEQ:4}', 'X{', 'X}{SEQ:4}', '{SEQ:4}{BR ANCH}', str_repeat('A', 31).'{SEQ:4}'] as $bad) {
            try {
                $parser->parse($bad);
                $this->fail("'{$bad}' should be rejected");
            } catch (NumberingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_variable_the_document_does_not_supply_is_rejected_with_the_available_ones_listed(): void
    {
        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('{WAREHOUSE} is not available for Sales invoice numbers');
        $this->version('{WAREHOUSE}-{SEQ:4}');
    }

    // ---- allocation, the log and freezing -----------------------------------------------------------------------------

    public function test_a_fresh_document_type_gets_its_default_series_and_sequential_numbers(): void
    {
        $this->assertSame('0001', $this->allocate('2026-10-02 10:00:00'));
        $this->assertSame('0002', $this->allocate('2026-10-02 10:05:00'));
        $this->assertSame(1, NumberingSeries::query()->where('doc_type', 'SALES_INVOICE')->count());
    }

    public function test_the_allocation_log_freezes_the_number_and_everything_behind_it(): void
    {
        $this->timezone();
        $this->version('INV-{BRANCH}-{CUSTOMER_CODE}-{YYYY}-{SEQ:5}', ScopePeriod::Year);

        $number = $this->allocate('2026-10-02 10:00:00', 'KHI');

        $this->assertSame('INV-KHI-CUS001-2026-00001', $number);
        $log = NumberingAllocation::query()->where('number', $number)->firstOrFail();
        $this->assertSame('SALES_INVOICE', $log->doc_type);
        $this->assertSame(500, $log->document_id);
        $this->assertSame('Y2026', $log->counter_key);
        $this->assertSame(1, $log->counter_value);
        $this->assertSame('2026-10-02', $log->local_date->toDateString());
        $this->assertSame(['value' => 'KHI', 'source' => 'branches#2'], $log->variables['BRANCH']);
        $this->assertSame(['value' => 'CUS001', 'source' => 'customers#7'], $log->variables['CUSTOMER_CODE']);
        $this->assertSame(['value' => '2026', 'source' => null], $log->variables['YYYY']);
        $this->assertSame($this->series()->activeVersion->id, $log->version_id);
        $this->assertSame($this->manager->id, $log->allocated_by);
    }

    public function test_an_issued_number_is_history(): void
    {
        $this->allocate('2026-10-02 10:00:00');
        $log = NumberingAllocation::query()->firstOrFail();

        $this->expectException(RuntimeException::class);
        $log->update(['number' => 'CHANGED']);
    }

    public function test_the_same_number_can_never_be_issued_twice_for_a_type(): void
    {
        $this->allocate('2026-10-02 10:00:00');
        // Force a second counter to reproduce 0001: the unique boundary is the backstop even if configuration slipped through.
        DB::table('numbering_counters')->update(['allocator' => 'LOCKED', 'next_number' => 1, 'sequence_name' => null]);

        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('already been issued');
        $this->allocate('2026-10-02 10:10:00');
    }

    // ---- date, time zone, scope ----------------------------------------------------------------------------------

    public function test_date_parts_use_the_issue_instant_in_the_company_time_zone_not_utc(): void
    {
        $this->timezone('Asia/Karachi'); // UTC+5
        $this->version('{YYYY}{MM}-{SEQ:3}', ScopePeriod::Month);

        // 19:30 UTC on 31 October is 00:30 on 1 November in Karachi: a new month, a new counter.
        $this->assertSame('202510-001', $this->allocate('2025-10-31 18:00:00'));
        $this->assertSame('202511-001', $this->allocate('2025-10-31 19:30:00'));
        $this->assertSame('202511-002', $this->allocate('2025-11-15 08:00:00'));
    }

    public function test_the_counter_restarts_each_year_by_the_issue_date(): void
    {
        $this->timezone();
        $this->version('PO-{YYYY}-{SEQ:5}', ScopePeriod::Year);

        $this->assertSame('PO-2026-00001', $this->allocate('2026-12-31 10:00:00'));
        $this->assertSame('PO-2026-00002', $this->allocate('2026-12-31 18:00:00'));
        $this->assertSame('PO-2027-00001', $this->allocate('2026-12-31 20:00:00')); // 01:00 on 1 Jan in Karachi
    }

    public function test_a_date_based_format_warns_while_the_company_is_still_on_the_utc_default_and_a_dateless_one_does_not(): void
    {
        $preview = fn (string $template, ScopePeriod $period = ScopePeriod::None) => app(NumberingPreview::class)->preview('SALES_INVOICE', $template, CounterScope::of($period), Continuity::AllowGaps, 'DEFAULT');

        $dated = $preview('{YYYY}-{SEQ:4}', ScopePeriod::Year);
        $this->assertSame([], $dated['errors']);
        $this->assertStringContainsString('company time zone is UTC, the default', $dated['warnings'][0]);
        $this->assertSame([], $preview('{SEQ:4}')['warnings'], 'a format without a date part needs no time zone');

        $this->timezone('Asia/Karachi');
        $this->assertSame([], $preview('{YYYY}-{SEQ:4}', ScopePeriod::Year)['warnings']);
    }

    public function test_a_variable_can_be_decorative_without_creating_another_counter(): void
    {
        $this->timezone();
        $this->version('PO-{BRANCH}-{YYYY}-{SEQ:5}', ScopePeriod::Year); // scope: Company + Year; BRANCH is only shown

        $this->assertSame('PO-LHR-2026-00001', $this->allocate('2026-10-02 10:00:00', 'LHR'));
        $this->assertSame('PO-KHI-2026-00002', $this->allocate('2026-10-02 10:01:00', 'KHI'));
        $this->assertSame('PO-LHR-2026-00003', $this->allocate('2026-10-02 10:02:00', 'LHR'));
    }

    public function test_a_dimension_gives_each_value_its_own_counter(): void
    {
        $this->timezone();
        $this->version('PO-{BRANCH}-{YYYY}-{SEQ:5}', ScopePeriod::Year, ['BRANCH']);

        $this->assertSame('PO-LHR-2026-00001', $this->allocate('2026-10-02 10:00:00', 'LHR'));
        $this->assertSame('PO-KHI-2026-00001', $this->allocate('2026-10-02 10:01:00', 'KHI'));
        $this->assertSame('PO-LHR-2026-00002', $this->allocate('2026-10-02 10:02:00', 'LHR'));
    }

    public function test_uniqueness_is_validated_not_assumed_two_independent_counters_that_can_issue_the_same_number_are_rejected(): void
    {
        $this->timezone();

        try {
            // Lahore and Karachi count separately, but nothing in the text tells them apart: both reach PO-2026-00001.
            $this->version('PO-{YYYY}-{SEQ:5}', ScopePeriod::Year, ['BRANCH']);
            $this->fail('expected a collision');
        } catch (NumberingException $e) {
            $this->assertStringContainsString('both produce PO-2026-00001', $e->getMessage());
        }

        // The same scope is fine once any variable keeps the two apart — here a branch that is in the text.
        $this->assertSame('PO-{BRANCH}-{YYYY}-{SEQ:5}', $this->version('PO-{BRANCH}-{YYYY}-{SEQ:5}', ScopePeriod::Year, ['BRANCH'])->template);
    }

    public function test_a_yearly_counter_whose_text_never_names_the_year_is_rejected(): void
    {
        $this->timezone();

        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('both produce');
        $this->version('PO-{SEQ:5}', ScopePeriod::Year);
    }

    public function test_a_format_may_show_more_than_the_scope_counts(): void
    {
        $this->timezone();
        $this->version('{YYYY}{MM}-{SEQ:4}', ScopePeriod::Year); // the month is in the text; the counter runs all year

        $this->assertSame('202610-0001', $this->allocate('2026-10-02 10:00:00'));
        $this->assertSame('202611-0002', $this->allocate('2026-11-02 10:00:00'));
    }

    public function test_fiscal_variables_come_from_the_fiscal_calendar_and_are_refused_when_none_covers_the_date(): void
    {
        $this->timezone();

        // Nothing can be saved against a calendar that does not exist yet: the message says what to set up.
        try {
            $this->version('{FY}-{FP}-{SEQ:3}', ScopePeriod::FiscalYear);
            $this->fail('expected a refusal');
        } catch (NumberingException $e) {
            $this->assertStringContainsString('No fiscal year covers', $e->getMessage());
        }

        $year = FiscalYear::query()->create(['name' => 'FY2526', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'status' => 'OPEN']);
        AccountingPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_no' => 4, 'name' => 'Oct', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'status' => 'OPEN']);
        $this->version('{FY}-{FP}-{SEQ:3}', ScopePeriod::FiscalYear);

        $this->assertSame('2026-27-04-001', $this->allocate('2026-10-02 10:00:00'));
        $this->assertSame('2026-27-04-002', $this->allocate('2026-10-03 10:00:00'));

        // A date the calendar does not cover is refused rather than numbered from the calendar year.
        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('No fiscal year covers');
        $this->allocate('2028-01-15 10:00:00');
    }

    // ---- versions --------------------------------------------------------------------------------------------------

    public function test_a_change_adds_a_version_and_issued_numbers_stay_tied_to_the_one_that_made_them(): void
    {
        $this->timezone();
        $v1 = $this->version('PO-{YYYY}-{SEQ:5}', ScopePeriod::Year);
        $this->assertSame('PO-2026-00001', $this->allocate('2026-10-02 10:00:00'));

        $v2 = $this->version('PO-{BRANCH}-{YYYY}-{SEQ:5}', ScopePeriod::Year, ['BRANCH']);
        $this->assertNotSame($v1->id, $v2->id);
        $this->assertSame('PO-LHR-2026-00001', $this->allocate('2026-10-02 10:05:00', 'LHR')); // new scope, new counters

        $this->assertSame($v1->id, NumberingAllocation::query()->where('number', 'PO-2026-00001')->value('version_id'));
        $this->assertSame($v2->id, NumberingAllocation::query()->where('number', 'PO-LHR-2026-00001')->value('version_id'));
        $this->assertSame('SUPERSEDED', $v1->fresh()->status);
        // the series' first version came from the document type's defaults; v1 and v2 here are the next two
        $this->assertSame(3, NumberingSeriesVersion::query()->where('series_id', $v1->series_id)->count());
    }

    public function test_saving_an_unchanged_format_writes_nothing_and_a_reason_is_required(): void
    {
        $v1 = $this->version('{SEQ:5}');
        $again = $this->version('{SEQ:5}');
        $this->assertSame($v1->id, $again->id);
        $this->assertSame(2, NumberingSeriesVersion::query()->where('series_id', $v1->series_id)->count(), 'the default version plus one; the unchanged save added nothing');

        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('Say why');
        app(SaveSeriesVersion::class)($this->series(), '{SEQ:6}', CounterScope::company(), Continuity::AllowGaps, ' ', $this->manager);
    }

    public function test_only_someone_who_may_manage_the_type_can_change_it(): void
    {
        $nobody = PurchasingActor::make(['numbering.view']);

        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('not permitted');
        app(SaveSeriesVersion::class)($this->series(), '{SEQ:6}', CounterScope::company(), Continuity::AllowGaps, 'why', $nobody);
    }

    public function test_a_version_cannot_be_edited_or_deleted(): void
    {
        $v1 = $this->version('{SEQ:5}');
        try {
            $v1->update(['template' => '{SEQ:9}']);
            $this->fail('edit allowed');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(RuntimeException::class);
        $v1->delete();
    }

    // ---- company constants ---------------------------------------------------------------------------------------------

    public function test_company_constants_are_usable_in_a_format_and_frozen_in_the_log(): void
    {
        app(SaveConstant::class)('REGION', 'NORTH', 'launch', $this->manager);
        $this->version('{REGION}-{SEQ:4}');

        $this->assertSame('NORTH-0001', $this->allocate('2026-10-02 10:00:00'));
        $this->assertSame(['value' => 'NORTH', 'source' => 'constant:REGION'], NumberingAllocation::query()->firstOrFail()->variables['REGION']);

        // A later change affects only later numbers and the old one stays as issued.
        app(SaveConstant::class)('REGION', 'SOUTH', 'restructure', $this->manager);
        $this->assertSame('SOUTH-0002', $this->allocate('2026-10-02 11:00:00'));
        $this->assertSame('NORTH-0001', NumberingAllocation::query()->orderBy('id')->value('number'));
    }

    public function test_constants_are_validated_reserved_and_cannot_be_retired_while_in_use(): void
    {
        foreach ([['year', 'X'], ['YYYY', 'X'], ['SEQ', 'X'], ['BRANCH', 'X'], ['REGION', 'has space'], ['REGION', str_repeat('A', 21)]] as [$code, $value]) {
            try {
                app(SaveConstant::class)($code, $value, 'r', $this->manager);
                $this->fail("{$code}={$value} accepted");
            } catch (NumberingException) {
                $this->addToAssertionCount(1);
            }
        }

        app(SaveConstant::class)('BRAND', 'ABC', 'r', $this->manager);
        $this->version('{BRAND}-{SEQ:4}');
        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('used by a current number format');
        app(SaveConstant::class)('BRAND', null, 'retire', $this->manager);
    }

    // ---- continuity ---------------------------------------------------------------------------------------------------

    public function test_gapless_returns_the_number_when_the_document_fails_and_allow_gaps_does_not(): void
    {
        $this->version('{SEQ:4}', continuity: Continuity::Gapless);
        $this->assertSame('0001', $this->allocate('2026-10-02 10:00:00'));
        try {
            DB::transaction(function () {
                $this->assertSame('0002', $this->allocate('2026-10-02 10:01:00'));
                throw new RuntimeException('the document failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame('0002', $this->allocate('2026-10-02 10:02:00'), 'gapless: the failed 0002 was returned');

        $this->version('{SEQ:4}', continuity: Continuity::AllowGaps); // converts the counter, keeping its place
        $this->assertSame('0003', $this->allocate('2026-10-02 10:03:00'));
        try {
            DB::transaction(function () {
                $this->assertSame('0004', $this->allocate('2026-10-02 10:04:00'));
                throw new RuntimeException('the document failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame('0005', $this->allocate('2026-10-02 10:05:00'), 'allow gaps: 0004 was lost');
    }

    public function test_switching_continuity_keeps_the_counter_s_place_in_both_directions(): void
    {
        $this->version('{SEQ:4}', continuity: Continuity::Gapless);
        $this->allocate('2026-10-02 10:00:00');
        $this->allocate('2026-10-02 10:01:00');

        $this->version('{SEQ:4}', continuity: Continuity::AllowGaps);
        $this->assertSame('0003', $this->allocate('2026-10-02 10:02:00'));
        $this->assertSame('SEQUENCE', NumberingCounter::query()->firstOrFail()->allocator);

        $this->version('{SEQ:4}', continuity: Continuity::Gapless);
        $this->assertSame('0004', $this->allocate('2026-10-02 10:03:00'));
        $counter = NumberingCounter::query()->firstOrFail();
        $this->assertSame('LOCKED', $counter->allocator);
        $this->assertNull($counter->sequence_name);
    }

    public function test_a_statutory_type_keeps_its_policy(): void
    {
        $this->register(new FakeNumberableDocument(Continuity::Gapless, locked: true));

        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('must stay GAPLESS');
        $this->version('{SEQ:4}', continuity: Continuity::AllowGaps);
    }

    // ---- series and routing -------------------------------------------------------------------------------------------

    public function test_a_document_type_supports_several_series_each_with_its_own_counter(): void
    {
        $this->timezone();
        $this->version('INV-{SEQ:5}');
        app(CreateSeries::class)('SALES_INVOICE', 'EXPORT', 'Export', 'EXP-{BRANCH}-{YYYY}-{SEQ:6}', CounterScope::of(ScopePeriod::Year, ['BRANCH']), Continuity::Gapless, true, 'launch', $this->manager);

        $this->assertSame('INV-00001', $this->allocate('2026-10-02 10:00:00'));
        $this->assertSame('EXP-LHR-2026-000001', $this->allocate('2026-10-02 10:01:00', 'LHR', [], 'EXPORT'));
        $this->assertSame('INV-00002', $this->allocate('2026-10-02 10:02:00'));
    }

    public function test_a_series_can_only_be_chosen_by_hand_when_it_is_selectable(): void
    {
        app(CreateSeries::class)('SALES_INVOICE', 'HIDDEN', 'Hidden', 'H-{SEQ:4}', CounterScope::company(), Continuity::AllowGaps, false, 'r', $this->manager);

        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('cannot be chosen by hand');
        $this->allocate('2026-10-02 10:00:00', 'LHR', [], 'HIDDEN');
    }

    public function test_routing_rules_pick_the_series_by_priority_and_fall_back_to_the_default(): void
    {
        $this->version('INV-{SEQ:4}');
        $export = app(CreateSeries::class)('SALES_INVOICE', 'EXPORT', 'Export', 'EXP-{SEQ:4}', CounterScope::company(), Continuity::AllowGaps, false, 'r', $this->manager);
        $karachi = app(CreateSeries::class)('SALES_INVOICE', 'KARACHI', 'Karachi', 'KHI-{SEQ:4}', CounterScope::company(), Continuity::AllowGaps, false, 'r', $this->manager);
        app(CreateSeriesRule::class)($export, 10, [['attribute' => 'SALE_TYPE', 'operator' => 'EQ', 'values' => ['EXPORT']]], $this->manager);
        app(CreateSeriesRule::class)($karachi, 20, [['attribute' => 'BRANCH', 'operator' => 'IN', 'values' => ['KHI', 'ISB']]], $this->manager);

        $this->assertSame('EXP-0001', $this->allocate('2026-10-02 10:00:00', 'KHI', ['SALE_TYPE' => 'EXPORT'])); // both match; export is first by priority
        $this->assertSame('KHI-0001', $this->allocate('2026-10-02 10:01:00', 'KHI'));
        $this->assertSame('INV-0001', $this->allocate('2026-10-02 10:02:00', 'LHR'));
    }

    public function test_a_rule_may_only_test_attributes_the_document_type_exposes(): void
    {
        $series = $this->series();

        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('not something Sales invoice documents can be routed by');
        app(CreateSeriesRule::class)($series, 1, [['attribute' => 'COLOUR', 'operator' => 'EQ', 'values' => ['RED']]], $this->manager);
    }

    public function test_two_series_that_could_issue_the_same_number_are_rejected(): void
    {
        $this->version('INV-{SEQ:4}');

        $this->expectException(NumberingException::class);
        $this->expectExceptionMessage('both produce INV-0001');
        app(CreateSeries::class)('SALES_INVOICE', 'COPY', 'Copy', 'INV-{SEQ:4}', CounterScope::company(), Continuity::AllowGaps, true, 'r', $this->manager);
    }

    // ---- counters ----------------------------------------------------------------------------------------------------

    /** @return array<string, array{Continuity}> */
    public static function policies(): array
    {
        return ['gapless' => [Continuity::Gapless], 'allow gaps' => [Continuity::AllowGaps]];
    }

    #[DataProvider('policies')]
    public function test_a_counter_can_be_raised_with_a_reason_and_never_lowered(Continuity $continuity): void
    {
        $this->version('{SEQ:4}', continuity: $continuity);
        $this->allocate('2026-10-02 10:00:00');
        $counter = NumberingCounter::query()->firstOrFail();

        $adjustment = app(RaiseCounter::class)($counter, 500, 'legacy numbers go up to 499', $this->manager);
        $this->assertSame(500, $adjustment->to_value);
        $this->assertSame(2, $adjustment->from_value);
        $this->assertSame('0500', $this->allocate('2026-10-02 10:01:00'));

        try {
            app(RaiseCounter::class)($counter->fresh(), 10, 'oops', $this->manager);
            $this->fail('lowering allowed');
        } catch (NumberingException $e) {
            $this->assertStringContainsString('can only be raised', $e->getMessage());
        }
        $this->assertSame('0501', $this->allocate('2026-10-02 10:02:00'));
    }

    public function test_the_database_also_refuses_a_lowered_adjustment(): void
    {
        $this->allocate('2026-10-02 10:00:00');
        $counter = NumberingCounter::query()->firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('numbering_counter_adjustments')->insert(['company_id' => 1, 'counter_id' => $counter->id, 'from_value' => 10, 'to_value' => 5, 'reason' => 'x', 'changed_by' => $this->manager->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- the tester never consumes a number ----------------------------------------------------------------------------

    public function test_the_preview_explains_the_configuration_and_never_takes_a_number(): void
    {
        $this->timezone();
        $this->version('PO-{YYYY}-{MM}-{SEQ:5}', ScopePeriod::Month);
        $this->assertSame('PO-2026-10-00001', $this->allocate('2026-10-02 10:00:00'));
        $before = DB::table('numbering_counters')->get();

        $preview = app(NumberingPreview::class)->preview('SALES_INVOICE', 'PO-{YYYY}-{MM}-{SEQ:5}', CounterScope::of(ScopePeriod::Month), Continuity::AllowGaps, 'DEFAULT', CarbonImmutable::parse('2026-10-02 10:30:00'));

        $this->assertSame([], $preview['errors']);
        $this->assertSame('Asia/Karachi', $preview['timezone']);
        $this->assertSame('2026-10-02', $preview['local_date']);
        $this->assertStringContainsString('not its order or document date', $preview['date_basis']);
        $this->assertSame('Company + Month', $preview['counter_scope']);
        $this->assertStringContainsString('each calendar month', $preview['reset']);
        $this->assertSame('PO-2026-10-00002', $preview['example']);
        $this->assertSame(['PO-2026-10-00002', 'PO-2026-10-00003', 'PO-2026-10-00004'], $preview['next_numbers']);
        $this->assertSame('PO-2026-11-00001', $preview['first_of_next_period']);
        $this->assertSame('No change from the current format.', $preview['change_impact']);

        $this->assertEquals($before, DB::table('numbering_counters')->get(), 'previewing changed a counter');
        $this->assertSame(1, NumberingAllocation::query()->count());
    }

    public function test_the_preview_reads_a_sequence_backed_counter_without_advancing_it(): void
    {
        $this->version('{SEQ:4}', continuity: Continuity::AllowGaps);
        $this->allocate('2026-10-02 10:00:00');

        for ($i = 0; $i < 3; $i++) {
            $preview = app(NumberingPreview::class)->preview('SALES_INVOICE', '{SEQ:4}', CounterScope::company(), Continuity::AllowGaps, 'DEFAULT');
            $this->assertSame('0002', $preview['example']);
        }
        $this->assertSame('0002', $this->allocate('2026-10-02 10:05:00'));
    }

    public function test_the_preview_reports_format_problems_and_the_effect_of_a_change(): void
    {
        $this->timezone();
        $this->version('INV-{SEQ:4}');

        $bad = app(NumberingPreview::class)->preview('SALES_INVOICE', 'INV-{NOPE}-{SEQ:4}', CounterScope::company(), Continuity::AllowGaps, 'DEFAULT');
        $this->assertNotSame([], $bad['errors']);
        $this->assertNull($bad['example']);

        $changed = app(NumberingPreview::class)->preview('SALES_INVOICE', 'INV-{BRANCH}-{YYYY}-{SEQ:4}', CounterScope::of(ScopePeriod::Year, ['BRANCH']), Continuity::Gapless, 'DEFAULT');
        $this->assertStringContainsString('ormat changes from INV-{SEQ:4}', $changed['change_impact']);
        $this->assertStringContainsString('new counters start', $changed['change_impact']);
        $this->assertStringContainsString('ALLOW_GAPS to GAPLESS', $changed['change_impact']);
        $this->assertStringContainsString('numbers already issued do not change', $changed['change_impact']);
    }

    public function test_an_unregistered_document_type_is_refused(): void
    {
        $this->expectException(NumberingException::class);
        app(NumberingService::class)->allocate(new NumberingContext('NOPE', CarbonImmutable::now()));
    }
}
