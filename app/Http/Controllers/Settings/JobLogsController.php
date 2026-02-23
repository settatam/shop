<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Queue\JobLogger;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JobLogsController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(Request $request): Response
    {
        $store = $this->storeContext->getCurrentStore();

        return Inertia::render('settings/JobLogs', [
            'logs' => Inertia::defer(fn () => $this->getLogs($request, $store?->id)),
            'stats' => Inertia::defer(fn () => JobLogger::stats($store?->id)),
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();
        $logs = $this->getLogs($request, $store?->id);

        return response()->json(['logs' => $logs]);
    }

    public function stats(): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        return response()->json(['stats' => JobLogger::stats($store?->id)]);
    }

    public function show(string $jobId): JsonResponse
    {
        $log = JobLogger::get($jobId);

        if (! $log) {
            return response()->json(['error' => 'Job log not found'], 404);
        }

        return response()->json(['log' => $log]);
    }

    protected function getLogs(Request $request, ?int $storeId): array
    {
        $limit = min((int) $request->get('limit', 50), 100);
        $status = $request->get('status');

        return JobLogger::recent($limit, $storeId, $status);
    }
}
