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

        $searchResults = $this->searchService->search($query, $storeId, $limit);

        // Format results for the frontend
        $results = [];
        foreach ($searchResults as $type => $data) {
            $results[$type] = [
                'items' => $data['items'],
                'total' => $data['total'],
                'view_all_url' => $data['total'] > $limit
                    ? $this->searchService->getViewAllUrl($type, $query)
                    : null,
            ];
        }

        return response()->json([
            'results' => $results,
            'total' => $this->searchService->getTotalCount($searchResults),
        ]);
    }
}
