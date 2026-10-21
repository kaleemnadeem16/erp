<?php

namespace Tests\Unit\Modules\Sales;

use App\Modules\Sales\Support\Authority\DutyClass;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyCatalogue;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/** AU0 — the Sales duty manifest reconciles the accepted matrix with what is actually registered (ADR 0138). Pure: no database. */
class SalesDutyManifestTest extends TestCase
{
    private const EXISTING = [Sales::PIPELINE_VIEW, Sales::LEAD_MANAGE, Sales::OPPORTUNITY_MANAGE, Sales::OPPORTUNITY_ASSIGN, Sales::OPPORTUNITY_CLOSE, Sales::STAGE_MANAGE,
        Sales::NUMBERING_MANAGE, Sales::OPPORTUNITY_ATTRIBUTION_CORRECT];

    private function duty(string $name, DutyClass $class, DutyStatus $status): SalesDuty
    {
        return new SalesDuty($name, $class, $status, 'op', 'projection', 'gate');
    }

    public function test_the_catalogue_is_the_accepted_matrix_sales_rows_and_nothing_else(): void
    {
        $manifest = SalesDutyManifest::shipped();
        $names = array_map(fn (SalesDuty $duty): string => $duty->name, $manifest->all());

        $this->assertCount(58, $names, 'The 58 Sales-owned rows of the 78-row matrix; the other 20 belong to other modules.');
        $this->assertSame($names, array_values(array_unique($names)));
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^sales\.[a-z][a-z0-9.-]*$/', $name);
            $this->assertStringNotContainsString('*', $name);
        }
        // Shared Documents, Tax and other owners' duties are not registered under Sales.
        foreach (['documents.template.manage', 'tax.configuration.manage', 'currency.exchange-rate.manage', 'company.profile.manage', 'approvals.authority.manage', 'numbering.series.select'] as $foreign) {
            $this->assertNull($manifest->find($foreign), $foreign);
        }
    }

    public function test_exactly_the_eight_existing_crm_duties_are_active_and_unchanged(): void
    {
        $manifest = SalesDutyManifest::shipped();

        $this->assertEqualsCanonicalizing(self::EXISTING, $manifest->activeNames());
        $this->assertEqualsCanonicalizing(self::EXISTING, Sales::all());
        foreach (self::EXISTING as $name) {
            $this->assertTrue($manifest->isActive($name));
            $this->assertTrue($manifest->find($name)->class->isExisting());
        }
        $this->assertSame(DutyClass::ExistingSensitive, $manifest->find(Sales::OPPORTUNITY_ATTRIBUTION_CORRECT)->class);
        $this->assertSame(DutyClass::Existing, $manifest->find(Sales::PIPELINE_VIEW)->class);
    }

    public function test_every_other_duty_is_specified_or_dormant_and_none_is_active(): void
    {
        $manifest = SalesDutyManifest::shipped();

        $this->assertCount(50, array_filter($manifest->all(), fn (SalesDuty $duty): bool => ! $duty->class->isExisting()));
        $this->assertCount(8, $manifest->withStatus(DutyStatus::Active));
        $this->assertCount(5, $manifest->withStatus(DutyStatus::Dormant), 'delivery.send, delivery.reconcile, pricing exception, pricing agreement departure and claim-release');
        foreach ($manifest->withStatus(DutyStatus::Dormant) as $duty) {
            $this->assertSame(DutyClass::DormantSensitive, $duty->class, $duty->name);
        }
        foreach ($manifest->withStatus(DutyStatus::Specified) as $duty) {
            $this->assertContains($duty->class, [DutyClass::NewOrdinary, DutyClass::Sensitive], $duty->name);
            $this->assertFalse($manifest->isActive($duty->name));
        }
        $this->assertCount(43, $manifest->withStatus(DutyStatus::Specified));
        $this->assertSame(['sales.financial.configure', 'sales.document.generate'], array_map(fn (SalesDuty $duty): string => $duty->name, $manifest->withStatus(DutyStatus::Staged)), 'The staged duties: their Actions exist, the duties are disabled.');
        $this->assertFalse($manifest->isActive('sales.document.generate'));
        $this->assertFalse($manifest->isActive('sales.financial.configure'), 'FR1/FR4 configuration is staged, not live: no holder recipe is approved.');
        $this->assertSame(DutyClass::Sensitive, $manifest->find('sales.financial.configure')->class);
        $this->assertFalse($manifest->isActive('sales.*'));
        $this->assertFalse($manifest->isActive('sales'));
    }

    public function test_an_unsound_duty_or_manifest_is_refused(): void
    {
        foreach (['sales.*', 'sales', 'Sales.quotation.view', 'documents.template.manage', 'sales.quotation view', 'sales..x', ''] as $bad) {
            try {
                $this->duty($bad, DutyClass::Sensitive, DutyStatus::Specified);
                $this->fail("[{$bad}] should be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new SalesDutyManifest([$this->duty('sales.x.view', DutyClass::NewOrdinary, DutyStatus::Specified), $this->duty('sales.x.view', DutyClass::NewOrdinary, DutyStatus::Specified)]);
    }

    public function test_class_and_status_cannot_contradict(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->duty('sales.future.exception', DutyClass::DormantSensitive, DutyStatus::Active);
    }

    public function test_an_existing_crm_duty_cannot_be_demoted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->duty('sales.pipeline.view', DutyClass::Existing, DutyStatus::Specified);
    }

    public function test_only_the_existing_non_sensitive_duties_stay_automatically_grantable(): void
    {
        $shipped = SalesDutyManifest::shipped()->automaticGrantExclusions();
        $this->assertSame([[Sales::OPPORTUNITY_ATTRIBUTION_CORRECT]], array_values($shipped), 'Unchanged: only the ADR 0114 duty is excluded today.');
        $this->assertSame('Retrospective attribution is a sensitive duty: granted only explicitly or under a verified full-access mandate (ADR 0114).', array_key_first($shipped));

        $future = new SalesDutyManifest([
            $this->duty('sales.pipeline.view', DutyClass::Existing, DutyStatus::Active),
            $this->duty('sales.quotation.view', DutyClass::NewOrdinary, DutyStatus::Active),
            $this->duty('sales.financial.configure', DutyClass::Sensitive, DutyStatus::Active),
            $this->duty('sales.quotation.prepare', DutyClass::NewOrdinary, DutyStatus::Specified),
            $this->duty('sales.delivery.send', DutyClass::DormantSensitive, DutyStatus::Dormant),
        ]);
        $excluded = array_merge(...array_values($future->automaticGrantExclusions()));

        $this->assertEqualsCanonicalizing(['sales.quotation.view', 'sales.financial.configure'], $excluded, 'A new duty — ordinary or sensitive — is never part of an automatic grant, and an unregistered one is not listed.');
        $this->assertNotContains('sales.pipeline.view', $excluded);
    }

    public function test_no_application_code_names_a_non_active_sales_duty(): void
    {
        $manifest = SalesDutyManifest::shipped();
        $pending = array_map(fn (SalesDuty $duty): string => $duty->name, array_filter($manifest->all(), fn (SalesDuty $duty): bool => ! $duty->isActive() && $duty->status !== DutyStatus::Staged));
        $catalogue = (new \ReflectionClass(SalesDutyCatalogue::class))->getFileName();
        $offenders = [];

        foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 4).'/app') as $file) {
            if ($file->getPathname() === $catalogue) {
                continue;
            }
            $source = $file->getContents();
            foreach ($pending as $name) {
                if (str_contains($source, "'{$name}'") || str_contains($source, "\"{$name}\"")) {
                    $offenders[] = $name.' in '.$file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'A specified or dormant duty must have no consumer until its slice stages it; a staged one is named only by its guarded Actions.');
    }

    public function test_the_rollout_manifest_document_lists_every_duty_with_its_status(): void
    {
        $path = dirname(__DIR__, 5).'/docs/phases/phase-4-au0-au1-rollout-manifest.md';
        if (! is_file($path)) {
            $this->markTestSkipped('The docs folder is outside the backend container; this runs in CI and from a checkout.');
        }
        $document = (string) file_get_contents($path);

        foreach (SalesDutyManifest::shipped()->all() as $duty) {
            $row = null;
            foreach (explode("\n", $document) as $line) {
                if (str_starts_with($line, "| `{$duty->name}` |")) {
                    $row = $line;
                    break;
                }
            }
            $this->assertNotNull($row, "{$duty->name} is missing from the rollout manifest.");
            if (! $duty->isActive()) {
                $this->assertStringContainsString($duty->status->value, $row, "{$duty->name} status");
            }
        }
    }
}
