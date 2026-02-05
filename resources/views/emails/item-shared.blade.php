<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Shared With You</title>
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
        .header h1 {
            color: #111827;
            font-size: 24px;
            margin: 0;
        }
        .sender-info {
            background-color: #f3f4f6;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .sender-info p {
            margin: 0;
            color: #4b5563;
        }
        .sender-info strong {
            color: #111827;
        }
        .message-box {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            border-radius: 4px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .message-box p {
            margin: 0;
            color: #92400e;
            font-style: italic;
        }
        .item-details {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .item-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin: 0 0 12px 0;
        }
        .item-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 12px;
        }
        .meta-item {
            font-size: 14px;
        }
        .meta-label {
            color: #6b7280;
        }
        .meta-value {
            color: #111827;
            font-weight: 500;
        }
        .price-highlight {
            color: #059669;
            font-weight: 600;
        }
        .description {
            color: #6b7280;
            font-size: 14px;
            margin-top: 12px;
        }
        .button {
            display: inline-block;
            background-color: #4f46e5;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 500;
            text-align: center;
        }
        .button:hover {
            background-color: #4338ca;
        }
        .button-container {
            text-align: center;
            margin-top: 24px;
        }
        .footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>Item Shared With You</h1>
            </div>

            <div class="sender-info">
                <p><strong>{{ $sender->name }}</strong> shared an item with you for review.</p>
            </div>

            @if($message)
                <div class="message-box">
                    <p>"{{ $message }}"</p>
                </div>
            @endif

            <div class="item-details">
                <h2 class="item-title">{{ $item->title }}</h2>

                <div class="item-meta">
                    @if($item->category)
                        <div class="meta-item">
                            <span class="meta-label">Category:</span>
                            <span class="meta-value">{{ $item->category->name }}</span>
                        </div>
                    @endif

                    @if($item->precious_metal)
                        <div class="meta-item">
                            <span class="meta-label">Metal:</span>
                            <span class="meta-value">{{ ucfirst(str_replace('_', ' ', $item->precious_metal)) }}</span>
                        </div>
                    @endif

                    @if($item->price)
                        <div class="meta-item">
                            <span class="meta-label">Est. Value:</span>
                            <span class="meta-value price-highlight">${{ number_format($item->price, 2) }}</span>
                        </div>
                    @endif

                    @if($item->buy_price)
                        <div class="meta-item">
                            <span class="meta-label">Buy Price:</span>
                            <span class="meta-value price-highlight">${{ number_format($item->buy_price, 2) }}</span>
                        </div>
                    @endif

                    @if($item->condition)
                        <div class="meta-item">
                            <span class="meta-label">Condition:</span>
                            <span class="meta-value">{{ ucfirst(str_replace('_', ' ', $item->condition)) }}</span>
                        </div>
                    @endif
                </div>

                @if($item->description)
                    <p class="description">{{ Str::limit($item->description, 200) }}</p>
                @endif
            </div>

            <div class="button-container">
                <a href="{{ $itemUrl }}" class="button">View Item Details</a>
            </div>

            <div class="footer">
                <p>This email was sent because a team member shared an item with you.</p>
            </div>
        </div>
    </div>
</body>
</html>
