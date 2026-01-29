<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\Transactions\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Transaction::query()
            ->with(['customer', 'user', 'items'])
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('transaction_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $transactions = $query->paginate($request->input('per_page', 15));

        return TransactionResource::collection($transactions);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $this->transactionService->create($request->validated());

        return (new TransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        $transaction->load(['customer', 'user', 'items.category']);

        return new TransactionResource($transaction);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): TransactionResource
    {
        $transaction->update($request->validated());

        return new TransactionResource($transaction->fresh(['customer', 'user', 'items']));
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        $transaction->delete();

        return response()->json(['message' => 'Transaction deleted successfully.']);
    }

    public function addItem(Request $request, Transaction $transaction): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'buy_price' => ['nullable', 'numeric', 'min:0'],
            'dwt' => ['nullable', 'numeric', 'min:0'],
            'precious_metal' => ['nullable', 'string', 'max:50'],
            'condition' => ['nullable', 'string', 'max:50'],
        ]);

        $item = $this->transactionService->addItem($transaction, $validated);

        return response()->json([
            'message' => 'Item added successfully.',
            'item' => $item,
        ]);
    }

    public function updateItem(Request $request, Transaction $transaction, TransactionItem $item): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'buy_price' => ['nullable', 'numeric', 'min:0'],
            'dwt' => ['nullable', 'numeric', 'min:0'],
            'precious_metal' => ['nullable', 'string', 'max:50'],
            'condition' => ['nullable', 'string', 'max:50'],
        ]);

        $item = $this->transactionService->updateItem($item, $validated);

        return response()->json([
            'message' => 'Item updated successfully.',
            'item' => $item,
        ]);
    }

    public function removeItem(Transaction $transaction, TransactionItem $item): JsonResponse
    {
        $this->transactionService->removeItem($item);

        return response()->json(['message' => 'Item removed successfully.']);
    }

    public function submitOffer(Request $request, Transaction $transaction): TransactionResource
    {
        $validated = $request->validate([
            'offer' => ['required', 'numeric', 'min:0'],
        ]);

        $transaction = $this->transactionService->submitOffer($transaction, $validated['offer']);

        return new TransactionResource($transaction->load(['customer', 'user', 'items']));
    }

    public function acceptOffer(Transaction $transaction): TransactionResource
    {
        $transaction = $this->transactionService->acceptOffer($transaction);

        return new TransactionResource($transaction->load(['customer', 'user', 'items']));
    }

    public function declineOffer(Request $request, Transaction $transaction): TransactionResource
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $transaction = $this->transactionService->declineOffer($transaction, $validated['reason'] ?? null);

        return new TransactionResource($transaction->load(['customer', 'user', 'items']));
    }

    public function processPayment(Request $request, Transaction $transaction): TransactionResource
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string'],
        ]);

        $transaction = $this->transactionService->processPayment($transaction, $validated['payment_method']);

        return new TransactionResource($transaction->load(['customer', 'user', 'items']));
    }

    public function moveToInventory(Request $request, Transaction $transaction, TransactionItem $item): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = $this->transactionService->moveItemToInventory($item, $validated);

        return response()->json([
            'message' => 'Item moved to inventory successfully.',
            'product_id' => $product->id,
        ]);
    }
}
