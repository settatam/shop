<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCreditCashOutRequest;
use App\Models\Customer;
use App\Models\StoreCredit;
use App\Services\Credits\StoreCreditService;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
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
            'customer' => $customer->only('id', 'first_name', 'last_name', 'full_name', 'email', 'store_credit_balance'),
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
        );

        return back()->with('success', 'Store credit cashed out successfully.');
    }
}
