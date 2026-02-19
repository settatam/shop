<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Offer from {{ $storeName }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            margin: 0;
            padding: 0;
            background-color: #f3f4f6;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 32px;
            margin-top: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 24px;
        }
        .header img {
            max-height: 60px;
            margin-bottom: 16px;
        }
        .header h1 {
            color: #111827;
            font-size: 24px;
            margin: 0;
        }
        .greeting {
            font-size: 16px;
            color: #4b5563;
            margin-bottom: 24px;
        }
        .offer-box {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            margin-bottom: 24px;
        }
        .offer-label {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        .offer-amount {
            color: #ffffff;
            font-size: 36px;
            font-weight: 700;
            margin: 0;
        }
        .offer-tier {
            display: inline-block;
            background-color: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 12px;
        }
        .expires-notice {
            color: rgba(255, 255, 255, 0.8);
            font-size: 12px;
            margin-top: 12px;
        }
        .section-title {
            color: #111827;
            font-size: 16px;
            font-weight: 600;
            margin: 24px 0 12px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        .reasoning-box {
            background-color: #f9fafb;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .reasoning-box p {
            margin: 0;
            color: #374151;
            white-space: pre-wrap;
        }
        .images-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
        }
        .image-item {
            width: calc(50% - 6px);
            border-radius: 6px;
            overflow: hidden;
        }
        .image-item img {
            width: 100%;
            height: auto;
            display: block;
        }
        .image-caption {
            background-color: #f3f4f6;
            padding: 8px;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
        }
        .items-summary {
            background-color: #f9fafb;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .items-summary ul {
            margin: 0;
            padding: 0 0 0 20px;
        }
        .items-summary li {
            color: #4b5563;
            margin-bottom: 4px;
        }
        .button {
            display: inline-block;
            background-color: #059669;
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            text-align: center;
        }
        .button:hover {
            background-color: #047857;
        }
        .button-container {
            text-align: center;
            margin: 32px 0;
        }
        .transaction-info {
            background-color: #f3f4f6;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .transaction-info span {
            color: #6b7280;
        }
        .transaction-info strong {
            color: #111827;
        }
        .footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 12px;
        }
        .footer a {
            color: #4f46e5;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                @if($storeLogo)
                    <img src="{{ $storeLogo }}" alt="{{ $storeName }}">
                @endif
                <h1>Your Offer is Ready!</h1>
            </div>

            <p class="greeting">Hi {{ $customerName }},</p>

            <p>We've carefully reviewed the items you sent us and are pleased to present our offer:</p>

            <div class="offer-box">
                <p class="offer-label">Our Offer</p>
                <p class="offer-amount">${{ $offerAmount }}</p>
                @if($tierLabel)
                    <span class="offer-tier">{{ $tierLabel }} Offer</span>
                @endif
                @if($expiresAt)
                    <p class="expires-notice">This offer expires on {{ $expiresAt }}</p>
                @endif
            </div>

            <div class="transaction-info">
                <span>Transaction:</span> <strong>{{ $transactionNumber }}</strong>
                @if($itemCount > 0)
                    &nbsp;&middot;&nbsp;
                    <span>{{ $itemCount }} item{{ $itemCount > 1 ? 's' : '' }} reviewed</span>
                @endif
            </div>

            @if($reasoning)
                <h3 class="section-title">Our Assessment</h3>
                <div class="reasoning-box">
                    <p>{{ $reasoning }}</p>
                </div>
            @endif

            @if(!empty($images))
                <h3 class="section-title">Your Items</h3>
                <div class="images-grid">
                    @foreach($images as $image)
                        <div class="image-item">
                            <img src="{{ $image['url'] }}" alt="{{ $image['item_title'] ?? 'Item' }}">
                            @if(!empty($image['item_title']))
                                <div class="image-caption">{{ $image['item_title'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if(!empty($itemsSummary))
                <h3 class="section-title">Items Included</h3>
                <div class="items-summary">
                    <ul>
                        @foreach($itemsSummary as $item)
                            <li>
                                {{ $item['title'] }}
                                @if(!empty($item['category']))
                                    <span style="color: #9ca3af;">({{ $item['category'] }})</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="button-container">
                <a href="{{ $portalUrl }}" class="button">View & Respond to Offer</a>
            </div>

            <p style="text-align: center; color: #6b7280; font-size: 14px;">
                You can accept, decline, or request a revision through our secure portal.
            </p>

            <div class="footer">
                <p>This email was sent by {{ $storeName }} regarding your transaction {{ $transactionNumber }}.</p>
                <p>Questions? Reply to this email or <a href="{{ $portalUrl }}">visit your portal</a>.</p>
            </div>
        </div>
    </div>
</body>
</html>
