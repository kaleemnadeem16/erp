<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Modules\Sales\Actions\ActivateDiscountAuthorityPolicy;
use App\Modules\Sales\Actions\ApproveSalesPriceReference;
use App\Modules\Sales\Actions\AssignDiscountAuthority;
use App\Modules\Sales\Actions\CreateDiscountAuthorityPolicy;
use App\Modules\Sales\Actions\ProposeSalesPriceReference;
use App\Modules\Sales\Actions\ReadDiscountAuthorityConfiguration;
use App\Modules\Sales\Actions\RevokeDiscountAuthority;
use App\Modules\Sales\Actions\SetDiscountAuthorityMode;
use App\Modules\Sales\Http\Requests\ApproveSalesPriceReferenceRequest;
use App\Modules\Sales\Http\Requests\AssignDiscountAuthorityRequest;
use App\Modules\Sales\Http\Requests\ChangeDiscountAuthorityModeRequest;
use App\Modules\Sales\Http\Requests\ChangeDiscountAuthorityRequest;
use App\Modules\Sales\Http\Requests\CreateDiscountAuthorityPolicyRequest;
use App\Modules\Sales\Http\Requests\ProposeSalesPriceReferenceRequest;
use App\Modules\Sales\Http\Requests\ReadDiscountAuthorityConfigurationRequest;
use App\Modules\Sales\Http\Resources\DiscountAuthorityChangeResource;
use App\Modules\Sales\Http\Resources\DiscountAuthorityConfigurationResource;
use Dedoc\Scramble\Attributes\HeaderParameter;

/** Disabled until independently authorised holders are rolled out. Writes use Idempotency-Key and expected_generation. */
class DiscountAuthorityController extends AuthorityApiController
{
    public function index(ReadDiscountAuthorityConfigurationRequest $request, string $collection, ReadDiscountAuthorityConfiguration $action): DiscountAuthorityConfigurationResource
    {
        return new DiscountAuthorityConfigurationResource($action($request->user(), $collection, null, $request->integer('page', 1), $request->integer('per_page', 25)));
    }

    public function show(ReadDiscountAuthorityConfigurationRequest $request, string $collection, int $id, ReadDiscountAuthorityConfiguration $action): DiscountAuthorityConfigurationResource
    {
        return new DiscountAuthorityConfigurationResource($action($request->user(), $collection, $id));
    }

    #[HeaderParameter('Idempotency-Key', 'One key per exact operation; 1 to 85 characters.', required: true, type: 'string')]
    public function proposeReference(ProposeSalesPriceReferenceRequest $request, ProposeSalesPriceReference $action): DiscountAuthorityChangeResource
    {
        return new DiscountAuthorityChangeResource(['id' => $action($request->user(), (string) $request->header('Idempotency-Key'), $request->integer('expected_generation'), $request->safe()->except(['request_key', 'expected_generation']))->id]);
    }

    #[HeaderParameter('Idempotency-Key', 'One key per exact operation; 1 to 85 characters.', required: true, type: 'string')]
    public function approveReference(ApproveSalesPriceReferenceRequest $request, int $reference, ApproveSalesPriceReference $action): DiscountAuthorityChangeResource
    {
        return new DiscountAuthorityChangeResource(['id' => $action($request->user(), $reference, (string) $request->header('Idempotency-Key'), $request->integer('expected_generation'), $request->string('reason')->toString())->id]);
    }

    #[HeaderParameter('Idempotency-Key', 'One key per exact operation; 1 to 85 characters.', required: true, type: 'string')]
    public function proposePolicy(CreateDiscountAuthorityPolicyRequest $request, CreateDiscountAuthorityPolicy $action): DiscountAuthorityChangeResource
    {
        return new DiscountAuthorityChangeResource(['id' => $action($request->user(), (string) $request->header('Idempotency-Key'), $request->integer('expected_generation'), $request->safe()->except(['request_key', 'expected_generation']))->id]);
    }

    #[HeaderParameter('Idempotency-Key', 'One key per exact operation; 1 to 85 characters.', required: true, type: 'string')]
    public function activatePolicy(ChangeDiscountAuthorityRequest $request, int $policy, ActivateDiscountAuthorityPolicy $action): DiscountAuthorityChangeResource
    {
        return new DiscountAuthorityChangeResource(['id' => $action($request->user(), $policy, (string) $request->header('Idempotency-Key'), $request->integer('expected_generation'), $request->string('reason')->toString())->id]);
    }

    #[HeaderParameter('Idempotency-Key', 'One key per exact operation; 1 to 85 characters.', required: true, type: 'string')]
    public function assign(AssignDiscountAuthorityRequest $request, AssignDiscountAuthority $action): DiscountAuthorityChangeResource
    {
        return new DiscountAuthorityChangeResource(['id' => $action($request->user(), (string) $request->header('Idempotency-Key'), $request->integer('expected_generation'), $request->safe()->except(['request_key', 'expected_generation']))->id]);
    }

    #[HeaderParameter('Idempotency-Key', 'One key per exact operation; 1 to 85 characters.', required: true, type: 'string')]
    public function revoke(ChangeDiscountAuthorityRequest $request, int $assignment, RevokeDiscountAuthority $action): DiscountAuthorityChangeResource
    {
        return new DiscountAuthorityChangeResource($action($request->user(), $assignment, (string) $request->header('Idempotency-Key'), $request->integer('expected_generation'), $request->string('reason')->toString()));
    }

    #[HeaderParameter('Idempotency-Key', 'One key per exact operation; 1 to 85 characters.', required: true, type: 'string')]
    public function mode(ChangeDiscountAuthorityModeRequest $request, SetDiscountAuthorityMode $action): DiscountAuthorityChangeResource
    {
        return new DiscountAuthorityChangeResource($action($request->user(), $request->string('mode')->toString(), (string) $request->header('Idempotency-Key'), $request->integer('expected_generation'), $request->string('reason')->toString()));
    }
}
