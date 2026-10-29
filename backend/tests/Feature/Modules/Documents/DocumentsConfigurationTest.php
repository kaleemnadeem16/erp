<?php

namespace Tests\Feature\Modules\Documents;

use App\Models\User;
use App\Modules\Documents\Actions\ActivateDocumentConfiguration;
use App\Modules\Documents\Actions\ResolveDocumentPolicy;
use App\Modules\Documents\Actions\VersionDocumentPagePolicy;
use App\Modules\Documents\Actions\VersionDocumentTemplate;
use App\Modules\Documents\Models\DocumentCompanyDefault;
use App\Modules\Documents\Models\DocumentDefaultActivation;
use App\Modules\Documents\Models\DocumentPagePolicyVersion;
use App\Modules\Documents\Models\DocumentTemplateVersion;
use App\Modules\Documents\Support\DocumentConfigurationUsage;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentLayouts;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\PageBandMode;
use App\Modules\Documents\Support\PageFormat;
use App\Modules\Documents\Support\PageOrientation;
use App\Modules\Documents\Support\PagePolicyConfiguration;
use App\Modules\Documents\Support\ResolvedDocumentPolicy;
use App\Modules\Documents\Support\TemplateConfiguration;
use App\Support\PermissionRegistry;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Documents\PurchaseOrderShapedAdapter;
use Tests\Support\Documents\QuotationShapedA4Adapter;
use Tests\TestCase;

/**
 * DF1a — Documents-owned template and page-policy configuration over the real database: curated typed versions that are immutable, generation-locked
 * activation with safe downgrades, A4/Letter by type constraint, and resolution that never invents a brand (ADR 0137, documents.md configuration workflow).
 */
class DocumentsConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const PO = PurchaseOrderShapedAdapter::KEY;

    private const QUOTE = QuotationShapedA4Adapter::KEY;

    private User $user;

    private int $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $registry = new DocumentTypeRegistry;
        $registry->register(new PurchaseOrderShapedAdapter(supportsLetter: true));
        $registry->register(new QuotationShapedA4Adapter);
        $this->app->instance(DocumentTypeRegistry::class, $registry);
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function template(string $type = self::PO, string $key = 'standard', ?TemplateConfiguration $config = null): DocumentTemplateVersion
    {
        $config ??= $type === self::PO
            ? new TemplateConfiguration(DocumentLayouts::STANDARD, ['lines', 'terms'], ['lines'])
            : new TemplateConfiguration(DocumentLayouts::STANDARD, ['lines', 'terms'], ['lines', 'terms']);

        return app(VersionDocumentTemplate::class)->execute($type, $key, $config, $this->user->id);
    }

    private function pageConfig(PageFormat $format = PageFormat::A4, array $layouts = [DocumentLayouts::STANDARD, DocumentLayouts::COMPACT], string $margin = '20'): PagePolicyConfiguration
    {
        return new PagePolicyConfiguration($format, PageOrientation::Portrait, $margin, $margin, $margin, $margin, PageBandMode::Full, PageBandMode::Compact, PageBandMode::PageNumberOfTotal, PageBandMode::PageNumber, $layouts);
    }

    private function page(string $type = '*', string $key = 'plain', ?PagePolicyConfiguration $config = null): DocumentPagePolicyVersion
    {
        return app(VersionDocumentPagePolicy::class)->execute($type, $key, $config ?? $this->pageConfig(), $this->user->id);
    }

    private function activate(string $type, ?int $template, ?int $page, int $expected, ?string $reason = null): DocumentCompanyDefault
    {
        return app(ActivateDocumentConfiguration::class)->execute($type, $template, $page, $expected, $this->user->id, $reason);
    }

    private function resolve(string $type, ?int $template = null, ?int $page = null): ResolvedDocumentPolicy
    {
        return app(ResolveDocumentPolicy::class)->execute($type, $template, $page);
    }

    private function refusal(Closure $call): string
    {
        try {
            $call();
        } catch (DocumentException $e) {
            return (string) $e->reasonCode;
        }
        $this->fail('Expected a DocumentException.');
    }

    private function assertDbRefuses(Closure $write, string $contains): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());

            return;
        }
        $this->fail("The database accepted a write that should have been refused ({$contains}).");
    }

    // ---- versions

    public function test_versions_are_numbered_per_template_line_and_carry_only_typed_content(): void
    {
        $this->assertSame(1, $this->template()->version);
        $this->assertSame(2, $this->template()->version);
        $this->assertSame(1, $this->template(key: 'other')->version);
        $this->assertSame(1, $this->page()->version);
        $this->assertSame(2, $this->page()->version);
        $v = DocumentTemplateVersion::query()->where('template_key', 'standard')->orderByDesc('version')->firstOrFail();
        $this->assertSame(DocumentLayouts::STANDARD, $v->layout_id);
        $this->assertSame(['lines'], $v->mandatory_sections);
    }

    public function test_templates_are_curated_and_must_fit_the_document_type(): void
    {
        $this->assertSame('layout_unknown', $this->refusal(fn () => new TemplateConfiguration('custom.css', ['lines'], ['lines'])));
        $this->assertSame('sections_invalid', $this->refusal(fn () => new TemplateConfiguration(DocumentLayouts::STANDARD, ['lines'], ['terms'])));
        $this->assertSame('mandatory_content_missing', $this->refusal(fn () => $this->template(self::QUOTE, config: new TemplateConfiguration(DocumentLayouts::STANDARD, ['lines', 'terms'], ['lines']))));
        $this->assertSame('section_not_allowed', $this->refusal(fn () => $this->template(config: new TemplateConfiguration(DocumentLayouts::STANDARD, ['lines', 'banner'], ['lines']))));
        $this->assertSame('layout_not_allowed', $this->refusal(fn () => $this->template(self::QUOTE, config: new TemplateConfiguration(DocumentLayouts::COMPACT, ['lines', 'terms'], ['lines', 'terms']))));
        $this->assertSame('adapter_missing', $this->refusal(fn () => $this->template('nobody.knows.this')));
        $this->assertSame('key_invalid', $this->refusal(fn () => $this->template(key: 'Bad Key')));
        $this->assertSame(0, DocumentTemplateVersion::count());
    }

    public function test_page_policies_check_format_margins_bands_and_layouts(): void
    {
        $this->assertSame('format_not_allowed', $this->refusal(fn () => $this->page(self::QUOTE, 'letter', $this->pageConfig(PageFormat::Letter))));
        $this->assertSame('margins_invalid', $this->refusal(fn () => $this->pageConfig(margin: '2')));
        $this->assertSame('margins_invalid', $this->refusal(fn () => $this->pageConfig(margin: '20.5.1')));
        $this->assertSame('layout_unknown', $this->refusal(fn () => $this->pageConfig(layouts: ['freeform'])));
        $this->assertSame('layout_unknown', $this->refusal(fn () => $this->pageConfig(layouts: [])));
        $this->assertSame('band_invalid', $this->refusal(fn () => new PagePolicyConfiguration(PageFormat::A4, PageOrientation::Portrait, '20', '20', '20', '20', PageBandMode::PageNumber, PageBandMode::Compact, PageBandMode::PageNumber, PageBandMode::None, [DocumentLayouts::STANDARD])));
        $this->assertSame('layout_not_allowed', $this->refusal(fn () => $this->page(self::QUOTE, 'compact-only', $this->pageConfig(layouts: [DocumentLayouts::COMPACT]))));
        $letter = $this->page(self::PO, 'letter', $this->pageConfig(PageFormat::Letter));
        $this->assertSame(['215.9', '279.4'], [(string) $letter->width_mm, (string) $letter->height_mm]);
        $this->assertSame('A4', $this->page('*', 'a4')->format);
    }

    // ---- activation

    public function test_activation_is_generation_locked_and_recorded(): void
    {
        $t1 = $this->template();
        $p1 = $this->page(self::PO, 'po-page');

        $first = $this->activate(self::PO, $t1->id, $p1->id, 0);
        $this->assertSame(1, $first->generation);
        $this->assertSame('generation_conflict', $this->refusal(fn () => $this->activate(self::PO, $this->template()->id, null, 0)));
        $this->assertSame('no_change', $this->refusal(fn () => $this->activate(self::PO, $t1->id, null, 1)));

        $t2 = $this->template();
        $second = $this->activate(self::PO, $t2->id, null, 1);
        $this->assertSame(2, $second->generation);
        $this->assertSame($p1->id, $second->active_page_policy_version_id, 'the page policy is kept when only the template is replaced');
        $events = DocumentDefaultActivation::query()->where('type_key', self::PO)->orderBy('generation')->get();
        $this->assertCount(2, $events);
        $this->assertSame($t1->id, $events[1]->previous_template_version_id);
        $this->assertFalse($events[1]->is_downgrade);
    }

    public function test_activation_refuses_wrong_type_unknown_version_incompatible_layout_and_type_constraints(): void
    {
        $poTemplate = $this->template();
        $poPage = $this->page(self::PO, 'po-page');
        $letterPage = $this->page('*', 'letter-generic', $this->pageConfig(PageFormat::Letter));
        $compactOnly = $this->page('*', 'compact-only', $this->pageConfig(layouts: [DocumentLayouts::COMPACT]));

        $this->assertSame('type_mismatch', $this->refusal(fn () => $this->activate(self::QUOTE, $poTemplate->id, null, 0)));
        $this->assertSame('template_not_generic', $this->refusal(fn () => $this->activate('*', $poTemplate->id, null, 0)));
        $this->assertSame('version_not_found', $this->refusal(fn () => $this->activate(self::PO, 999999, null, 0)));
        $this->assertSame('nothing_to_activate', $this->refusal(fn () => $this->activate(self::PO, null, null, 0)));
        $this->assertSame('incompatible', $this->refusal(fn () => $this->activate(self::PO, $poTemplate->id, $compactOnly->id, 0)));
        // A Letter page policy is generic, but the quotation-shaped type allows A4 only.
        $this->assertSame('format_not_allowed', $this->refusal(fn () => $this->activate(self::QUOTE, null, $letterPage->id, 0)));
        // The PO-shaped type opted into Letter.
        $this->assertSame(1, $this->activate(self::PO, $poTemplate->id, $letterPage->id, 0)->generation);
        $this->assertSame(0, DocumentCompanyDefault::query()->where('type_key', self::QUOTE)->count());
        $this->assertNotNull($poPage);
    }

    public function test_another_companys_version_is_not_visible_to_activation(): void
    {
        $theirs = DB::table('document_page_policy_versions')->insertGetId([
            'company_id' => $this->otherCompany, 'type_key' => '*', 'policy_key' => 'theirs', 'version' => 1, 'format' => 'A4', 'orientation' => 'PORTRAIT', 'width_mm' => 210.0, 'height_mm' => 297.0,
            'margin_top_mm' => 20, 'margin_right_mm' => 20, 'margin_bottom_mm' => 20, 'margin_left_mm' => 20, 'first_header_mode' => 'FULL', 'continuation_header_mode' => 'FULL',
            'first_footer_mode' => 'NONE', 'continuation_footer_mode' => 'NONE', 'compatible_layouts' => '["standard-table"]', 'created_at' => now(),
        ]);
        $this->assertSame('version_not_found', $this->refusal(fn () => $this->activate(self::PO, null, $theirs, 0)));
    }

    public function test_downgrade_needs_a_reason_and_is_refused_while_live_documents_pin_the_version(): void
    {
        $t1 = $this->template();
        $t2 = $this->template();
        $this->activate(self::PO, $t1->id, null, 0);
        $this->activate(self::PO, $t2->id, null, 1);

        $this->assertSame('downgrade_needs_reason', $this->refusal(fn () => $this->activate(self::PO, $t1->id, null, 2)));

        $this->app->instance(DocumentConfigurationUsage::class, new class implements DocumentConfigurationUsage
        {
            public function isPinned(int $companyId, string $kind, int $versionId): bool
            {
                return $kind === 'template';
            }
        });
        $this->assertSame('version_pinned', $this->refusal(fn () => $this->activate(self::PO, $t1->id, null, 2, 'back out v2')));
        $this->assertSame(2, DocumentCompanyDefault::query()->where('type_key', self::PO)->sole()->generation);

        $this->app->instance(DocumentConfigurationUsage::class, new class implements DocumentConfigurationUsage
        {
            public function isPinned(int $companyId, string $kind, int $versionId): bool
            {
                return false;
            }
        });
        $this->assertSame(3, $this->activate(self::PO, $t1->id, null, 2, 'back out v2')->generation);
        $event = DocumentDefaultActivation::query()->where('type_key', self::PO)->where('generation', 3)->sole();
        $this->assertTrue($event->is_downgrade);
        $this->assertSame('back out v2', $event->reason);
    }

    // ---- resolution

    public function test_resolution_falls_back_to_the_shipped_default_and_never_invents_a_brand(): void
    {
        $r = $this->resolve(self::PO);
        $this->assertSame(['SHIPPED', 'SHIPPED'], [$r->template->source, $r->pagePolicy->source]);
        $this->assertNull($r->template->versionId);
        $this->assertSame(ResolvedDocumentPolicy::BRAND_NOT_AVAILABLE, $r->brand);
        $this->assertSame('A4', $r->pagePolicy->configuration->format->value);
        $this->assertSame(['lines'], $r->template->configuration->mandatorySections);

        // Even with configuration in place, the brand stays absent: nothing here can supply or default one.
        $this->activate(self::PO, $this->template()->id, $this->page(self::PO, 'po-page')->id, 0);
        $this->assertSame('NOT_AVAILABLE', $this->resolve(self::PO)->brand);
        foreach (['document_template_versions', 'document_page_policy_versions', 'document_company_defaults', 'document_default_activations'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertDoesNotMatchRegularExpression('/brand|logo|asset|font|colou?r/', $column, "{$table}.{$column} must not carry brand data in DF1a");
            }
        }
    }

    public function test_resolution_layers_company_type_and_explicit_choices(): void
    {
        $companyPage = $this->page('*', 'company-letter', $this->pageConfig(PageFormat::Letter));
        $this->activate('*', null, $companyPage->id, 0);
        $typeTemplate = $this->template();
        $this->activate(self::PO, $typeTemplate->id, null, 0);

        $po = $this->resolve(self::PO);
        $this->assertSame('COMPANY', $po->pagePolicy->source);
        $this->assertSame('LETTER', $po->pagePolicy->configuration->format->value);
        $this->assertSame('TYPE', $po->template->source);
        $this->assertSame($typeTemplate->id, $po->template->versionId);

        $ownPage = $this->page(self::PO, 'po-a4');
        $this->activate(self::PO, null, $ownPage->id, 1);
        $this->assertSame(['TYPE', 'A4'], [$this->resolve(self::PO)->pagePolicy->source, $this->resolve(self::PO)->pagePolicy->configuration->format->value]);

        $explicit = $this->page(self::PO, 'po-explicit', $this->pageConfig(PageFormat::Letter));
        $r = $this->resolve(self::PO, page: $explicit->id);
        $this->assertSame(['EXPLICIT', $explicit->id], [$r->pagePolicy->source, $r->pagePolicy->versionId]);
    }

    public function test_the_type_constraint_overrides_an_incompatible_company_default(): void
    {
        $companyPage = $this->page('*', 'company-letter', $this->pageConfig(PageFormat::Letter));
        $this->activate('*', null, $companyPage->id, 0);

        $quote = $this->resolve(self::QUOTE);
        $this->assertSame('SHIPPED', $quote->pagePolicy->source);
        $this->assertSame('A4', $quote->pagePolicy->configuration->format->value);
        $this->assertCount(1, $quote->notes);
        $this->assertStringContainsString('not supported', $quote->notes[0]);
        $this->assertSame('LETTER', $this->resolve(self::PO)->pagePolicy->configuration->format->value, 'the separate Letter-capable fixture does use the Letter default');
    }

    public function test_an_explicit_choice_that_does_not_fit_is_refused(): void
    {
        $letter = $this->page('*', 'company-letter', $this->pageConfig(PageFormat::Letter));
        $compactOnly = $this->page('*', 'compact-only', $this->pageConfig(layouts: [DocumentLayouts::COMPACT]));
        $poTemplate = $this->template();

        $this->assertSame('format_not_allowed', $this->refusal(fn () => $this->resolve(self::QUOTE, page: $letter->id)));
        $this->assertSame('incompatible', $this->refusal(fn () => $this->resolve(self::PO, $poTemplate->id, $compactOnly->id)));
        $this->assertSame('type_mismatch', $this->refusal(fn () => $this->resolve(self::QUOTE, $poTemplate->id)));
        $this->assertSame('version_not_found', $this->refusal(fn () => $this->resolve(self::PO, 999999)));
    }

    public function test_resolution_ignores_user_preferences_and_changes_nothing(): void
    {
        $this->activate(self::PO, $this->template()->id, $this->page(self::PO, 'po-page')->id, 0);
        $before = $this->resolve(self::PO);
        $counts = [DocumentTemplateVersion::count(), DocumentPagePolicyVersion::count(), DocumentDefaultActivation::count()];

        app()->setLocale('ur');
        config(['app.timezone' => 'Asia/Karachi']);
        $this->actingAs($this->user);

        $this->assertEquals($before, $this->resolve(self::PO));
        $this->assertSame($counts, [DocumentTemplateVersion::count(), DocumentPagePolicyVersion::count(), DocumentDefaultActivation::count()]);
    }

    // ---- the database

    public function test_versions_and_activation_history_are_immutable(): void
    {
        $t = $this->template();
        $p = $this->page();
        $this->activate(self::PO, $t->id, $p->id, 0);

        $this->assertDbRefuses(fn () => DB::table('document_template_versions')->update(['layout_id' => 'compact-table']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('document_template_versions')->delete(), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('document_page_policy_versions')->update(['format' => 'LETTER']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('document_page_policy_versions')->delete(), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('document_default_activations')->update(['reason' => 'x']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('document_default_activations')->delete(), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('document_company_defaults')->delete(), 'cannot be deleted');
    }

    public function test_the_database_refuses_invalid_raw_versions(): void
    {
        $page = [
            'company_id' => 1, 'type_key' => '*', 'policy_key' => 'raw', 'version' => 1, 'format' => 'A4', 'orientation' => 'PORTRAIT', 'width_mm' => 210.0, 'height_mm' => 297.0,
            'margin_top_mm' => 20, 'margin_right_mm' => 20, 'margin_bottom_mm' => 20, 'margin_left_mm' => 20, 'first_header_mode' => 'FULL', 'continuation_header_mode' => 'FULL',
            'first_footer_mode' => 'NONE', 'continuation_footer_mode' => 'NONE', 'compatible_layouts' => '["standard-table"]', 'created_at' => now(),
        ];
        foreach ([['width_mm' => 215.9], ['format' => 'LETTER'], ['format' => 'LEGAL'], ['margin_top_mm' => 4], ['margin_left_mm' => 120], ['first_header_mode' => 'PAGE_NUMBER'],
            ['continuation_footer_mode' => 'FULL'], ['compatible_layouts' => '[]'], ['compatible_layouts' => '{}'], ['policy_key' => 'Bad Key'], ['version' => 0]] as $bad) {
            $this->assertDbRefuses(fn () => DB::table('document_page_policy_versions')->insert([...$page, ...$bad]), 'document_page_policy_versions_shape');
        }
        $this->assertDbRefuses(fn () => DB::table('document_page_policy_versions')->insert([...$page, 'bound_by' => 1]), 'bound_by');

        $template = ['company_id' => 1, 'type_key' => self::PO, 'template_key' => 'raw', 'version' => 1, 'layout_id' => 'standard-table', 'permitted_sections' => '["lines"]', 'mandatory_sections' => '["lines"]', 'created_at' => now()];
        foreach ([['mandatory_sections' => '["terms"]'], ['permitted_sections' => '"lines"'], ['layout_id' => 'Evil Layout'], ['type_key' => '*'], ['version' => 0]] as $bad) {
            $this->assertDbRefuses(fn () => DB::table('document_template_versions')->insert([...$template, ...$bad]), 'document_template_versions_shape');
        }
        $this->assertDbRefuses(fn () => DB::table('document_template_versions')->insert([...$template, 'company_id' => $this->otherCompany, 'created_by' => $this->user->id]), 'document_template_versions_created_by_fk');
        DB::table('document_template_versions')->insert($template);
        $this->assertDbRefuses(fn () => DB::table('document_template_versions')->insert($template), 'document_template_versions_identity_unique');
    }

    public function test_the_database_refuses_a_default_that_breaks_its_keys_or_history(): void
    {
        $t = $this->template();
        $quoteTemplate = $this->template(self::QUOTE);
        $p = $this->page('*', 'plain');
        $compactOnly = $this->page('*', 'compact-only', $this->pageConfig(layouts: [DocumentLayouts::COMPACT]));
        $other = $this->page(self::QUOTE, 'quote-page');
        $default = ['company_id' => 1, 'type_key' => self::PO, 'generation' => 1, 'active_template_version_id' => $t->id, 'active_page_policy_version_id' => $p->id, 'updated_at' => now()];

        // A template of another type cannot be a default (the key includes type_key); a generic default carries no template.
        $this->assertDbRefuses(fn () => DB::table('document_company_defaults')->insert([...$default, 'active_template_version_id' => $quoteTemplate->id]), 'document_company_defaults_template_fk');
        $this->assertDbRefuses(fn () => DB::table('document_company_defaults')->insert([...$default, 'type_key' => '*']), 'document_company_defaults_shape');
        $this->assertDbRefuses(fn () => DB::table('document_company_defaults')->insert([...$default, 'active_template_version_id' => null, 'active_page_policy_version_id' => null]), 'document_company_defaults_shape');
        $this->assertDbRefuses(fn () => DB::table('document_company_defaults')->insert([...$default, 'company_id' => $this->otherCompany]), 'document_company_defaults_template_fk');

        // Deferred checks run at commit: a default with no matching activation event, a foreign page policy and an incompatible layout are all refused.
        $this->assertCommitRefuses(fn () => DB::table('document_company_defaults')->insert($default), 'must equal its activation event');
        $this->assertCommitRefuses(function () use ($default, $other, $t): void {
            DB::table('document_company_defaults')->insert([...$default, 'active_page_policy_version_id' => $other->id]);
            DB::table('document_default_activations')->insert(['company_id' => 1, 'type_key' => self::PO, 'generation' => 1, 'template_version_id' => $t->id, 'page_policy_version_id' => $other->id, 'activated_at' => now()]);
        }, 'belongs to another document type');
        $this->assertCommitRefuses(function () use ($default, $compactOnly, $t): void {
            DB::table('document_company_defaults')->insert([...$default, 'active_page_policy_version_id' => $compactOnly->id]);
            DB::table('document_default_activations')->insert(['company_id' => 1, 'type_key' => self::PO, 'generation' => 1, 'template_version_id' => $t->id, 'page_policy_version_id' => $compactOnly->id, 'activated_at' => now()]);
        }, 'not compatible with the template layout');
        $this->assertCommitRefuses(fn () => DB::table('document_default_activations')->insert(['company_id' => 1, 'type_key' => self::PO, 'generation' => 1, 'template_version_id' => $t->id, 'activated_at' => now()]), 'needs its Company default');

        // A real default can only move forward by exactly one generation, and a downgrade event needs a reason.
        $this->activate(self::PO, $t->id, $p->id, 0);
        $this->assertDbRefuses(fn () => DB::table('document_company_defaults')->update(['generation' => 3]), 'exactly one generation');
        $this->assertDbRefuses(fn () => DB::table('document_company_defaults')->update(['type_key' => self::QUOTE, 'generation' => 2]), 'keeps its company and type');
        $this->assertDbRefuses(fn () => DB::table('document_default_activations')->insert(['company_id' => 1, 'type_key' => self::PO, 'generation' => 2, 'template_version_id' => $t->id, 'is_downgrade' => true, 'activated_at' => now()]), 'must name the versions the previous activation left active');
    }

    private function assertCommitRefuses(Closure $write, string $contains): void
    {
        try {
            DB::transaction(function () use ($write): void {
                $write();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());

            return;
        }
        $this->fail("The database accepted a write that should have been refused at commit ({$contains}).");
    }

    // ---- the closed door

    public function test_the_entry_stays_disabled(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => str_starts_with($u, 'api/v1/documents'))->values()->all());
        $this->assertSame([], array_values(array_filter(app(PermissionRegistry::class)->all(), fn ($p) => str_starts_with($p, 'documents.'))));
    }
}
