# Categories API

## Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/categories` | List categories |
| GET | `/api/v1/categories/{id}` | Get single category |
| POST | `/api/v1/categories` | Create category |
| PUT | `/api/v1/categories/{id}` | Update category |
| DELETE | `/api/v1/categories/{id}` | Delete category |

---

## List Categories

```bash
GET /api/v1/categories
Authorization: Bearer {token}
X-Store-Id: 123
```

### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `parent_id` | integer | Filter by parent (null for root categories) |
| `is_active` | boolean | Filter by active status |
| `flat` | boolean | Return flat list (default: nested tree) |
| `with_count` | boolean | Include product counts |

### Response (Nested Tree - Default)

```json
{
    "data": [
        {
            "id": 1,
            "name": "Electronics",
            "slug": "electronics",
            "position": 1,
            "is_active": true,
            "product_count": 45,
            "children": [
                {
                    "id": 2,
                    "name": "Phones",
                    "slug": "phones",
                    "position": 1,
                    "is_active": true,
                    "product_count": 20,
                    "children": []
                },
                {
                    "id": 3,
                    "name": "Computers",
                    "slug": "computers",
                    "position": 2,
                    "is_active": true,
                    "product_count": 25,
                    "children": []
                }
            ]
        },
        {
            "id": 4,
            "name": "Clothing",
            "slug": "clothing",
            "position": 2,
            "is_active": true,
            "product_count": 80,
            "children": []
        }
    ]
}
```

### Response (Flat List)

```bash
GET /api/v1/categories?flat=true
```

```json
{
    "data": [
        {
            "id": 1,
            "name": "Electronics",
            "slug": "electronics",
            "parent_id": null,
            "position": 1,
            "is_active": true,
            "depth": 0
        },
        {
            "id": 2,
            "name": "Phones",
            "slug": "phones",
            "parent_id": 1,
            "position": 1,
            "is_active": true,
            "depth": 1
        }
    ]
}
```

---

## Get Category

```bash
GET /api/v1/categories/1
Authorization: Bearer {token}
X-Store-Id: 123
```

### Response

```json
{
    "data": {
        "id": 1,
        "name": "Electronics",
        "slug": "electronics",
        "description": "Electronic devices and accessories",
        "parent_id": null,
        "position": 1,
        "is_active": true,
        "image_url": "https://cdn.shopmata.com/categories/electronics.jpg",
        "template": {
            "id": 1,
            "name": "Electronics Template"
        },
        "ancestors": [],
        "children": [
            {
                "id": 2,
                "name": "Phones",
                "slug": "phones"
            },
            {
                "id": 3,
                "name": "Computers",
                "slug": "computers"
            }
        ],
        "product_count": 45,
        "created_at": "2024-01-10T10:00:00Z",
        "updated_at": "2024-01-15T10:00:00Z"
    }
}
```

---

## Create Category

```bash
POST /api/v1/categories
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "name": "Smartphones",
    "slug": "smartphones",
    "description": "Mobile smartphones",
    "parent_id": 2,
    "position": 1,
    "is_active": true,
    "template_id": 1
}
```

### Response

```json
{
    "data": {
        "id": 5,
        "name": "Smartphones",
        "slug": "smartphones",
        ...
    },
    "message": "Category created successfully"
}
```

---

## Update Category

```bash
PUT /api/v1/categories/5
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "name": "Smart Phones",
    "position": 2
}
```

### Response

```json
{
    "data": {
        "id": 5,
        "name": "Smart Phones",
        ...
    },
    "message": "Category updated successfully"
}
```

---

## Delete Category

```bash
DELETE /api/v1/categories/5
Authorization: Bearer {token}
X-Store-Id: 123
```

### Response

```json
{
    "message": "Category deleted successfully"
}
```

**Note:** Categories with products cannot be deleted. Reassign products first.

---

## Reorder Categories

```bash
POST /api/v1/categories/reorder
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "categories": [
        { "id": 4, "position": 1 },
        { "id": 1, "position": 2 }
    ]
}
```

### Response

```json
{
    "message": "Categories reordered successfully"
}
```

---

## Move Category

Move a category to a new parent:

```bash
POST /api/v1/categories/5/move
Authorization: Bearer {token}
X-Store-Id: 123
Content-Type: application/json

{
    "parent_id": 3,
    "position": 1
}
```

### Response

```json
{
    "data": {
        "id": 5,
        "parent_id": 3,
        ...
    },
    "message": "Category moved successfully"
}
```

---

## Get Category Products

```bash
GET /api/v1/categories/1/products
Authorization: Bearer {token}
X-Store-Id: 123
```

### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `include_children` | boolean | Include products from child categories |
| `page` | integer | Page number |
| `per_page` | integer | Items per page |

### Response

```json
{
    "data": [
        {
            "id": 1,
            "title": "iPhone 15",
            "handle": "iphone-15",
            ...
        }
    ],
    "meta": {
        "current_page": 1,
        "total": 20
    }
}
```

---

## Validation Rules

| Field | Rules |
|-------|-------|
| `name` | required, string, max:255 |
| `slug` | nullable, string, max:255, unique per store |
| `description` | nullable, string |
| `parent_id` | nullable, exists:categories,id |
| `position` | integer, min:0 |
| `is_active` | boolean |
| `template_id` | nullable, exists:templates,id |
| `image_url` | nullable, url |

---

## Error Responses

### 404 Not Found

```json
{
    "message": "Category not found"
}
```

### 422 Validation Error

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "name": ["The name field is required."],
        "parent_id": ["Cannot set category as its own parent."]
    }
}
```

### 409 Conflict

```json
{
    "message": "Cannot delete category with products. Reassign products first."
}
```
