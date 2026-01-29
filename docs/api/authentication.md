# API Authentication

## Overview

Shopmata uses OAuth2 via Laravel Passport for API authentication. All API requests require authentication and a store context.

---

## Authentication Methods

### 1. OAuth2 Password Grant

For server-to-server integrations or CLI tools.

```bash
POST /oauth/token
Content-Type: application/json

{
    "grant_type": "password",
    "client_id": "your-client-id",
    "client_secret": "your-client-secret",
    "username": "user@example.com",
    "password": "your-password",
    "scope": ""
}
```

**Response:**
```json
{
    "token_type": "Bearer",
    "expires_in": 31536000,
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJS...",
    "refresh_token": "def50200a4f..."
}
```

### 2. Personal Access Tokens

For user-generated API keys.

```bash
# Create via API
POST /api/v1/user/tokens
Authorization: Bearer {existing_token}

{
    "name": "My Integration",
    "scopes": ["*"]
}
```

**Response:**
```json
{
    "accessToken": "eyJ0eXAiOiJKV1QiLCJhbGciOi...",
    "token": {
        "id": "abc123",
        "name": "My Integration",
        "scopes": ["*"],
        "created_at": "2024-01-15T10:00:00Z"
    }
}
```

### 3. First-Party Apps (Cookie Authentication)

For SPAs using the same domain, use the `CreateFreshApiToken` middleware which creates encrypted cookies automatically upon login.

---

## Using Authentication

### Bearer Token

Include the access token in the Authorization header:

```bash
GET /api/v1/products
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOi...
X-Store-Id: 123
```

### Store Context (Required)

All API requests must include the store context via header:

```bash
X-Store-Id: 123
```

Requests without this header will receive:
```json
{
    "error": "X-Store-Id header required",
    "status": 400
}
```

---

## Token Refresh

When your access token expires, use the refresh token to get a new one:

```bash
POST /oauth/token
Content-Type: application/json

{
    "grant_type": "refresh_token",
    "refresh_token": "def50200a4f...",
    "client_id": "your-client-id",
    "client_secret": "your-client-secret",
    "scope": ""
}
```

---

## Scopes

| Scope | Description |
|-------|-------------|
| `*` | Full access (all permissions) |
| `products:read` | Read product data |
| `products:write` | Create/update products |
| `orders:read` | Read order data |
| `orders:write` | Create/update orders |
| `inventory:read` | Read inventory levels |
| `inventory:write` | Adjust inventory |
| `customers:read` | Read customer data |
| `customers:write` | Create/update customers |

---

## Error Responses

### 401 Unauthorized

Token is missing, invalid, or expired:

```json
{
    "message": "Unauthenticated."
}
```

### 403 Forbidden

User lacks permission for this action:

```json
{
    "message": "This action is unauthorized."
}
```

### 400 Bad Request

Missing required headers:

```json
{
    "error": "X-Store-Id header required"
}
```

---

## Rate Limiting

API requests are rate limited per user:

| Endpoint Type | Limit |
|---------------|-------|
| Standard | 60 requests/minute |
| Bulk operations | 10 requests/minute |
| Webhooks | 1000 requests/minute |

Rate limit headers are included in responses:
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 57
X-RateLimit-Reset: 1705320000
```

When exceeded:
```json
{
    "message": "Too Many Attempts.",
    "retry_after": 42
}
```

---

## Example: Complete Authentication Flow

```bash
# 1. Get access token
TOKEN=$(curl -s -X POST https://api.shopmata.com/oauth/token \
    -H "Content-Type: application/json" \
    -d '{
        "grant_type": "password",
        "client_id": "1",
        "client_secret": "secret",
        "username": "user@example.com",
        "password": "password"
    }' | jq -r '.access_token')

# 2. Get user's stores
STORES=$(curl -s https://api.shopmata.com/api/v1/user/stores \
    -H "Authorization: Bearer $TOKEN")

# 3. Make API request with store context
curl -s https://api.shopmata.com/api/v1/products \
    -H "Authorization: Bearer $TOKEN" \
    -H "X-Store-Id: 123"
```

---

## Security Best Practices

1. **Never expose client secrets** in client-side code
2. **Use HTTPS** for all API requests
3. **Store tokens securely** - use environment variables or secure storage
4. **Rotate tokens regularly** - create new tokens and revoke old ones
5. **Use minimal scopes** - request only the permissions you need
6. **Validate webhooks** - verify webhook signatures
