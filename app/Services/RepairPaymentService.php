<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Repair;
use Illuminate\Support\Facades\DB;

class RepairPaymentService
{
    /**
     * Complete the repair payment workflow: create order, invoice, and update status.
     *
     * @return array{order: Order, invoice: Invoice, repair: Repair}
     */
    public function completeRepairPayment(Repair $repair): array
    {
        return DB::transaction(function () use ($repair) {
            $repair = $repair->fresh(['items', 'customer']);

            // Create the order (sale)
            $order = $this->createOrderFromRepair($repair);

            // Create the invoice
            $invoice = $this->createInvoiceFromRepair($repair, $order);

            // Link payments to invoice
            $repair->payments()->update(['invoice_id' => $invoice->id]);

            // Update repair status
            $repair->markPaymentReceived();

            // Link order to repair
            $repair->update(['order_id' => $order->id]);

            return [
                'order' => $order,
                'invoice' => $invoice,
                'repair' => $repair->fresh(),
            ];
        });
    }

    /**
     * Create an order (sale) from a repair.
     */
    protected function createOrderFromRepair(Repair $repair): Order
    {
        $order = Order::create([
            'store_id' => $repair->store_id,
            'customer_id' => $repair->customer_id,
            'user_id' => $repair->user_id,
            'invoice_number' => 'REP-TEMP', // Temporary, will be updated with order ID
            'sub_total' => $repair->subtotal,
            'sales_tax' => $repair->tax ?? 0,
            'shipping_cost' => $repair->shipping_cost ?? 0,
            'discount_cost' => $repair->discount ?? 0,
            'total' => $repair->grand_total,
            'status' => Order::STATUS_COMPLETED,
            'source_platform' => 'repair',
            'date_of_purchase' => now(),
            'notes' => "Created from Repair #{$repair->repair_number}",
        ]);

        // Update invoice number with REP-<order.id> format
        $order->update(['invoice_number' => "REP-{$order->id}"]);

        // Create order items from repair items
        foreach ($repair->items as $repairItem) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $repairItem->product_id,
                'title' => $repairItem->title ?? $repairItem->product?->title ?? 'Repair Service',
                'sku' => $repairItem->sku,
                'quantity' => 1,
                'cost' => $repairItem->vendor_cost,    // What vendor charges us
                'price' => $repairItem->customer_cost, // What customer pays
            ]);
        }

        // Add service fee as a line item if present
        if ($repair->service_fee > 0) {
            OrderItem::create([
                'order_id' => $order->id,
                'title' => 'Service Fee',
                'sku' => 'SERVICE-FEE',
                'quantity' => 1,
                'cost' => 0,                      // No cost for service fee
                'price' => $repair->service_fee,  // Pure profit
            ]);
        }

        return $order;
    }

    /**
     * Create an invoice from a repair.
     */
    protected function createInvoiceFromRepair(Repair $repair, Order $order): Invoice
    {
        return Invoice::create([
            'store_id' => $repair->store_id,
            'user_id' => $repair->user_id,
            'invoiceable_type' => Repair::class,
            'invoiceable_id' => $repair->id,
            'subtotal' => $repair->subtotal,
            'tax' => $repair->tax ?? 0,
            'shipping' => $repair->shipping_cost ?? 0,
            'discount' => $repair->discount ?? 0,
            'total' => $repair->grand_total,
            'total_paid' => $repair->total_paid,
            'balance_due' => 0,
            'status' => Invoice::STATUS_PAID,
            'currency' => 'USD',
            'paid_at' => now(),
            'notes' => "Invoice for Repair #{$repair->repair_number}",
        ]);
    }
}
