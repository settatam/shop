<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Deleted</title>
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
        .info-box {
            background-color: #f3f4f6;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .info-box p {
            margin: 4px 0;
            color: #4b5563;
        }
        .info-box strong {
            color: #111827;
        }
        .reason-box {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            border-radius: 4px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .reason-box .label {
            font-weight: 600;
            color: #92400e;
            margin: 0 0 4px 0;
        }
        .reason-box p {
            margin: 0;
            color: #92400e;
            font-style: italic;
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
                <h1>Product Deleted</h1>
            </div>

            <div class="info-box">
                <p><strong>Product:</strong> {{ $product->title }}</p>
                @if($product->variants->first()?->sku)
                    <p><strong>SKU:</strong> {{ $product->variants->first()->sku }}</p>
                @endif
                <p><strong>Deleted by:</strong> {{ $deletedByName }}</p>
                <p><strong>Date:</strong> {{ $product->deleted_at?->format('M d, Y \a\t g:i A') ?? now()->format('M d, Y \a\t g:i A') }}</p>
            </div>

            @if($deletionReason)
                <div class="reason-box">
                    <p class="label">Reason for deletion:</p>
                    <p>"{{ $deletionReason }}"</p>
                </div>
            @endif

            <div class="footer">
                <p>This email was sent because a product was deleted from your store.</p>
            </div>
        </div>
    </div>
</body>
</html>
