<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Packing Slip {{ $documentNumber }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #1f2937;
        }

        .container {
            padding: 30px;
        }

        .header {
            display: table;
            width: 100%;
            margin-bottom: 30px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 20px;
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
            font-size: 20px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 6px;
        }

        .company-details {
            color: #6b7280;
            font-size: 10px;
        }

        .document-title {
            font-size: 24px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 6px;
        }

        .document-number {
            font-size: 13px;
            color: #374151;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .document-date {
            font-size: 10px;
            color: #6b7280;
        }

        .addresses {
            display: table;
            width: 100%;
            margin-bottom: 25px;
        }

        .address-block {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 20px;
        }

        .section-title {
            font-size: 9px;
            font-weight: bold;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .recipient-name {
            font-weight: bold;
            color: #111827;
            font-size: 12px;
            margin-bottom: 4px;
        }

        .recipient-details {
            color: #6b7280;
            font-size: 10px;
        }

        .shipping-info {
            background-color: #f9fafb;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 4px;
        }

        .shipping-row {
            display: table;
            width: 100%;
            margin-bottom: 6px;
        }

        .shipping-row:last-child {
            margin-bottom: 0;
        }

        .shipping-label {
            display: table-cell;
            width: 120px;
            color: #6b7280;
            font-size: 10px;
        }

        .shipping-value {
            display: table-cell;
            color: #111827;
            font-weight: 600;
            font-size: 11px;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }

        table.items th {
            background-color: #f3f4f6;
            border-bottom: 2px solid #e5e7eb;
            padding: 10px 8px;
            text-align: left;
            font-size: 9px;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
        }

        table.items th.text-center {
            text-align: center;
        }

        table.items th.text-right {
            text-align: right;
        }

        table.items td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        table.items td.text-center {
            text-align: center;
        }

        table.items td.text-right {
            text-align: right;
        }

        .item-title {
            color: #111827;
            font-weight: 600;
        }

        .item-sku {
            color: #6b7280;
            font-size: 9px;
            margin-top: 2px;
        }

        .item-description {
            color: #6b7280;
            font-size: 9px;
            margin-top: 2px;
        }

        .checkbox {
            width: 14px;
            height: 14px;
            border: 1.5px solid #9ca3af;
            display: inline-block;
            vertical-align: middle;
        }

        .summary {
            display: table;
            width: 100%;
            margin-bottom: 25px;
        }

        .summary-left {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .summary-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }

        .total-items {
            font-size: 13px;
            font-weight: bold;
            color: #111827;
        }

        .notes-section {
            background-color: #fffbeb;
            border: 1px solid #fcd34d;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 4px;
        }

        .notes-title {
            font-weight: bold;
            color: #92400e;
            font-size: 10px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .notes-content {
            color: #78350f;
            font-size: 10px;
            white-space: pre-wrap;
        }

        .instructions {
            border: 1px solid #e5e7eb;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 4px;
        }

        .instructions-title {
            font-weight: bold;
            color: #111827;
            font-size: 10px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .instructions-content {
            color: #6b7280;
            font-size: 10px;
        }

        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
        }

        .signature-area {
            display: table;
            width: 100%;
        }

        .signature-block {
            display: table-cell;
            width: 50%;
            padding-right: 30px;
        }

        .signature-line {
            border-bottom: 1px solid #9ca3af;
            height: 40px;
            margin-bottom: 5px;
        }

        .signature-label {
            font-size: 9px;
            color: #6b7280;
        }

        .barcode-area {
            text-align: center;
            margin-top: 20px;
        }

        .barcode-number {
            font-family: monospace;
            font-size: 14px;
            letter-spacing: 2px;
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
                <div class="document-title">PACKING SLIP</div>
                <div class="document-number">#{{ $documentNumber }}</div>
                <div class="document-date">{{ $date }}</div>
            </div>
        </div>

        <div class="addresses">
            <div class="address-block">
                <div class="section-title">Ship From</div>
                <div class="recipient-name">{{ $store['name'] }}</div>
                <div class="recipient-details">
                    @if($store['address'])
                        {{ $store['address'] }}<br>
                    @endif
                    @if($store['city'] || $store['state'] || $store['zip'])
                        {{ $store['city'] }}@if($store['city'] && $store['state']),@endif
                        {{ $store['state'] }} {{ $store['zip'] }}
                    @endif
                </div>
            </div>
            <div class="address-block">
                <div class="section-title">Ship To</div>
                @if($recipient)
                    <div class="recipient-name">{{ $recipient['name'] }}</div>
                    <div class="recipient-details">
                        @if($recipient['company'])
                            {{ $recipient['company'] }}<br>
                        @endif
                        @if($recipient['address'])
                            {{ $recipient['address'] }}<br>
                        @endif
                        @if($recipient['address2'])
                            {{ $recipient['address2'] }}<br>
                        @endif
                        @if($recipient['city'] || $recipient['state'] || $recipient['zip'])
                            {{ $recipient['city'] }}@if($recipient['city'] && ($recipient['state'] || $recipient['zip'])),@endif
                            {{ $recipient['state'] }} {{ $recipient['zip'] }}<br>
                        @endif
                        @if($recipient['phone'])
                            {{ $recipient['phone'] }}<br>
                        @endif
                        @if($recipient['email'])
                            {{ $recipient['email'] }}
                        @endif
                    </div>
                @else
                    <div class="recipient-details">No recipient information</div>
                @endif
            </div>
        </div>

        @if($trackingNumber || $carrier || $shippingMethod)
            <div class="shipping-info">
                @if($carrier)
                    <div class="shipping-row">
                        <div class="shipping-label">Carrier:</div>
                        <div class="shipping-value">{{ ucfirst($carrier) }}</div>
                    </div>
                @endif
                @if($shippingMethod)
                    <div class="shipping-row">
                        <div class="shipping-label">Shipping Method:</div>
                        <div class="shipping-value">{{ $shippingMethod }}</div>
                    </div>
                @endif
                @if($trackingNumber)
                    <div class="shipping-row">
                        <div class="shipping-label">Tracking Number:</div>
                        <div class="shipping-value">{{ $trackingNumber }}</div>
                    </div>
                @endif
            </div>
        @endif

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 8%;" class="text-center">Check</th>
                    <th style="width: 12%;">SKU</th>
                    <th style="width: 45%;">Description</th>
                    <th style="width: 10%;" class="text-center">Qty</th>
                    @if($showPrices)
                        <th style="width: 12%;" class="text-right">Unit Price</th>
                        <th style="width: 13%;" class="text-right">Total</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td class="text-center"><span class="checkbox"></span></td>
                        <td>
                            <span style="font-family: monospace; font-size: 10px;">{{ $item['sku'] ?? '-' }}</span>
                        </td>
                        <td>
                            <div class="item-title">{{ $item['title'] }}</div>
                            @if(!empty($item['description']))
                                <div class="item-description">{{ $item['description'] }}</div>
                            @endif
                        </td>
                        <td class="text-center">{{ $item['quantity'] }}</td>
                        @if($showPrices)
                            <td class="text-right">${{ number_format($item['unit_price'], 2) }}</td>
                            <td class="text-right">${{ number_format($item['total'], 2) }}</td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showPrices ? 6 : 4 }}" style="text-align: center; color: #9ca3af; padding: 20px;">
                            No items in this shipment
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="summary">
            <div class="summary-left">
                <div class="total-items">Total Items: {{ $totalItems }}</div>
            </div>
            @if($showPrices && $totalValue > 0)
                <div class="summary-right">
                    <div class="total-items">Total Value: ${{ number_format($totalValue, 2) }}</div>
                </div>
            @endif
        </div>

        @if($notes)
            <div class="notes-section">
                <div class="notes-title">Special Instructions</div>
                <div class="notes-content">{{ $notes }}</div>
            </div>
        @endif

        @if($packingInstructions)
            <div class="instructions">
                <div class="instructions-title">Packing Instructions</div>
                <div class="instructions-content">{{ $packingInstructions }}</div>
            </div>
        @endif

        <div class="footer">
            <div class="signature-area">
                <div class="signature-block">
                    <div class="signature-line"></div>
                    <div class="signature-label">Packed By / Date</div>
                </div>
                <div class="signature-block">
                    <div class="signature-line"></div>
                    <div class="signature-label">Received By / Date</div>
                </div>
            </div>

            <div class="barcode-area">
                <div class="barcode-number">{{ $documentNumber }}</div>
            </div>
        </div>
    </div>
</body>
</html>
