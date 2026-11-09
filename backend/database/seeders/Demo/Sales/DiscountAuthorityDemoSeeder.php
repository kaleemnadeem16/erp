<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Sales;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\SalesDiscountBand;
use App\Modules\Sales\Models\SalesDiscountPolicy;
use App\Modules\Sales\Models\SalesPriceReference;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\DiscountAuthority\DiscountPolicyInput;
use App\Modules\Sales\Support\DiscountAuthority\ReferenceInput;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Support\CompanyContext;
use App\Support\Scope\CompanyAccessFence;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create-only, explicitly fictitious configuration fixtures. No duty activation, grant or mode change.
 * Uses the same input validators and database guards; it is a demo fixture loader, not an authority bypass for runtime Actions.
 */
final class DiscountAuthorityDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public const CODE = 'demo-only-discount-authority';

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $company = Company::query()->find(app(CompanyContext::class)->id());
        if ($company === null || $company->id !== 1 || $company->name !== 'X1 ERP Demo Company' || $company->code !== 'X1' || $company->base_currency_id === null) {
            return;
        }
        if ($company->base_currency_id !== Currency::query()->where('code', 'PKR')->value('id')) {
            return; // These illustrative monetary bands are DEMO ONLY PKR defaults.
        }
        $item = Item::query()->where('company_id', 1)->where('code', 'demo-site-application')->first();
        $customer = Party::query()->where('company_id', 1)->where('code', 'cus-alpha')->first();
        if ($item === null || $customer === null) {
            return;
        }
        DB::transaction(function () use ($company, $item, $customer): void {
            app(CompanyAccessFence::class)->lockAll();
            app(AuthorityFence::class)->lock();
            if (SalesDiscountPolicy::query()->where('code', self::CODE)->exists()) {
                return; // A completed fixture is never updated or expanded on a rerun.
            }
            $today = now($company->timezone)->format('Y-m-d');
            $proposer = $this->person('proposer');
            $reviewer = $this->person('independent-reviewer');
            $bands = [];
            foreach ([[0, 'Representative', '0', '0.0000'], [1, 'Lead', '3', '25000.0000'], [2, 'Manager', '7', '100000.0000'], [3, 'Director', '12', '500000.0000']] as [$rank, $label, $percent, $amount]) {
                $bands[] = ['rank' => $rank, 'label' => 'DEMO ONLY '.$label, 'exception_only' => false, 'line_percent' => $percent, 'document_percent' => $percent,
                    'input_percent' => $percent, 'line_base_amount' => $amount, 'document_base_amount' => $amount, 'input_base_amount' => $amount];
            }
            $bands[] = ['rank' => 4, 'label' => 'DEMO ONLY independent exception', 'exception_only' => true];
            $data = DiscountPolicyInput::parse(['code' => self::CODE, 'priority' => -1000, 'operation' => 'ANY', 'enforcement' => 'BOTH', 'customer_id' => $customer->id,
                'effective_from' => $today, 'expiry_calendar_days' => 14, 'allow_higher_approver' => false, 'reason' => 'DEMO ONLY illustrative values; no approved real holders or limits', 'bands' => $bands]);
            $bands = $data['bands'];
            unset($data['bands']);
            $policy = SalesDiscountPolicy::create($data + ['company_id' => 1, 'base_currency_id' => $company->base_currency_id, 'version_no' => 1, 'created_by' => $proposer->id]);
            foreach ($bands as $band) {
                $record = SalesDiscountBand::create($band + ['company_id' => 1, 'policy_id' => $policy->id]);
                $person = $this->person('band-'.$band['rank']);
                DB::table('sales_discount_assignments')->insert(['company_id' => 1, 'user_id' => $person->id, 'band_id' => $record->id, 'purpose' => $band['exception_only'] ? 'APPROVE' : 'BOTH',
                    'effective_from' => $today, 'assigned_by' => $proposer->id, 'reason' => 'DEMO ONLY illustrative assignment; no capability grant']);
            }
            DB::table('sales_discount_policy_activations')->insert(['company_id' => 1, 'policy_id' => $policy->id, 'actor_id' => $reviewer->id, 'reason' => 'DEMO ONLY policy fixture; mode remains unchanged']);
            $expired = $this->person('expired');
            $this->person('unassigned');
            $lead = SalesDiscountBand::query()->where('policy_id', $policy->id)->where('rank', 1)->firstOrFail();
            DB::table('sales_discount_assignments')->insert(['company_id' => 1, 'user_id' => $expired->id, 'band_id' => $lead->id, 'purpose' => 'ROUTINE',
                'effective_from' => now($company->timezone)->subDays(30)->format('Y-m-d'), 'effective_to' => now($company->timezone)->subDay()->format('Y-m-d'), 'assigned_by' => $proposer->id, 'reason' => 'DEMO ONLY expired authority example']);
            foreach ([['PRICE_LIST', null, '100.0000', 'ordinary-list'], ['CUSTOMER_AGREEMENT', $customer->id, '95.0000', 'customer-agreement']] as [$kind, $customerId, $price, $suffix]) {
                $reference = ReferenceInput::parse(['code' => 'demo-only-authority-'.$suffix, 'source_kind' => $kind, 'source_key' => 'DEMO ONLY fictitious '.$suffix, 'source_version' => '1',
                    'customer_id' => $customerId, 'item_id' => $item->id, 'uom_id' => $item->stock_uom_id, 'currency_id' => $company->base_currency_id,
                    'unit_price' => $price, 'effective_from' => $today, 'priority' => -1000, 'reason' => 'DEMO ONLY trusted-source illustration; not a real price']);
                $reference += ['version_no' => 1, 'proposed_by' => $proposer->id];
                $reference['fingerprint'] = OperationIntent::hash('sales.governed_reference.v1', $reference);
                $row = SalesPriceReference::create($reference + ['company_id' => 1]);
                DB::table('sales_price_reference_activations')->insert(['company_id' => 1, 'reference_id' => $row->id, 'approved_by' => $reviewer->id, 'reason' => 'DEMO ONLY independent fixture review']);
            }
        });
    }

    private function person(string $name): User
    {
        // No useful credential or permission is assigned; this persona must complete normal access onboarding.
        $email = 'demo-only-authority-'.$name.'@example.invalid';
        $existing = User::query()->where('company_id', 1)->where('email', $email)->first();
        if ($existing !== null) {
            return $existing;
        }
        $person = new User(['name' => 'DEMO ONLY authority '.$name, 'email' => $email, 'password' => Str::random(64), 'status' => 'ACTIVE']);
        $person->forceFill(['company_id' => 1, 'must_change_password' => true])->save();

        return $person;
    }
}
