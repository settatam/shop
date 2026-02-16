<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BugReport;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BugReportController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:5000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'user_agent' => ['nullable', 'string', 'max:500'],
            'screenshot' => ['nullable', 'string'], // base64 encoded image
        ]);

        $store = $this->storeContext->getCurrentStore();
        $user = $request->user();

        $screenshotPath = null;

        // Save screenshot if provided
        if (! empty($validated['screenshot'])) {
            try {
                // Extract base64 data
                $imageData = $validated['screenshot'];
                if (str_starts_with($imageData, 'data:image')) {
                    $imageData = substr($imageData, strpos($imageData, ',') + 1);
                }

                $imageData = base64_decode($imageData);

                if ($imageData !== false) {
                    $filename = 'bug-reports/'.Str::uuid().'.png';
                    Storage::disk('public')->put($filename, $imageData);
                    $screenshotPath = $filename;
                }
            } catch (\Exception $e) {
                // Silently fail if screenshot saving fails
                report($e);
            }
        }

        $bugReport = BugReport::create([
            'store_id' => $store?->id,
            'user_id' => $user?->id,
            'description' => $validated['description'],
            'url' => $validated['url'] ?? null,
            'user_agent' => $validated['user_agent'] ?? null,
            'screenshot_path' => $screenshotPath,
            'status' => 'open',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bug report submitted successfully',
            'id' => $bugReport->id,
        ], 201);
    }
}
