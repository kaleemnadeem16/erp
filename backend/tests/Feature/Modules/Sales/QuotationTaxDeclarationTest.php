<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\Fr3QuotationTaxProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use App\Modules\Tax\Actions\CorrectQuotationTaxSource;
use App\Modules\Tax\Actions\DeclareQuotationTaxSource;
use App\Modules\Tax\Support\EffectivePeriod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Feature\Modules\Tax\Concerns\BuildsQuotationTax;
use Tests\TestCase;

/**
 * FR3 — the seller's tax declaration for a MANUAL line of a draft (append-only versions; a correction is the next version), the Tax-owned jurisdiction it is read with (corrected as a
 * new Tax version), and the breakdown the review shows. Everything Tax cannot establish stays UNKNOWN with a code, never zero.
 */
class QuotationTaxDeclarationTest extends TestCase
{
    use BuildsIssuableQuotations;
    use BuildsQuotationTax;
    use RefreshDatabase;

    private const TAX_DATE = '2026-10-27';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->setUpQuotationTax();
        $this->currency = (int) Currency::query()->where('code', 'PKR')->value('id');
        $this->activateDeclaration($this->declaration([$this->coverage('goods', '10.000000000')]));
        $this->app->forgetInstance(QuotationTaxProvider::class);
        $this->app->bind(QuotationTaxProvider::class, Fr3QuotationTaxProvider::class);
    }

    private function edit(int $revision, array $body): TestResponse
    {
        return $this->patchJson("/api/v1/quotations/{$revision}", $body + ['expected_generation' => QuotationRevision::findOrFail($revision)->input_generation], ['Idempotency-Key' => $this->issueKey()]);
    }

    /** @return array<string, mixed> */
    private function tax(int $revision): array
    {
        return $this->getJson("/api/v1/quotations/{$revision}/preview")->assertOk()->json('data.tax');
    }

    private function declare(int $line, string $nature, string $classification, string $reason = 'Seller states it'): array
    {
        return ['update_lines' => [['id' => $line, 'tax_declaration' => ['nature' => $nature, 'classification' => $classification, 'reason' => $reason]]]];
    }

    public function test_a_declaration_is_versioned_corrections_keep_the_earlier_version_and_tax_follows_the_latest(): void
    {
        $revision = $this->preparedDraft(taxDate: self::TAX_DATE);
        $lines = $revision->lines()->orderBy('id')->pluck('id')->all();
        $jurisdiction = app(DeclareQuotationTaxSource::class)->jurisdiction($this->actor, 'wrong-place', new EffectivePeriod('2026-01-01', '2027-01-01'), 'Mis-keyed', 'j', $this->generation());
        Sanctum::actingAs($this->maker);

        $this->assertContains('TAX_CLASSIFICATION_REQUIRED', $this->tax($revision->id)['reasons']);
        $this->edit($revision->id, ['update_lines' => array_map(fn (int $id): array => $this->declare($id, 'SERVICES', 'services')['update_lines'][0], $lines)])->assertOk();
        $this->assertSame(['TAX_CONTEXT_UNKNOWN'], $this->tax($revision->id)['reasons'], 'a place Tax has no declaration for is unknown, never zero');

        app(CorrectQuotationTaxSource::class)->jurisdiction($this->actor, $jurisdiction, 'illustrative', 'The place was mis-keyed', 'fix', $this->generation());
        $this->assertSame(['TAX_CONTEXT_UNKNOWN'], $this->tax($revision->id)['reasons'], 'an uncovered classification is still unknown');

        $this->edit($revision->id, ['update_lines' => array_map(fn (int $id): array => $this->declare($id, 'GOODS', 'goods', 'It is supplied paint, not labour')['update_lines'][0], $lines)])->assertOk();
        $tax = $this->tax($revision->id);
        $this->assertSame(['COMPLETE', '23.0000', '253.0000'], [$tax['certainty'], $tax['total'], $tax['total_including_tax']['amount']], 'optional lines are taxed but do not count in the total');
        $this->assertEqualsCanonicalizing(['20.0000', '3.0000'], array_column($tax['lines'], 'tax'));

        $history = $this->getJson("/api/v1/quotations/{$revision->id}/tax-declarations")->assertOk()->json('data');
        $this->assertSame([[1, 'services'], [1, 'services'], [2, 'goods'], [2, 'goods']], array_map(fn (array $r): array => [$r['version_no'], $r['classification']], $history));
        $this->assertSame($this->maker->id, $history[0]['declared_by']);

        // Restating what is in force adds no version; the editable read shows the latest.
        $before = DB::table('quotation_draft_tax_declarations')->count();
        $this->edit($revision->id, $this->declare($lines[0], 'GOODS', 'goods', 'Again'))->assertOk();
        $this->assertSame($before, DB::table('quotation_draft_tax_declarations')->count());
        $editable = $this->getJson("/api/v1/quotations/{$revision->id}/editable")->assertOk()->json('data.lines.0.tax_declaration');
        $this->assertSame([2, 'GOODS', 'goods'], [$editable['version_no'], $editable['nature'], $editable['classification']]);
    }

    public function test_the_evidence_is_append_only_draft_only_and_moves_the_input_generation(): void
    {
        $revision = $this->preparedDraft(taxDate: self::TAX_DATE);
        $line = (int) $revision->lines()->orderBy('id')->value('id');
        Sanctum::actingAs($this->maker);
        $generation = QuotationRevision::findOrFail($revision->id)->input_generation;
        $this->edit($revision->id, $this->declare($line, 'GOODS', 'goods'))->assertOk();
        $this->assertGreaterThan($generation, QuotationRevision::findOrFail($revision->id)->input_generation);

        foreach (['UPDATE quotation_draft_tax_declarations SET reason = \'x\'', 'DELETE FROM quotation_draft_tax_declarations', 'TRUNCATE quotation_draft_tax_declarations'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("{$sql} must be refused");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        DB::table('quotation_revisions')->where('id', $revision->id)->update(['state' => 'CANCELLED_DRAFT', 'cancelled_at' => now(), 'cancelled_by' => $this->maker->id, 'cancellation_reason' => 'x']);
        $this->expectException(QueryException::class);
        DB::table('quotation_draft_tax_declarations')->insert(['company_id' => 1, 'revision_id' => $revision->id, 'logical_scope_id' => $revision->lines()->value('logical_scope_id'), 'version_no' => 2,
            'nature' => 'GOODS', 'classification' => 'x', 'reason' => 'late', 'declared_by' => $this->maker->id]);
    }

    public function test_an_item_line_cannot_carry_a_seller_declaration_and_bad_input_is_refused(): void
    {
        $revision = $this->preparedDraft(taxDate: self::TAX_DATE);
        $line = (int) $revision->lines()->orderBy('id')->value('id');
        Sanctum::actingAs($this->maker);
        foreach ([['SERVICES', ''], ['BOTH', 'x'], ['GOODS', str_repeat('x', 81)]] as [$nature, $class]) {
            $this->edit($revision->id, $this->declare($line, $nature, $class))->assertUnprocessable();
        }
        $this->edit($revision->id, $this->declare($line, 'GOODS', 'goods', '  '))->assertUnprocessable();
        $this->edit($revision->id, ['update_lines' => [['id' => $line, 'tax_declaration' => null]]])->assertUnprocessable();
        $this->assertSame(0, DB::table('quotation_draft_tax_declarations')->count());
    }

    public function test_the_declaration_history_needs_prepare_with_view_and_is_not_found_across_companies(): void
    {
        $revision = $this->preparedDraft(taxDate: self::TAX_DATE);
        $this->getJson("/api/v1/quotations/{$revision->id}/tax-declarations")->assertUnauthorized();
        Sanctum::actingAs($this->checker);
        $this->getJson("/api/v1/quotations/{$revision->id}/tax-declarations")->assertForbidden();
        $outsider = User::factory()->create();
        $outsider->givePermissionTo([PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW]);
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/quotations/999999/tax-declarations')->assertNotFound();
        Sanctum::actingAs($this->maker);
        $this->getJson("/api/v1/quotations/{$revision->id}/tax-declarations")->assertOk()->assertJsonPath('data', []);
    }
}
