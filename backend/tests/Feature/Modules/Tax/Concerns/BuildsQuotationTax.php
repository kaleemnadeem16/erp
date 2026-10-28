<?php

namespace Tests\Feature\Modules\Tax\Concerns;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Tax\Actions\ActivateTaxGroup;
use App\Modules\Tax\Actions\ActivateTaxQuotationDeclaration;
use App\Modules\Tax\Actions\CreateTaxComponent;
use App\Modules\Tax\Actions\CreateTaxQuotationDeclarationSuccessor;
use App\Modules\Tax\Actions\CreateTaxVersionSuccessor;
use App\Modules\Tax\Actions\DefineTaxGroupGraph;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\Quotation\QuotationTaxContext;
use App\Modules\Tax\Support\Quotation\QuotationTaxContextAuthorizer;
use App\Modules\Tax\Support\SourceKind;
use App\Modules\Tax\Support\TaxClassification;
use App\Modules\Tax\Support\TaxDutyManifest;
use App\Modules\Tax\Support\TaxNature;
use App\Modules\Tax\Support\TaxVersionKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Synthetic test evidence, never installed tax law or production duty activation. */
trait BuildsQuotationTax
{
    private User $actor;

    private Party $party;

    private int $currency;

    private function setUpQuotationTax(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->actor = User::factory()->create();
        $this->party = Party::create(['code' => 'fr3-prospect', 'name' => 'Illustrative prospect'])->refresh();
        $this->currency = DB::table('currencies')->insertGetId(['code' => 'TQT', 'name' => 'Illustrative Tax currency', 'symbol' => 'T', 'decimal_places' => 2, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([TaxDutyManifest::CONFIGURE, TaxDutyManifest::PROFILE] as $duty) {
            Permission::findOrCreate($duty, 'web');
            $this->actor->givePermissionTo($duty);
        }
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest([TaxDutyManifest::CONFIGURE => 'ACTIVE', TaxDutyManifest::PROFILE => 'ACTIVE']));
    }

    private function generation(): int
    {
        return (int) DB::table('tax_generations')->where('company_id', 1)->value('generation');
    }

    /** @return array<string,int|string|bool|null> */
    private function coverage(string $code = 'goods', string $percentage = '10.000000000', string $disposition = 'TAXABLE', bool $profile = false, string $nature = 'GOODS', string $mode = 'BASIC', bool $activate = true): array
    {
        $component = app(CreateTaxComponent::class)($this->actor, $code, 'identity-'.$code, $this->generation());
        $period = new EffectivePeriod('2026-01-01', '2028-01-01');
        $make = fn (TaxVersionKind $kind, array $definition): int => app(CreateTaxVersionSuccessor::class)($this->actor, $kind, $code, null, $period, $definition, 'Illustrative', $kind->value.'-'.$code, $this->generation());
        $cv = $make(TaxVersionKind::Component, ['component_id' => $component, 'name' => 'Illustrative '.$code]);
        $rate = $make(TaxVersionKind::Rate, ['component_id' => $component, 'percentage' => $percentage]);
        $rule = $make(TaxVersionKind::Rule, ['component_id' => $component, 'component_version_id' => $cv, 'rate_version_id' => $rate, 'method' => 'PERCENT', 'base' => 'NET', 'dependency_stage' => 'RAW',
            'required_mode' => $mode, 'nature' => $nature, 'classification' => $code, 'jurisdiction' => 'illustrative']);
        $group = $make(TaxVersionKind::Group, ['required_mode' => $mode, 'currency_id' => $this->currency]);
        app(DefineTaxGroupGraph::class)($this->actor, $group, [['component_id' => $component, 'rule_version_id' => $rule, 'predecessors' => []]], 'graph-'.$code, $this->generation());
        if ($activate) {
            app(ActivateTaxGroup::class)($this->actor, $group, 'Illustrative', 'activate-'.$code, $this->generation());
        }

        return ['nature' => $nature, 'classification' => $code, 'group_version_id' => $group, 'component_id' => $component, 'rule_version_id' => $rule,
            'disposition' => $disposition, 'requires_party_profile' => $profile, 'grounds' => 'protected-declaration-grounds'];
    }

    /** @param list<array<string,int|string|bool|null>> $coverage */
    private function declaration(array $coverage, string $key = 'declare', ?int $previous = null, string $from = '2026-01-01', ?string $to = '2027-01-01', string $rounding = 'HALF_UP', string $code = 'illustrative'): int
    {
        return app(CreateTaxQuotationDeclarationSuccessor::class)($this->actor, $code, 'illustrative', $previous, new EffectivePeriod($from, $to), $rounding, $coverage, 'protected-declaration-reason', $key, $this->generation());
    }

    private function activateDeclaration(int $id, string $key = 'activate-declaration'): int
    {
        return app(ActivateTaxQuotationDeclaration::class)($this->actor, $id, 'Reviewed illustrative declaration', $key, $this->generation());
    }

    private function context(string $line = 'line-1', string $classification = 'goods', string $date = '2026-10-27', TaxNature $nature = TaxNature::Goods): QuotationTaxContext
    {
        return new QuotationTaxContext(1, $this->party->id, $this->currency, $line, 'illustrative', $date, new TaxClassification(SourceKind::Manual, $nature, $classification, 'manual_explicit'));
    }

    private function allowContext(QuotationTaxContext ...$contexts): void
    {
        $fingerprints = array_map(static fn (QuotationTaxContext $c): string => $c->fingerprint(), $contexts);
        $actorId = $this->actor->id;
        $this->app->instance(QuotationTaxContextAuthorizer::class, new class($fingerprints, $actorId) implements QuotationTaxContextAuthorizer
        {
            public function __construct(private array $fingerprints, private int $actorId) {}

            public function authorise(User $actor, QuotationTaxContext $context): void
            {
                if ($actor->id !== $this->actorId || ! in_array($context->fingerprint(), $this->fingerprints, true)) {
                    throw new AuthorizationException('Fixture source context is not authorised.');
                }
            }
        });
    }
}
