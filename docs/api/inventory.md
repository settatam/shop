# Inventory API

## Endpoints

### Inventory
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/inventory` | List inventory levels |
| GET | `/api/v1/inventory/{variant_id}` | Get inventory for variant |
| POST | `/api/v1/inventory/adjust` | Adjust inventory |
| POST | `/api/v1/inventory/set` | Set inventory level |

### Warehouses
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/warehouses` | List warehouses |
| GET | `/api/v1/warehouses/{id}` | Get warehouse |
| POST | `/api/v1/warehouses` | Create warehouse |
| PUT | `/api/v1/warehouses/{id}` | Update warehouse |
| DELETE | `/api/v1/warehouses/{id}` | Delete warehouse |

### Transfers
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/transfers` | List stock transfers |
| POST | `/api/v1/transfers` | Create transfer |
| POST | `/api/v1/transfers/{id}/complete` | Complete transfer |
| POST | `/api/v1/transfers/{id}/cancel` | Cancel transfer |

---

## List Inventory

```bash
GET /api/v1/inventory
Authorization: Bearer {token}
X-Store-Id: 123
```

### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `warehouse_id` | integer | Filter by warehouse |
| `product_id` | integer | Filter by product |
| `low_stock` | boolean | Only show items at/below reorder point |
| `out_of_stock` | boolean | Only show items with zero quantity |
| `search` | string | Search by SKU or product title |
| `page` | integer | Page number |
| `per_page` | integer | Items per page |

### Response

```json
{
    "data": [
        {
            "id": 1,
            "product_variant": {
                "id": 1,
                "sku": "TSHIRT-S-BLU",
                "product": {
                    "id": 1,
                    "title": "Classic T-Shirt"
                }
            },
            "warehouse": {
                "id": 1,
                "name": "Main Warehouse",
                "code": "MAIN"
            },
            "quantity": 50,
            "reserved_quantity": 5,
            "available": 45,
            "reorder_point": 10,
            "reorder_quantity": 50,
            "unit_cost": "12.00",
            "bin_location": "A-12-3",
            "needs_reorder": false,
            "updated_at": "2024-01-15T10:00:00Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "total": 150
    }
}
```

---

## Get Inventory for Variant

```bash
GET /api/v1/inventory/1
Authorization: Bearer {token}
X-Store-Id: 123
```

### Response

```json
{
    "data": {
        "variant": {
            "id": 1,
            "sku": "TSHIRT-S-BLU",
            "product": {
                "id": 1,
                "title": "Classic T-Shirt"
            }
        },
        "total_quantity": 80,
        "total_available": 70,
        "total_reserved": 10,
        "locations": [
            {
                "warehouse_id": 1,
                "warehouse_name": "Main Warehouse",
                "quantity": 50,
                "reserved": 5,
                "available": 45,
                "bin_location": "A-12-3"
            },
            {
                "warehouse_id": 2,
                "warehouse_name": "East Coast",
                "quantity": 30,
                "reserved": 5,
                "available": 25,
                "bin_location": "B-05-1"
            }
        ]
    }
}
```

---

## Adjust Inventory

Add or remove inventory (relative change):

```bash
POST /api/v1/inventory/adjust
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "variant_id": 1,
    "warehouse_id": 1,
    "adjustment": -5,
    "reason": "damaged",
    "reference": "ADJ-001",
    "notes": "Items damaged during shipping"
}
```

### Response

```json
{
    "data": {
        "previous_quantity": 50,
        "adjustment": -5,
        "new_quantity": 45,
        "inventory": {
            "id": 1,
            "quantity": 45,
            ...
        }
    },
    "message": "Inventory adjusted successfully"
}
```

### Adjustment Reasons

| Reason | Description |
|--------|-------------|
| `received` | Stock received from supplier |
| `returned` | Customer return |
| `damaged` | Damaged/defective items |
| `lost` | Lost or missing inventory |
| `correction` | Manual count correction |
| `other` | Other reason (specify in notes) |

---

## Set Inventory

Set inventory to a specific level (absolute):

```bash
POST /api/v1/inventory/set
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "variant_id": 1,
    "warehouse_id": 1,
    "quantity": 100,
    "reason": "correction",
    "reference": "COUNT-001",
    "notes": "Physical inventory count"
}
```

### Response

```json
{
    "data": {
        "previous_quantity": 45,
        "new_quantity": 100,
        "inventory": {
            "id": 1,
            "quantity": 100,
            ...
        }
    },
    "message": "Inventory set successfully"
}
```

---

## List Warehouses

```bash
GET /api/v1/warehouses
Authorization: Bearer {token}
X-Store-Id: 123
```

### Response

```json
{
    "data": [
        {
            "id": 1,
            "name": "Main Warehouse",
            "code": "MAIN",
            "address": "123 Warehouse St",
            "city": "New York",
            "state": "NY",
            "zip": "10001",
            "country_code": "US",
            "is_default": true,
            "is_active": true,
            "fulfills_orders": true,
            "accepts_transfers": true,
            "inventory_count": 150,
            "total_units": 5000
        }
    ]
}
```

---

## Create Warehouse

```bash
POST /api/v1/warehouses
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "name": "East Coast Fulfillment",
    "code": "EAST-01",
    "address": "456 Distribution Ave",
    "city": "Newark",
    "state": "NJ",
    "zip": "07102",
    "country_code": "US",
    "is_active": true,
    "fulfills_orders": true,
    "accepts_transfers": true
}
```

### Response

```json
{
    "data": {
        "id": 2,
        "name": "East Coast Fulfillment",
        ...
    },
    "message": "Warehouse created successfully"
}
```

---

## Stock Transfers

### List Transfers

```bash
GET /api/v1/transfers
Authorization: Bearer {token}
X-Store-Id: 123
```

### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | pending, in_transit, completed, cancelled |
| `from_warehouse_id` | integer | Source warehouse |
| `to_warehouse_id` | integer | Destination warehouse |

### Response

```json
{
    "data": [
        {
            "id": 1,
            "reference": "TRF-001",
            "from_warehouse": {
                "id": 1,
                "name": "Main Warehouse"
            },
            "to_warehouse": {
                "id": 2,
                "name": "East Coast"
            },
            "status": "pending",
            "items": [
                {
                    "variant_id": 1,
                    "sku": "TSHIRT-S-BLU",
                    "quantity": 20
                }
            ],
            "total_items": 1,
            "total_units": 20,
            "created_at": "2024-01-15T10:00:00Z",
            "completed_at": null
        }
    ]
}
```

### Create Transfer

```bash
POST /api/v1/transfers
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "from_warehouse_id": 1,
    "to_warehouse_id": 2,
    "notes": "Restocking East Coast",
    "items": [
        {
            "variant_id": 1,
            "quantity": 20
        },
        {
            "variant_id": 2,
            "quantity": 15
        }
    ]
}
```

### Complete Transfer

```bash
POST /api/v1/transfers/1/complete
Authorization: Bearer {token}
X-Store-Id: 123
```

This deducts inventory from source and adds to destination.

---

## Low Stock Report

```bash
GET /api/v1/inventory?low_stock=true
Authorization: Bearer {token}
X-Store-Id: 123
```

Returns only items where `quantity <= reorder_point`.

---

## Validation Rules

### Inventory Adjustment

| Field | Rules |
|-------|-------|
| `variant_id` | required, exists:product_variants,id |
| `warehouse_id` | required, exists:warehouses,id |
| `adjustment` | required, integer, not zero |
| `reason` | required, in:received,returned,damaged,lost,correction,other |
| `reference` | nullable, string, max:100 |
| `notes` | nullable, string |

### Warehouse

| Field | Rules |
|-------|-------|
| `name` | required, string, max:255 |
| `code` | required, string, max:50, unique per store |
| `address` | nullable, string, max:255 |
| `city` | nullable, string, max:100 |
| `state` | nullable, string, max:100 |
| `zip` | nullable, string, max:20 |
| `country_code` | string, size:2 |
| `is_active` | boolean |
| `fulfills_orders` | boolean |
| `accepts_transfers` | boolean |

---

## Error Responses

### 400 Bad Request

```json
{
    "message": "Cannot adjust inventory below zero",
    "current_quantity": 5,
    "requested_adjustment": -10
}
```

### 409 Conflict

```json
{
    "message": "Cannot delete warehouse with inventory. Transfer or clear inventory first."
}
```
