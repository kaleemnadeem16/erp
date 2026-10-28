<?php

declare(strict_types=1);
namespace App\Modules\Tax\Actions;
use App\Models\User;
use App\Modules\Tax\Models\TaxQuotationDeclarationVersion;
use App\Modules\Tax\Models\TaxQuotationDeclarationRule;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\TaxMutation;
use App\Modules\Tax\Support\TaxRefusal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

/** Entire immutable declaration/coverage in one atomic, generation-fenced operation; no automatic activation. */
final class CreateTaxQuotationDeclarationSuccessor
{
    public function __construct(private readonly TaxMutation $mutation) {}
    /** @param list<array<string, int|string|bool|null>> $rules */
    public function __invoke(User $actor, string $code, string $jurisdiction, ?int $predecessorId, EffectivePeriod $period,
        string $roundingMode, array $rules, string $reason, string $operationKey, int $expectedGeneration): int
    {
        TaxAccess::requireConfiguration($actor);
        if ($rules===[] || !array_is_list($rules)) throw TaxRefusal::because('TAX_DECLARATION_INVALID','Invalid quotation tax declaration.');
        foreach ($rules as &$rule) {
            if (array_diff(array_keys($rule), ['nature','classification','group_version_id','component_id','rule_version_id','disposition','requires_party_profile','grounds']) !== []) {
                throw TaxRefusal::because('TAX_DECLARATION_INVALID','Invalid quotation tax declaration.');
            }
            $rule += ['grounds'=>null]; ksort($rule);
        }
        unset($rule);
        usort($rules, static fn(array $a,array $b): int => [$a['nature']??'', $a['classification']??'', $a['component_id']??0] <=> [$b['nature']??'', $b['classification']??'', $b['component_id']??0]);
        $intent=['operation'=>'create_quotation_declaration','actor'=>$actor->id,'code'=>$code,'jurisdiction'=>$jurisdiction,'predecessor'=>$predecessorId,
            'from'=>$period->from,'to'=>$period->endExclusive,'rounding_mode'=>$roundingMode,'rules'=>$rules,'reason'=>$reason];
        try {
            $result=$this->mutation->run($operationKey,$expectedGeneration,$intent, static function() use ($actor,$code,$jurisdiction,$predecessorId,$period,$roundingMode,$rules,$reason): array {
                $previous=$predecessorId===null ? null : TaxQuotationDeclarationVersion::query()->findOrFail($predecessorId);
                $version=TaxQuotationDeclarationVersion::create(['code'=>$code,'jurisdiction'=>$jurisdiction,'version_no'=>$previous===null ? 1 : (int)$previous->getAttribute('version_no')+1,
                    'predecessor_id'=>$predecessorId,'effective_from'=>$period->from,'effective_to'=>$period->endExclusive,'tax_mode'=>'BASIC','calculation_version'=>'FR3.1',
                    'settlement_basis'=>'LINE_COMPONENT','rounding_mode'=>$roundingMode,'created_by'=>$actor->id,'reason'=>$reason]);
                foreach ($rules as $rule) TaxQuotationDeclarationRule::create($rule+['declaration_version_id'=>$version->getKey(),'created_by'=>$actor->id]);
                return ['declaration_version_id'=>(int)$version->getKey()];
            });
        } catch (QueryException|ModelNotFoundException) {
            throw TaxRefusal::because('TAX_DECLARATION_INVALID','Invalid quotation tax declaration.');
        }
        return $result['declaration_version_id'];
    }
}
