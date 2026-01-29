<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #1f2937;
        }

        .container {
            padding: 40px;
        }

        .header {
            display: table;
            width: 100%;
            margin-bottom: 40px;
        }

        .header-left {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .header-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }

        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 8px;
        }

        .company-details {
            color: #6b7280;
            font-size: 11px;
        }

        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 8px;
        }

        .invoice-number {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .invoice-date {
            font-size: 11px;
            color: #6b7280;
        }

        .addresses {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }

        .bill-to {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .invoice-details {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }

        .section-title {
            font-size: 10px;
            font-weight: bold;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .customer-name {
            font-weight: bold;
            color: #111827;
            margin-bottom: 4px;
        }

        .customer-details {
            color: #6b7280;
            font-size: 11px;
        }

        .detail-row {
            margin-bottom: 4px;
        }

        .detail-label {
            color: #6b7280;
            font-size: 11px;
        }

        .detail-value {
            font-weight: 600;
            color: #111827;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-paid {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-partial {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-overdue {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .status-void {
            background-color: #f3f4f6;
            color: #6b7280;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        table.items th {
            background-color: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
            padding: 12px;
            text-align: left;
            font-size: 10px;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
        }

        table.items th.text-right {
            text-align: right;
        }

        table.items td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        table.items td.text-right {
            text-align: right;
        }

        .item-description {
            color: #111827;
        }

        .totals {
            width: 100%;
            margin-bottom: 30px;
        }

        .totals-table {
            width: 300px;
            margin-left: auto;
        }

        .totals-row {
            display: table;
            width: 100%;
            padding: 8px 0;
        }

        .totals-label {
            display: table-cell;
            width: 60%;
            color: #6b7280;
            text-align: right;
            padding-right: 20px;
        }

        .totals-value {
            display: table-cell;
            width: 40%;
            text-align: right;
            color: #111827;
        }

        .totals-row.total {
            border-top: 2px solid #e5e7eb;
            margin-top: 8px;
            padding-top: 12px;
        }

        .totals-row.total .totals-label,
        .totals-row.total .totals-value {
            font-weight: bold;
            font-size: 14px;
        }

        .totals-row.balance-due {
            background-color: #f9fafb;
            padding: 12px;
            margin-top: 8px;
        }

        .totals-row.balance-due .totals-label,
        .totals-row.balance-due .totals-value {
            font-weight: bold;
            font-size: 16px;
            color: #111827;
        }

        .payments-section {
            margin-bottom: 30px;
        }

        .payments-title {
            font-size: 14px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 12px;
        }

        table.payments {
            width: 100%;
            border-collapse: collapse;
        }

        table.payments th {
            background-color: #f9fafb;
            padding: 8px 12px;
            text-align: left;
            font-size: 10px;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
        }

        table.payments td {
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
        }

        .notes {
            margin-top: 30px;
            padding: 16px;
            background-color: #f9fafb;
            border-radius: 4px;
        }

        .notes-title {
            font-weight: bold;
            color: #111827;
            margin-bottom: 8px;
        }

        .notes-content {
            color: #6b7280;
            font-size: 11px;
        }

        .footer {
            margin-top: 40px;
            text-align: center;
            color: #9ca3af;
            font-size: 10px;
        }

        .reference {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .reference-title {
            font-size: 11px;
            font-weight: bold;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .reference-value {
            font-size: 12px;
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="company-name">{{ $store['name'] }}</div>
                <div class="company-details">
                    @if($store['address'])
                        {{ $store['address'] }}<br>
                    @endif
                    @if($store['address2'])
                        {{ $store['address2'] }}<br>
                    @endif
                    @if($store['city'] || $store['state'] || $store['zip'])
                        {{ $store['city'] }}@if($store['city'] && $store['state']),@endif
                        {{ $store['state'] }} {{ $store['zip'] }}<br>
                    @endif
                    @if($store['phone'])
                        {{ $store['phone'] }}<br>
                    @endif
                    @if($store['email'])
                        {{ $store['email'] }}
                    @endif
                </div>
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">#{{ $invoice->invoice_number }}</div>
                <div class="invoice-date">
                    Issued: {{ $invoice->created_at->format('F j, Y') }}
                </div>
            </div>
        </div>

        <div class="addresses">
            <div class="bill-to">
                <div class="section-title">Bill To</div>
                @if($customer)
                    <div class="customer-name">{{ $customer['name'] }}</div>
                    <div class="customer-details">
                        @if($customer['address'])
                            {{ $customer['address'] }}<br>
                        @endif
                        @if($customer['address2'])
                            {{ $customer['address2'] }}<br>
                        @endif
                        @if($customer['city'] || $customer['zip'])
                            {{ $customer['city'] }} {{ $customer['zip'] }}<br>
                        @endif
                        @if($customer['email'])
                            {{ $customer['email'] }}<br>
                        @endif
                        @if($customer['phone'])
                            {{ $customer['phone'] }}
                        @endif
                    </div>
                @else
                    <div class="customer-details">No customer information</div>
                @endif
            </div>
            <div class="invoice-details">
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="status-badge status-{{ $invoice->status }}">
                        {{ ucfirst($invoice->status) }}
                    </span>
                </div>
                @if($invoice->due_date)
                    <div class="detail-row">
                        <span class="detail-label">Due Date:</span>
                        <span class="detail-value">{{ $invoice->due_date->format('F j, Y') }}</span>
                    </div>
                @endif
                <div class="detail-row">
                    <span class="detail-label">Reference:</span>
                    <span class="detail-value">{{ $invoiceableType }}</span>
                </div>
            </div>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 50%;">Description</th>
                    <th class="text-right" style="width: 15%;">Quantity</th>
                    <th class="text-right" style="width: 17.5%;">Unit Price</th>
                    <th class="text-right" style="width: 17.5%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lineItems as $item)
                    <tr>
                        <td class="item-description">{{ $item['description'] }}</td>
                        <td class="text-right">{{ $item['quantity'] }}</td>
                        <td class="text-right">${{ number_format($item['unit_price'], 2) }}</td>
                        <td class="text-right">${{ number_format($item['total'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="text-align: center; color: #9ca3af; padding: 20px;">
                            No line items available
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="totals">
            <div class="totals-table">
                <div class="totals-row">
                    <div class="totals-label">Subtotal</div>
                    <div class="totals-value">${{ number_format($invoice->subtotal, 2) }}</div>
                </div>
                @if($invoice->discount > 0)
                    <div class="totals-row">
                        <div class="totals-label">Discount</div>
                        <div class="totals-value">-${{ number_format($invoice->discount, 2) }}</div>
                    </div>
                @endif
                @if($invoice->tax > 0)
                    <div class="totals-row">
                        <div class="totals-label">Tax</div>
                        <div class="totals-value">${{ number_format($invoice->tax, 2) }}</div>
                    </div>
                @endif
                @if($invoice->shipping > 0)
                    <div class="totals-row">
                        <div class="totals-label">Shipping</div>
                        <div class="totals-value">${{ number_format($invoice->shipping, 2) }}</div>
                    </div>
                @endif
                @if(isset($serviceFee) && $serviceFee['amount'] > 0)
                    <div class="totals-row">
                        <div class="totals-label">Service Fee{{ $serviceFee['reason'] ? ' ('.$serviceFee['reason'].')' : '' }}</div>
                        <div class="totals-value">${{ number_format($serviceFee['amount'], 2) }}</div>
                    </div>
                @endif
                <div class="totals-row total">
                    <div class="totals-label">Total</div>
                    <div class="totals-value">${{ number_format($invoice->total, 2) }}</div>
                </div>
                @if($invoice->total_paid > 0)
                    <div class="totals-row">
                        <div class="totals-label">Amount Paid</div>
                        <div class="totals-value">-${{ number_format($invoice->total_paid, 2) }}</div>
                    </div>
                @endif
                <div class="totals-row balance-due">
                    <div class="totals-label">Balance Due</div>
                    <div class="totals-value">${{ number_format($invoice->balance_due, 2) }}</div>
                </div>
            </div>
        </div>

        @if($payments->count() > 0)
            <div class="payments-section">
                <div class="payments-title">Payment History</div>
                <table class="payments">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($payments as $payment)
                            <tr>
                                <td>{{ $payment->created_at->format('M j, Y') }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}</td>
                                <td>{{ $payment->reference ?? '-' }}</td>
                                <td style="text-align: right;">${{ number_format($payment->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($invoice->notes)
            <div class="notes">
                <div class="notes-title">Notes</div>
                <div class="notes-content">{{ $invoice->notes }}</div>
            </div>
        @endif

        <div class="footer">
            Thank you for your business!
        </div>
    </div>
</body>
</html>
