<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReturnPolicyRequest;
use App\Http\Requests\UpdateReturnPolicyRequest;
use App\Http\Resources\ReturnPolicyResource;
use App\Models\ReturnPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReturnPolicyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ReturnPolicy::query()->latest();

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $policies = $query->paginate($request->input('per_page', 15));

        return ReturnPolicyResource::collection($policies);
    }

    public function store(StoreReturnPolicyRequest $request): ReturnPolicyResource
    {
        $policy = ReturnPolicy::create($request->validated());

        if ($request->boolean('is_default')) {
            $policy->setAsDefault();
        }

        return new ReturnPolicyResource($policy);
    }

    public function show(ReturnPolicy $returnPolicy): ReturnPolicyResource
    {
        return new ReturnPolicyResource($returnPolicy);
    }

    public function update(UpdateReturnPolicyRequest $request, ReturnPolicy $returnPolicy): ReturnPolicyResource
    {
        $returnPolicy->update($request->validated());

        if ($request->has('is_default') && $request->boolean('is_default')) {
            $returnPolicy->setAsDefault();
        }

        return new ReturnPolicyResource($returnPolicy->fresh());
    }

    public function destroy(ReturnPolicy $returnPolicy): JsonResponse
    {
        if ($returnPolicy->is_default) {
            return response()->json([
                'message' => 'Cannot delete the default return policy.',
            ], 422);
        }

        if ($returnPolicy->products()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a return policy that is assigned to products.',
            ], 422);
        }

        $returnPolicy->delete();

        return response()->json(['message' => 'Return policy deleted successfully.']);
    }

    public function setDefault(ReturnPolicy $returnPolicy): ReturnPolicyResource
    {
        $returnPolicy->setAsDefault();

        return new ReturnPolicyResource($returnPolicy->fresh());
    }
}
