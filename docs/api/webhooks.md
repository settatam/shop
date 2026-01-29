# Webhooks

## Overview

Webhooks allow external systems to receive real-time notifications when events occur in your store. When an event triggers, Shopmata sends an HTTP POST request to your configured endpoint.

---

## Setting Up Webhooks

### Create Webhook

```bash
POST /api/v1/webhooks
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "url": "https://your-server.com/webhooks/shopmata",
    "events": ["order.created", "order.updated", "product.created"],
    "secret": "your-webhook-secret"
}
```

### Response

```json
{
    "data": {
        "id": 1,
        "url": "https://your-server.com/webhooks/shopmata",
        "events": ["order.created", "order.updated", "product.created"],
        "is_active": true,
        "created_at": "2024-01-15T10:00:00Z"
    },
    "message": "Webhook created successfully"
}
```

---

## Available Events

### Product Events

| Event | Description |
|-------|-------------|
| `product.created` | New product created |
| `product.updated` | Product details changed |
| `product.deleted` | Product deleted/archived |
| `product.published` | Product published |
| `product.unpublished` | Product unpublished |

### Variant Events

| Event | Description |
|-------|-------------|
| `variant.created` | New variant added |
| `variant.updated` | Variant details changed |
| `variant.deleted` | Variant removed |

### Inventory Events

| Event | Description |
|-------|-------------|
| `inventory.updated` | Stock level changed |
| `inventory.low_stock` | Stock fell below reorder point |
| `inventory.out_of_stock` | Stock reached zero |

### Order Events

| Event | Description |
|-------|-------------|
| `order.created` | New order placed |
| `order.updated` | Order details changed |
| `order.paid` | Payment received |
| `order.fulfilled` | Order shipped |
| `order.cancelled` | Order cancelled |
| `order.refunded` | Refund issued |

### Customer Events

| Event | Description |
|-------|-------------|
| `customer.created` | New customer registered |
| `customer.updated` | Customer details changed |

---

## Webhook Payload

### Structure

```json
{
    "id": "wh_abc123xyz",
    "event": "order.created",
    "store_id": 123,
    "timestamp": "2024-01-15T10:30:00Z",
    "data": {
        // Event-specific data
    }
}
```

### Example: order.created

```json
{
    "id": "wh_abc123xyz",
    "event": "order.created",
    "store_id": 123,
    "timestamp": "2024-01-15T10:30:00Z",
    "data": {
        "id": 456,
        "order_number": "ORD-1001",
        "status": "pending",
        "total": "149.99",
        "currency": "USD",
        "customer": {
            "id": 789,
            "email": "customer@example.com",
            "name": "John Doe"
        },
        "line_items": [
            {
                "variant_id": 1,
                "sku": "TSHIRT-S-BLU",
                "title": "Classic T-Shirt - Small / Blue",
                "quantity": 2,
                "price": "29.99",
                "total": "59.98"
            }
        ],
        "shipping_address": {
            "address1": "123 Main St",
            "city": "New York",
            "state": "NY",
            "zip": "10001",
            "country": "US"
        },
        "created_at": "2024-01-15T10:30:00Z"
    }
}
```

### Example: inventory.updated

```json
{
    "id": "wh_def456uvw",
    "event": "inventory.updated",
    "store_id": 123,
    "timestamp": "2024-01-15T10:35:00Z",
    "data": {
        "variant_id": 1,
        "sku": "TSHIRT-S-BLU",
        "warehouse_id": 1,
        "previous_quantity": 50,
        "new_quantity": 45,
        "change": -5,
        "reason": "order_fulfilled",
        "reference": "ORD-1001"
    }
}
```

---

## Verifying Webhooks

All webhook requests include a signature header for verification:

```
X-Shopmata-Signature: sha256=5d41402abc4b2a76b9719d911017c592
```

### Verification Example (PHP)

```php
function verifyWebhook(string $payload, string $signature, string $secret): bool
{
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}

// In your webhook handler
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SHOPMATA_SIGNATURE'] ?? '';
$secret = 'your-webhook-secret';

if (!verifyWebhook($payload, $signature, $secret)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
// Process event...
```

### Verification Example (Node.js)

```javascript
const crypto = require('crypto');

function verifyWebhook(payload, signature, secret) {
    const expected = 'sha256=' + crypto
        .createHmac('sha256', secret)
        .update(payload)
        .digest('hex');
    return crypto.timingSafeEqual(
        Buffer.from(expected),
        Buffer.from(signature)
    );
}
```

---

## Webhook Headers

Every webhook request includes these headers:

| Header | Description |
|--------|-------------|
| `Content-Type` | `application/json` |
| `X-Shopmata-Signature` | HMAC-SHA256 signature |
| `X-Shopmata-Event` | Event type (e.g., `order.created`) |
| `X-Shopmata-Delivery` | Unique delivery ID |
| `X-Shopmata-Store-Id` | Store ID |
| `User-Agent` | `Shopmata-Webhook/1.0` |

---

## Retry Policy

Failed webhook deliveries are retried automatically:

| Attempt | Delay |
|---------|-------|
| 1 | Immediate |
| 2 | 1 minute |
| 3 | 5 minutes |
| 4 | 30 minutes |
| 5 | 2 hours |
| 6 | 8 hours |
| 7 | 24 hours |

After 7 failed attempts, the webhook is marked as failed and no more retries occur.

### Success Criteria

A delivery is considered successful if your endpoint returns:
- HTTP status 2xx (200-299)
- Within 30 seconds

---

## Managing Webhooks

### List Webhooks

```bash
GET /api/v1/webhooks
Authorization: Bearer {token}
X-Store-Id: 123
```

### Update Webhook

```bash
PUT /api/v1/webhooks/1
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "events": ["order.created", "order.updated"],
    "is_active": true
}
```

### Delete Webhook

```bash
DELETE /api/v1/webhooks/1
Authorization: Bearer {token}
X-Store-Id: 123
```

### Test Webhook

Send a test event to verify your endpoint:

```bash
POST /api/v1/webhooks/1/test
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "event": "order.created"
}
```

---

## Webhook Logs

View delivery history for a webhook:

```bash
GET /api/v1/webhooks/1/logs
Authorization: Bearer {token}
X-Store-Id: 123
```

### Response

```json
{
    "data": [
        {
            "id": "del_abc123",
            "event": "order.created",
            "status": "success",
            "response_code": 200,
            "response_time_ms": 245,
            "attempts": 1,
            "created_at": "2024-01-15T10:30:00Z",
            "completed_at": "2024-01-15T10:30:00Z"
        },
        {
            "id": "del_def456",
            "event": "inventory.updated",
            "status": "failed",
            "response_code": 500,
            "response_time_ms": 5000,
            "attempts": 7,
            "error": "Connection timeout",
            "created_at": "2024-01-14T09:00:00Z",
            "completed_at": "2024-01-15T09:00:00Z"
        }
    ]
}
```

---

## Best Practices

1. **Respond quickly** - Return 200 immediately, process asynchronously
2. **Handle duplicates** - Use the delivery ID to deduplicate
3. **Verify signatures** - Always validate webhook authenticity
4. **Use HTTPS** - Secure your endpoint with TLS
5. **Log everything** - Keep records for debugging
6. **Handle retries** - Be idempotent in your processing
