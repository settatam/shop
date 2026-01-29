<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Search\GlobalSearchService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        protected GlobalSearchService $searchService,
        protected StoreContext $storeContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $query = $request->input('q');
        $limit = $request->input('limit', 5);
        $storeId = $this->storeContext->getCurrentStoreId();

        $results = $this->searchService->search($query, $storeId, $limit);

        return response()->json([
            'results' => $results,
            'total' => $this->searchService->getTotalCount($results),
        ]);
    }
}
