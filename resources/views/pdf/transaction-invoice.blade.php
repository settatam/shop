<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Buy Invoice {{ $transactionNumber }}</title>
@php
    $salesperson = $salesperson ?? null;
    $primaryPaymentMethod = $primaryPaymentMethod ?? 'N/A';
    $paymentModes = $paymentModes ?? [];
@endphp
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #334155;
            background: #fff;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
        }

        .header {
            padding: 12px 36px;
            display: table;
            width: 100%;
        }

        .header-left {
            display: table-cell;
            width: 70%;
            vertical-align: top;
        }

        .header-right {
            display: table-cell;
            width: 30%;
            vertical-align: top;
            text-align: right;
        }

        .store-logo {
            max-height: 80px;
            width: auto;
            margin-bottom: 8px;
            image-rendering: -webkit-optimize-contrast;
            image-rendering: crisp-edges;
        }

        .store-name {
            font-size: 18px;
            font-weight: bold;
            color: #334155;
            margin-bottom: 8px;
        }

        .store-info {
            font-size: 10px;
            color: #64748b;
            text-align: left;
        }

        .invoice-meta {
            padding: 24px 36px;
        }

        .meta-grid {
            display: table;
            width: 100%;
        }

        .meta-col {
            display: table-cell;
            width: 25%;
            vertical-align: top;
            padding-right: 24px;
        }

        .meta-label {
            font-size: 10px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 4px;
        }

        .meta-value {
            font-size: 10px;
            color: #64748b;
        }

        .meta-value-italic {
            font-size: 10px;
            color: #64748b;
            font-style: italic;
        }

        .content {
            padding: 8px 36px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .items-table th {
            padding: 12px 8px;
            text-align: left;
            font-size: 10px;
            font-weight: 600;
            color: #334155;
            border-bottom: 1px solid #64748b;
        }

        .items-table th.text-right {
            text-align: right;
        }

        .items-table td {
            padding: 8px;
            font-size: 10px;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .items-table td.text-right {
            text-align: right;
        }

        .item-title {
            font-weight: 500;
            color: #334155;
        }

        .item-image {
            width: 40px;
            height: 40px;
            object-fit: cover;
        }

        .totals-footer th,
        .totals-footer td {
            padding: 8px;
            font-size: 10px;
        }

        .totals-footer th {
            text-align: right;
            color: #64748b;
            font-weight: 400;
            border: none;
        }

        .totals-footer td {
            text-align: right;
            color: #64748b;
            border: none;
        }

        .totals-footer tr.total th,
        .totals-footer tr.total td {
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            padding-top: 12px;
        }

        .totals-footer tr.first-row th,
        .totals-footer tr.first-row td {
            padding-top: 24px;
        }

        .payment-modes-table {
            width: 100%;
        }

        .payment-modes-table td {
            font-size: 10px;
            padding: 2px 0;
            border: none;
        }

        .payment-modes-table td:first-child {
            text-align: left;
        }

        .payment-modes-table td:last-child {
            text-align: right;
        }

        .footer {
            margin-top: 96px;
            padding: 24px 36px;
            border-top: 1px solid #e2e8f0;
        }

        .signature-table {
            width: 100%;
            margin-bottom: 24px;
        }

        .signature-table td {
            width: 25%;
            text-align: center;
            padding: 8px;
            font-size: 10px;
            color: #64748b;
            vertical-align: top;
        }

        .signature-table .label-row td {
            font-weight: 600;
            color: #334155;
        }

        .signature-table .value-row td {
            height: 100px;
            vertical-align: top;
            padding-top: 8px;
        }

        .disclaimer {
            font-size: 9px;
            color: #64748b;
            line-height: 1.5;
        }

        .disclaimer-title {
            font-size: 16px;
            font-weight: 700;
            color: #334155;
            text-align: center;
            margin-bottom: 16px;
        }

        .disclaimer p {
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        {{-- Header --}}
        <div class="header">
            <div class="header-left">
                @if(!empty($store['logo']))
                    <img src="{{ $store['logo'] }}" class="store-logo" alt="{{ $store['name'] }}">
                @else
                    <div class="store-name">{{ $store['name'] }}</div>
                @endif
                <div class="store-info">
                    @if(!empty($store['address']))
                        {{ $store['address'] }} {{ $store['address2'] ?? '' }}<br>
                    @endif
                    @if(!empty($store['city']) || !empty($store['state']))
                        {{ $store['city'] ?? '' }}{{ !empty($store['city']) && !empty($store['state']) ? ', ' : '' }}{{ $store['state'] ?? '' }}<br>
                    @endif
                    @if(!empty($store['zip']))
                        {{ $store['zip'] }}<br>
                    @endif
                    @if(!empty($store['phone']))
                        {{ $store['phone'] }}<br>
                    @endif
                    @if(!empty($store['email']))
                        {{ $store['email'] }}
                    @endif
                </div>
            </div>
            <div class="header-right"></div>
        </div>

        {{-- Invoice Meta --}}
        <div class="invoice-meta">
            <div class="meta-grid">
                <div class="meta-col">
                    <div class="meta-label">Invoice Detail:</div>
                    <div class="meta-value">
                        {{ $store['name'] ?? '' }}<br>
                        @if(!empty($store['address']))
                            {{ $store['address'] }} {{ $store['address2'] ?? '' }}<br>
                        @endif
                        @if(!empty($store['city']))
                            {{ $store['city'] }}<br>
                        @endif
                        @if(!empty($store['state']) || !empty($store['zip']))
                            {{ $store['state'] ?? '' }} {{ $store['zip'] ?? '' }}
                        @endif
                    </div>
                </div>

                <div class="meta-col">
                    <div class="meta-label">Purchased From</div>
                    <div class="meta-value">
                        @if($customer)
                            @if(!empty($customer['company_name']))
                                {{ $customer['company_name'] }}<br>
                            @endif
                            @if(!empty($customer['name']))
                                {{ $customer['name'] }}<br>
                            @endif
                            @if(!empty($customer['address']))
                                {{ $customer['address'] }} {{ $customer['address2'] ?? '' }}<br>
                            @endif
                            @if(!empty($customer['city']))
                                {{ $customer['city'] }}<br>
                            @endif
                            @if(!empty($customer['state']) || !empty($customer['zip']))
                                {{ $customer['state'] ?? '' }} {{ $customer['zip'] ?? '' }}
                            @endif
                        @else
                            <span class="meta-value-italic">Walk-in customer</span>
                        @endif
                    </div>
                </div>

                <div class="meta-col">
                    <div class="meta-label">Invoice Number</div>
                    <div class="meta-value">Buy: {{ $transactionNumber }}</div>
                    <div class="meta-label" style="margin-top: 8px;">Date of Issue</div>
                    <div class="meta-value">{{ $dateOfIssue }}</div>
                </div>

                <div class="meta-col">
                    <div class="meta-label">Payment Method</div>
                    <div class="meta-value">{{ $primaryPaymentMethod }}</div>
                </div>
            </div>
        </div>

        {{-- Items Table --}}
        <div class="content">
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">&nbsp;</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th class="text-right">Quantity</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lineItems as $item)
                        <tr>
                            <td>
                                @if(!empty($item['image']))
                                    <img src="{{ $item['image'] }}" class="item-image" alt="">
                                @endif
                            </td>
                            <td>
                                <span class="item-title">{{ $item['title'] }}</span>
                            </td>
                            <td>{{ $item['category'] ?? '-' }}</td>
                            <td class="text-right">{{ $item['quantity'] ?? 1 }}</td>
                            <td class="text-right">${{ number_format($item['total'] ?? 0, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align: center; color: #94a3b8; padding: 20px;">
                                No items
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="totals-footer">
                    @if(count($paymentModes) > 1)
                        <tr class="first-row">
                            <th colspan="4">Payment Modes</th>
                            <td>
                                <table class="payment-modes-table">
                                    @foreach($paymentModes as $mode)
                                        <tr>
                                            <td>{{ $mode['mode'] }}</td>
                                            <td>${{ number_format($mode['total_paid'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif

                    <tr class="total">
                        <th colspan="4">Total</th>
                        <td>${{ number_format($total, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- Footer --}}
        <div class="footer">
            <table class="signature-table">
                <tr class="label-row">
                    <td>Fingerprint</td>
                    <td>Signature</td>
                    <td>Date</td>
                    <td>Sales Person</td>
                </tr>
                <tr class="value-row">
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>{{ $dateOfIssue }}</td>
                    <td>{{ $salesperson ?? '-' }}</td>
                </tr>
            </table>

            <div class="disclaimer">
                <p class="disclaimer-title">Disclaimer</p>
                <p>1. Sellers of Merchandise warrant that he or she is the legal owner of any and all items presented for sale. The seller agrees to transfer the full title of said items to {{ $store['name'] }} (hereafter referred to as "{{ $store['name'] }}") upon acceptance of any form of payment and upon execution of this agreement. Sellers further certifies that the presented goods are genuine and not misrepresented in any way, shape, or form.</p>
                <p>2. You consent to the law and jurisdiction of any court within the State of Pennsylvania for action arising from this transaction. You agree to pay all costs, including attorney's fees and expenses and court costs, incurred by {{ $store['name'] }} or its assigns in enforcing any part of this contract.</p>
                <p>3. The price for which each item is sold represents the price that {{ $store['name'] }} has offered, and you have paid, independent of any description by {{ $store['name'] }}. The condition, description or grade of any item sold represents the opinion of {{ $store['name'] }} and is not a warranty of any kind. {{ $store['name'] }} disclaims all warranties, expressed or implied, including warranties of merchantability.</p>
                <p>4. {{ $store['name'] }}'s sole liability for any claim shall be no greater than the purchase price of the merchandise with respect to which a claim is made after such merchandise is returned to {{ $store['name'] }}. Such liability shall not include consequential damages.</p>
                <p><strong>Consignment - Memo</strong></p>
                <p>5. The merchandise described on the front side of this invoice remains property of {{ $store['name'] }} and shall be returned to us on demand until payment is made in full and is received by {{ $store['name'] }}. No power is given to you to sell, pledge, hypothecate or otherwise dispose of this merchandise until paid in full.</p>
                <p>6. For Consignment and Memos, you will bear all risk of loss from all hazards for this merchandise from its delivery to you until its returned to {{ $store['name'] }} or paid in full. A finance charge of 3% per month (36% annually) will be applied to any balance remaining unpaid 30 days after the date of this sale order.</p>
            </div>
        </div>
    </div>
</body>
</html>
