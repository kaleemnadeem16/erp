<?php

declare(strict_types=1);
namespace App\Modules\Tax\Actions;
use App\Models\User;
use App\Modules\Tax\Models\TaxQuotationDeclarationVersion;
use App\Modules\Tax\Models\TaxQuotationDeclarationActivation;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\TaxMutation;
use App\Modules\Tax\Support\TaxRefusal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
final class ActivateTaxQuotationDeclaration
{
    public function __construct(private readonly TaxMutation $mutation) {}
    public function __invoke(User $actor,int $declarationId,string $reason,string $operationKey,int $expectedGeneration): int
    {
        TaxAccess::requireConfiguration($actor);
        try {
            $result=$this->mutation->run($operationKey,$expectedGeneration,['operation'=>'activate_quotation_declaration','actor'=>$actor->id,'declaration'=>$declarationId,'reason'=>$reason], static function() use ($actor,$declarationId,$reason): array {
                TaxQuotationDeclarationVersion::query()->findOrFail($declarationId);
                $row=TaxQuotationDeclarationActivation::create(['declaration_version_id'=>$declarationId,'activated_by'=>$actor->id,'reason'=>$reason]);
                return ['activation_id'=>(int)$row->getKey()];
            });
        } catch (QueryException|ModelNotFoundException) {
            throw TaxRefusal::because('TAX_DECLARATION_INVALID','Invalid quotation tax declaration.');
        }
        return $result['activation_id'];
    }
}
