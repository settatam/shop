<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Status;
use App\Models\Store;
use App\Models\Transaction;
use App\Services\StoreContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leads Dashboard Controller
 *
 * Displays all transaction statuses with counts for stores with online buys workflow.
 * Leads are tracked until payment is processed, then they become "buys".
 */
class LeadsDashboardController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Display the leads dashboard - a list of all statuses with counts.
     */
    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || ! $store->hasOnlineBuysWorkflow()) {
            abort(403, 'This feature is not available for your store.');
        }

        return Inertia::render('leads/Dashboard', [
            'statusCounts' => $this->getStatusCounts($store),
            'summary' => $this->getSummary($store),
        ]);
    }

    /**
     * Display leads filtered by status.
     */
    public function byStatus(Request $request, string $status): Response
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || ! $store->hasOnlineBuysWorkflow()) {
            abort(403, 'This feature is not available for your store.');
        }

        // Find the status model
        $statusModel = Status::findBySlug($store->id, 'transaction', $status);

        $query = Transaction::query()
            ->where('store_id', $store->id)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->with(['customer', 'statusModel', 'items'])
            ->orderByDesc('created_at');

        // Filter by status (support both status_id and legacy status slug)
        if ($statusModel) {
            $query->where(function ($q) use ($statusModel, $status) {
                $q->where('status_id', $statusModel->id)
                    ->orWhere('status', $status);
            });
        } else {
            $query->where('status', $status);
        }

        $leads = $query->paginate(25);

        return Inertia::render('leads/Index', [
            'leads' => TransactionResource::collection($leads),
            'currentStatus' => $statusModel ? [
                'id' => $statusModel->id,
                'name' => $statusModel->name,
                'slug' => $statusModel->slug,
                'color' => $statusModel->color,
            ] : [
                'name' => ucfirst(str_replace('_', ' ', $status)),
                'slug' => $status,
            ],
            'statusCounts' => $this->getStatusCounts($store),
        ]);
    }

    /**
     * Display a single lead detail page.
     */
    public function show(Transaction $transaction): Response
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || ! $store->hasOnlineBuysWorkflow()) {
            abort(403, 'This feature is not available for your store.');
        }

        // Ensure the transaction belongs to this store
        if ($transaction->store_id !== $store->id) {
            abort(404);
        }

        // Load relationships
        $transaction->load([
            'customer',
            'statusModel',
            'items.images',
            'items.category',
            'offers',
        ]);

        // Get available transitions
        $availableTransitions = [];
        if ($transaction->statusModel) {
            $availableTransitions = $transaction->statusModel
                ->getAvailableTargetStatuses()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'color' => $s->color,
                ])
                ->toArray();
        }

        return Inertia::render('leads/Show', [
            'lead' => new TransactionResource($transaction),
            'availableTransitions' => $availableTransitions,
            'statusHistory' => [],
        ]);
    }

    /**
     * Get counts for all transaction statuses.
     *
     * @return array<int, array{id: int|null, name: string, slug: string, count: int, color: string|null, is_final: bool}>
     */
    protected function getStatusCounts(Store $store): array
    {
        // Get all statuses for transactions in this store
        $statuses = Status::query()
            ->where('store_id', $store->id)
            ->where('entity_type', 'transaction')
            ->orderBy('sort_order')
            ->get();

        // Get counts by status_id
        $countsById = Transaction::query()
            ->where('store_id', $store->id)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->whereNotNull('status_id')
            ->selectRaw('status_id, COUNT(*) as count')
            ->groupBy('status_id')
            ->pluck('count', 'status_id')
            ->toArray();

        // Get counts by legacy status slug (for records without status_id)
        $countsBySlug = Transaction::query()
            ->where('store_id', $store->id)
            ->where('type', Transaction::TYPE_MAIL_IN)
            ->whereNull('status_id')
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return $statuses->map(function (Status $status) use ($countsById, $countsBySlug) {
            $count = ($countsById[$status->id] ?? 0) + ($countsBySlug[$status->slug] ?? 0);

            return [
                'id' => $status->id,
                'name' => $status->name,
                'slug' => $status->slug,
                'color' => $status->color,
                'is_final' => $status->is_final,
                'count' => $count,
            ];
        })->toArray();
    }

    /**
     * Get summary stats for the dashboard.
     *
     * @return array<string, mixed>
     */
    protected function getSummary(Store $store): array
    {
        $baseQuery = fn () => Transaction::query()
            ->where('store_id', $store->id)
            ->where('type', Transaction::TYPE_MAIL_IN);

        // Total active leads (not in final status)
        $finalStatuses = ['payment_processed', 'cancelled', 'items_returned', 'kit_request_rejected'];
        $activeLeads = $baseQuery()
            ->whereNotIn('status', $finalStatuses)
            ->count();

        // Converted to buys (all time)
        $totalConverted = $baseQuery()
            ->where('status', 'payment_processed')
            ->count();

        // Converted value
        $totalConvertedValue = (float) $baseQuery()
            ->where('status', 'payment_processed')
            ->sum('final_offer');

        // Potential value (active leads)
        $potentialValue = (float) $baseQuery()
            ->whereNotIn('status', $finalStatuses)
            ->sum('estimated_value');

        return [
            'active_leads' => $activeLeads,
            'total_converted' => $totalConverted,
            'total_converted_value' => $totalConvertedValue,
            'potential_value' => $potentialValue,
        ];
    }
}
