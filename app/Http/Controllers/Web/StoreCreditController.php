<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCreditCashOutRequest;
use App\Models\Customer;
use App\Models\StoreCredit;
use App\Services\Credits\StoreCreditService;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class StoreCreditController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected StoreCreditService $storeCreditService,
    ) {}

    public function index(Customer $customer): Response
    {
        $store = $this->storeContext->getCurrentStore();

        if ($customer->store_id !== $store->id) {
            abort(404);
        }

        $credits = StoreCredit::where('customer_id', $customer->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->paginate(25);

        return Inertia::render('customers/StoreCredits', [
            'customer' => $customer->only('id', 'first_name', 'last_name', 'full_name', 'email', 'phone_number', 'address', 'city', 'state', 'zip', 'store_credit_balance'),
            'credits' => $credits,
            'payoutMethods' => [
                ['value' => StoreCredit::PAYOUT_CASH, 'label' => 'Cash'],
                ['value' => StoreCredit::PAYOUT_CHECK, 'label' => 'Check'],
                ['value' => StoreCredit::PAYOUT_PAYPAL, 'label' => 'PayPal'],
                ['value' => StoreCredit::PAYOUT_VENMO, 'label' => 'Venmo'],
                ['value' => StoreCredit::PAYOUT_ACH, 'label' => 'ACH'],
                ['value' => StoreCredit::PAYOUT_WIRE_TRANSFER, 'label' => 'Wire Transfer'],
            ],
        ]);
    }

    public function cashOut(StoreCreditCashOutRequest $request, Customer $customer): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if ($customer->store_id !== $store->id) {
            abort(404);
        }

        $amount = (float) $request->validated('amount');

        if ($amount > (float) $customer->store_credit_balance) {
            return back()->withErrors(['amount' => 'Cash out amount exceeds available store credit balance.']);
        }

        $this->storeCreditService->cashOut(
            customer: $customer,
            amount: $amount,
            payoutMethod: $request->validated('payout_method'),
            description: $request->validated('notes'),
            payoutDetails: $request->validated('payout_details'),
        );

        return back()->with('success', 'Store credit cashed out successfully.');
    }

    public function printReceipt(Customer $customer, StoreCredit $storeCredit): Response
    {
        $store = $this->storeContext->getCurrentStore();

        if ($customer->store_id !== $store->id) {
            abort(404);
        }

        if ($storeCredit->customer_id !== $customer->id) {
            abort(404);
        }

        $storeCredit->load('user:id,name');

        return Inertia::render('customers/PrintCashOutReceipt', [
            'storeCredit' => [
                'id' => $storeCredit->id,
                'amount' => $storeCredit->amount,
                'balance_after' => $storeCredit->balance_after,
                'payout_method' => $storeCredit->payout_method,
                'payout_details' => $storeCredit->payout_details,
                'description' => $storeCredit->description,
                'created_at' => $storeCredit->created_at->toISOString(),
                'user_name' => $storeCredit->user?->name,
            ],
            'customer' => $customer->only('id', 'full_name', 'email', 'phone_number', 'address', 'city', 'state', 'zip'),
            'store' => [
                'name' => $store->name,
                'logo' => $store->logo ? Storage::disk('do_spaces')->url($store->logo) : null,
                'address' => $store->address,
                'address2' => $store->address2,
                'city' => $store->city,
                'state' => $store->state,
                'zip' => $store->zip,
                'phone' => $store->phone,
                'email' => $store->account_email,
            ],
        ]);
    }
}
