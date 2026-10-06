<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CustomFieldDefinition;
use App\Modules\Administration\Actions\CreateCustomField;
use App\Modules\Administration\Http\Requests\StoreCustomFieldRequest;
use App\Modules\Administration\Http\Resources\CustomFieldDefinitionResource;
use App\Modules\Administration\Support\CustomFieldEntities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CustomFieldDefinitionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['entity_type' => ['required', Rule::in(CustomFieldEntities::TYPES)]]);
        abort_unless(CustomFieldEntities::canRead($request->user(), $request->string('entity_type')->toString()), 403);

        return CustomFieldDefinitionResource::collection(CustomFieldDefinition::query()->forEntityType($request->string('entity_type')->toString())->get());
    }

    public function store(StoreCustomFieldRequest $request, CreateCustomField $create): JsonResponse
    {
        return (new CustomFieldDefinitionResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }
}
