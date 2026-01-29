# Products API

## Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/products` | List products |
| GET | `/api/v1/products/{id}` | Get single product |
| POST | `/api/v1/products` | Create product |
| PUT | `/api/v1/products/{id}` | Update product |
| DELETE | `/api/v1/products/{id}` | Delete product |

---

## List Products

```bash
GET /api/v1/products
Authorization: Bearer {token}
X-Store-Id: 123
```

### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Items per page (default: 20, max: 100) |
| `search` | string | Search title, SKU, barcode |
| `status` | string | Filter by status (draft, active, archived) |
| `category_id` | integer | Filter by category |
| `brand_id` | integer | Filter by brand |
| `vendor` | string | Filter by vendor |
| `is_published` | boolean | Filter by published state |
| `sort` | string | Sort field (title, created_at, updated_at) |
| `direction` | string | Sort direction (asc, desc) |

### Response

```json
{
    "data": [
        {
            "id": 1,
            "title": "Classic T-Shirt",
            "handle": "classic-t-shirt",
            "description": "Comfortable cotton t-shirt",
            "status": "active",
            "is_published": true,
            "category": {
                "id": 5,
                "name": "Clothing"
            },
            "brand": null,
            "vendor": "Acme Co",
            "variants": [
                {
                    "id": 1,
                    "sku": "TSHIRT-S-BLU",
                    "price": "29.99",
                    "compare_at_price": null,
                    "cost": "12.00",
                    "quantity": 50,
                    "option1_name": "Size",
                    "option1_value": "Small",
                    "option2_name": "Color",
                    "option2_value": "Blue"
                }
            ],
            "created_at": "2024-01-15T10:00:00Z",
            "updated_at": "2024-01-15T10:00:00Z"
        }
    ],
    "links": {
        "first": "https://api.shopmata.com/api/v1/products?page=1",
        "last": "https://api.shopmata.com/api/v1/products?page=5",
        "prev": null,
        "next": "https://api.shopmata.com/api/v1/products?page=2"
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 5,
        "per_page": 20,
        "to": 20,
        "total": 95
    }
}
```

---

## Get Product

```bash
GET /api/v1/products/1
Authorization: Bearer {token}
X-Store-Id: 123
```

### Response

```json
{
    "data": {
        "id": 1,
        "title": "Classic T-Shirt",
        "handle": "classic-t-shirt",
        "description": "Comfortable cotton t-shirt",
        "status": "active",
        "is_published": true,
        "track_quantity": true,
        "continue_selling": false,
        "requires_shipping": true,
        "weight": "0.25",
        "weight_unit": "kg",
        "category": {
            "id": 5,
            "name": "Clothing"
        },
        "brand": null,
        "vendor": "Acme Co",
        "product_type": "Apparel",
        "attributes": {},
        "variants": [
            {
                "id": 1,
                "sku": "TSHIRT-S-BLU",
                "barcode": "1234567890123",
                "price": "29.99",
                "compare_at_price": null,
                "cost": "12.00",
                "quantity": 50,
                "option1_name": "Size",
                "option1_value": "Small",
                "option2_name": "Color",
                "option2_value": "Blue",
                "option3_name": null,
                "option3_value": null,
                "inventory": [
                    {
                        "warehouse_id": 1,
                        "warehouse_name": "Main Warehouse",
                        "quantity": 50,
                        "reserved": 5
                    }
                ]
            }
        ],
        "images": [],
        "created_at": "2024-01-15T10:00:00Z",
        "updated_at": "2024-01-15T10:00:00Z"
    }
}
```

---

## Create Product

```bash
POST /api/v1/products
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "title": "Classic T-Shirt",
    "description": "Comfortable cotton t-shirt",
    "category_id": 5,
    "vendor": "Acme Co",
    "product_type": "Apparel",
    "status": "draft",
    "is_published": false,
    "track_quantity": true,
    "continue_selling": false,
    "requires_shipping": true,
    "weight": 0.25,
    "weight_unit": "kg",
    "variants": [
        {
            "sku": "TSHIRT-S-BLU",
            "barcode": "1234567890123",
            "price": 29.99,
            "cost": 12.00,
            "option1_name": "Size",
            "option1_value": "Small",
            "option2_name": "Color",
            "option2_value": "Blue",
            "inventory": {
                "1": 50
            }
        },
        {
            "sku": "TSHIRT-M-BLU",
            "price": 29.99,
            "cost": 12.00,
            "option1_name": "Size",
            "option1_value": "Medium",
            "option2_name": "Color",
            "option2_value": "Blue",
            "inventory": {
                "1": 30
            }
        }
    ]
}
```

### Response

```json
{
    "data": {
        "id": 1,
        "title": "Classic T-Shirt",
        ...
    },
    "message": "Product created successfully"
}
```

---

## Update Product

```bash
PUT /api/v1/products/1
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "title": "Premium T-Shirt",
    "status": "active",
    "is_published": true
}
```

### Response

```json
{
    "data": {
        "id": 1,
        "title": "Premium T-Shirt",
        ...
    },
    "message": "Product updated successfully"
}
```

---

## Delete Product

```bash
DELETE /api/v1/products/1
Authorization: Bearer {token}
X-Store-Id: 123
```

### Response

```json
{
    "message": "Product deleted successfully"
}
```

---

## Variant Endpoints

### Add Variant

```bash
POST /api/v1/products/1/variants
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "sku": "TSHIRT-L-BLU",
    "price": 29.99,
    "cost": 12.00,
    "option1_name": "Size",
    "option1_value": "Large",
    "option2_name": "Color",
    "option2_value": "Blue",
    "inventory": {
        "1": 25
    }
}
```

### Update Variant

```bash
PUT /api/v1/products/1/variants/3
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "price": 34.99,
    "inventory": {
        "1": 40
    }
}
```

### Delete Variant

```bash
DELETE /api/v1/products/1/variants/3
Authorization: Bearer {token}
X-Store-Id: 123
```

---

## Validation Rules

### Product Fields

| Field | Rules |
|-------|-------|
| `title` | required, string, max:255 |
| `description` | nullable, string |
| `handle` | nullable, string, max:255, unique per store |
| `status` | in:draft,active,archived |
| `is_published` | boolean |
| `category_id` | nullable, exists:categories,id |
| `brand_id` | nullable, exists:brands,id |
| `vendor` | nullable, string, max:255 |
| `track_quantity` | boolean |
| `weight` | nullable, numeric, min:0 |
| `weight_unit` | in:kg,lb,oz,g |

### Variant Fields

| Field | Rules |
|-------|-------|
| `sku` | required, string, max:255, unique per store |
| `barcode` | nullable, string, max:255 |
| `price` | required, numeric, min:0 |
| `compare_at_price` | nullable, numeric, min:0 |
| `cost` | nullable, numeric, min:0 |
| `option1_name` | nullable, string, max:255 |
| `option1_value` | nullable, string, max:255 |

---

## Error Responses

### 404 Not Found

```json
{
    "message": "Product not found"
}
```

### 422 Validation Error

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "title": ["The title field is required."],
        "variants.0.sku": ["The sku has already been taken."]
    }
}
```
