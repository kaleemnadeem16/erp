<?php
namespace Tests\Feature\Modules\Tax;
use App\Modules\Tax\Actions\CreateTaxQuotationDeclarationSuccessor;
use App\Modules\Tax\Actions\ActivateTaxQuotationDeclaration;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxDutyManifest;
use App\Modules\Tax\Support\TaxRefusal;
use App\Modules\Tax\Models\TaxQuotationDeclarationVersion;
use App\Modules\Tax\Models\TaxQuotationDeclarationRule;
use App\Modules\Tax\Models\TaxQuotationDeclarationActivation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Tax\Concerns\BuildsQuotationTax;
use Tests\TestCase;
class TaxQuotationDeclarationTest extends TestCase
{
    use RefreshDatabase, BuildsQuotationTax;
    protected function setUp(): void { parent::setUp();$this->setUpQuotationTax(); }
    private function refuses(callable $work,string $state='23514'): void
    {
        try { DB::transaction($work);$this->fail('Invalid raw Tax write accepted.'); }
        catch (QueryException $e) { $this->assertSame($state,$e->errorInfo[0]); }
    }
    public function test_staged_duty_denies_both_actions_before_lookup_even_with_handmade_grants(): void
    {
        $rule=$this->coverage();$this->app->instance(TaxDutyManifest::class,new TaxDutyManifest);
        $this->assertThrows(fn()=>$this->declaration([$rule]),AuthorizationException::class);
        $this->assertThrows(fn()=>$this->activateDeclaration(999999),AuthorizationException::class);$this->assertDatabaseCount('tax_quotation_declaration_versions',0);
    }
    public function test_replay_rechecks_fresh_permissions_and_independent_all_scope(): void
    {
        $rule=$this->coverage();$id=$this->declaration([$rule]);$this->actor->load('permissions','roles');$stale=clone $this->actor;$this->actor->revokePermissionTo(TaxDutyManifest::CONFIGURE);
        $this->assertThrows(fn()=>app(CreateTaxQuotationDeclarationSuccessor::class)($stale,'illustrative','illustrative',null,new EffectivePeriod('2026-01-01','2027-01-01'),'HALF_UP',[$rule],'protected-declaration-reason','declare',0),AuthorizationException::class);
        $this->actor->givePermissionTo(TaxDutyManifest::CONFIGURE);
        DB::table('access_scope_limits')->insert(['company_id'=>1,'user_id'=>$this->actor->id,'permission'=>TaxDutyManifest::CONFIGURE,'scope'=>'MY_UNITS','created_at'=>now(),'updated_at'=>now()]);
        $this->assertThrows(fn()=>$this->declaration([$rule]),AuthorizationException::class);$this->assertThrows(fn()=>$this->activateDeclaration($id),AuthorizationException::class);
    }
    public function test_replay_is_stable_but_changed_intent_or_generation_refuses(): void
    {
        $rules=[$this->coverage(),$this->coverage('services','5.000000000','TAXABLE',false,'SERVICES')];$before=$this->generation();$id=$this->declaration($rules);$after=$this->generation();
        $this->assertSame($id,$this->declaration(array_reverse($rules)));$this->assertSame($after,$this->generation());$this->assertDatabaseCount('tax_quotation_declaration_versions',1);
        try { $this->declaration($rules,rounding:'HALF_EVEN');$this->fail('Key conflict accepted.'); }
        catch (TaxRefusal $e) { $this->assertSame('TAX_KEY_CONFLICT',$e->reasonCode); }
        try { app(CreateTaxQuotationDeclarationSuccessor::class)($this->actor,'other','other',null,new EffectivePeriod('2026-01-01','2027-01-01'),'HALF_UP',$rules,'reason','stale',$before);$this->fail('Stale generation accepted.'); }
        catch (TaxRefusal $e) { $this->assertSame('TAX_GENERATION_STALE',$e->reasonCode); }
        $activation=$this->activateDeclaration($id);$generation=$this->generation();$this->assertSame($activation,$this->activateDeclaration($id));$this->assertSame($generation,$this->generation());
    }
    public function test_last_child_failure_rolls_back_header_generation_and_receipt_and_is_redacted(): void
    {
        $rule=$this->coverage();$bad=$rule;$bad['component_id']=999999;$bad['classification']='bad';$generation=$this->generation();$receipts=DB::table('tax_operation_receipts')->count();
        try { $this->declaration([$rule,$bad]);$this->fail('Invalid child accepted.'); }
        catch (TaxRefusal $e) { $this->assertSame('TAX_DECLARATION_INVALID',$e->reasonCode);$this->assertNull($e->getPrevious());$this->assertStringNotContainsString('protected-',$e->getMessage()); }
        $this->assertDatabaseCount('tax_quotation_declaration_versions',0);$this->assertDatabaseCount('tax_quotation_declaration_rules',0);$this->assertSame($generation,$this->generation());$this->assertDatabaseCount('tax_operation_receipts',$receipts);
    }
    public function test_exact_adjacent_successors_and_cross_code_nonoverlap(): void
    {
        $rules=[$this->coverage()];$first=$this->declaration($rules);
        $this->assertThrows(fn()=>$this->declaration($rules,'overlap',code:'competing'),TaxRefusal::class);
        $this->assertThrows(fn()=>$this->declaration($rules,'gap',$first,'2027-02-01','2028-01-01'),TaxRefusal::class);
        $second=$this->declaration($rules,'next',$first,'2027-01-01','2028-01-01');$this->assertNotSame($first,$second);$this->activateDeclaration($first);$this->activateDeclaration($second,'activate-next');
    }
    public function test_unactivated_groups_unsupported_modes_and_blanket_exemption_cannot_activate(): void
    {
        $rule=$this->coverage(activate:false);$id=$this->declaration([$rule]);$this->assertThrows(fn()=>$this->activateDeclaration($id),TaxRefusal::class);
        $advanced=$this->coverage('advanced',mode:'ADVANCED');$next=$this->declaration([$advanced],'advanced',$id,'2027-01-01','2028-01-01');$this->assertThrows(fn()=>$this->activateDeclaration($next,'activate-advanced'),TaxRefusal::class);
        $blanket=$rule;$blanket['disposition']='EXEMPT';$this->assertThrows(fn()=>$this->declaration([$blanket],'blanket',$next,'2028-01-01','2029-01-01'),TaxRefusal::class);
        $this->assertDatabaseCount('tax_quotation_declaration_activations',0);
    }
    public function test_raw_activation_rejects_missing_and_mismatched_coverage(): void
    {
        $rule=$this->coverage();$id=$this->declaration([$rule]);$header=(array)DB::table('tax_quotation_declaration_versions')->where('id',$id)->first();unset($header['id']);$header['code']='empty';$header['jurisdiction']='empty';
        $empty=DB::table('tax_quotation_declaration_versions')->insertGetId($header);
        $activate=fn()=>DB::table('tax_quotation_declaration_activations')->insert(['company_id'=>1,'declaration_version_id'=>$empty,'activated_by'=>$this->actor->id,'reason'=>'fixture']);$this->refuses($activate);
        $different=$this->coverage('other');$row=(array)DB::table('tax_quotation_declaration_rules')->where('declaration_version_id',$id)->first();unset($row['id']);
        $row['declaration_version_id']=$empty;$row['group_version_id']=$different['group_version_id'];$row['component_id']=$different['component_id'];$row['rule_version_id']=$different['rule_version_id'];DB::table('tax_quotation_declaration_rules')->insert($row);$this->refuses($activate);
    }
    public function test_raw_tenant_fks_and_update_delete_truncate_refuse_and_activation_freezes_children(): void
    {
        $rule=$this->coverage();$id=$this->declaration([$rule]);$other=DB::table('companies')->insertGetId(['name'=>'Other fixture','created_at'=>now(),'updated_at'=>now()]);
        $row=(array)DB::table('tax_quotation_declaration_rules')->first();unset($row['id']);$row['company_id']=$other;$this->refuses(fn()=>DB::table('tax_quotation_declaration_rules')->insert($row),'23503');
        $this->activateDeclaration($id);
        foreach (['tax_quotation_declaration_versions','tax_quotation_declaration_rules','tax_quotation_declaration_activations'] as $table) {
            $this->refuses(fn()=>DB::table($table)->update(['company_id'=>1]));$this->refuses(fn()=>DB::table($table)->delete());$this->refuses(fn()=>DB::statement("TRUNCATE {$table} CASCADE"));$this->assertDatabaseCount($table,1);
        }
        $row['company_id']=1;$this->refuses(fn()=>DB::table('tax_quotation_declaration_rules')->insert($row));
    }
    public function test_protected_evidence_is_not_serialized_or_logged_and_populated_down_preserves_it(): void
    {
        $id=$this->declaration([$this->coverage()]);$this->activateDeclaration($id);
        foreach ([TaxQuotationDeclarationVersion::firstOrFail(),TaxQuotationDeclarationRule::firstOrFail(),TaxQuotationDeclarationActivation::firstOrFail()] as $model) { $this->assertSame([],$model->toArray());$this->assertStringNotContainsString('protected-',print_r($model,true)); }
        $this->assertSame(0,DB::table('activity_log')->whereRaw('properties::text LIKE ?',['%protected-%'])->count());$migration=require database_path('migrations/2026_10_29_090000_create_tax_quotation_declarations.php');
        $this->assertThrows(fn()=>DB::transaction(fn()=>$migration->down()),\RuntimeException::class);$this->assertDatabaseCount('tax_quotation_declaration_versions',1);
    }
    public function test_independent_raw_activation_waits_for_generation_fence(): void
    {
        $id=$this->declaration([$this->coverage()]);$config=config('database.connections.testing');$pdo=new \PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",$config['username'],$config['password']);$pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);$pdo->exec("SET lock_timeout='100ms'");
        try { $q=$pdo->prepare('INSERT INTO tax_quotation_declaration_activations(company_id,declaration_version_id,activated_by,reason) VALUES (1,?,?,?)');$q->execute([$id,$this->actor->id,'contender']);$this->fail('Contender passed the generation fence.'); }
        catch (\PDOException $e) { $this->assertSame('55P03',$e->getCode()); }
    }
}
