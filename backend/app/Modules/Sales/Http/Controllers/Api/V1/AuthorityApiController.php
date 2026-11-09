<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers\Api\V1;

use App\Exceptions\DomainInvariantException;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\QuotationRevision;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class AuthorityApiController extends Controller
{
    /** Dispatch boundary: exact-intent key conflicts use the existing 409 API convention.
     * @param  array<string, mixed>  $parameters
     */
    public function callAction(string $method, array $parameters): mixed
    {
        try {
            foreach (['id', 'reference', 'policy', 'assignment', 'revision'] as $key) {
                if (isset($parameters[$key])) {
                    $parameters[$key] = (int) $parameters[$key];
                }
            }

            return $this->{$method}(...array_values($parameters));
        } catch (DomainInvariantException $e) {
            if ($e->reasonCode === 'QUOTATION_NOT_FOUND') {
                throw (new ModelNotFoundException)->setModel(QuotationRevision::class);
            }
            if ($e->reasonCode === 'REQUEST_KEY_REUSED') {
                throw new HttpResponseException(response()->json(['message' => $e->getMessage(), 'reason_code' => $e->reasonCode, 'errors' => [($e->field ?? 'id') => [$e->getMessage()]]], 409));
            }
            throw $e;
        }
    }
}
