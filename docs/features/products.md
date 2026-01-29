# Products & Variants

## Overview

Products are the core of Shopmata's catalog system. Each product can have multiple variants (SKUs) with their own pricing, inventory, and attributes.

```
Product
├── Title, Description, Handle
├── Category, Brand, Template
├── Status (draft, active, archived)
└── Variants[]
    ├── SKU, Barcode
    ├── Price, Cost
    ├── Options (Size, Color, etc.)
    └── Inventory (per warehouse)
```

---

## Product Fields

### Basic Information
| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Product name |
| `description` | text | Rich text description |
| `handle` | string | URL slug (auto-generated) |
| `status` | enum | draft, active, archived |
| `is_published` | boolean | Visible in storefront |

### Organization
| Field | Type | Description |
|-------|------|-------------|
| `category_id` | foreign key | Primary category |
| `brand_id` | foreign key | Product brand |
| `template_id` | foreign key | Attribute template |
| `vendor` | string | Supplier/vendor name |
| `product_type` | string | Product classification |

### Inventory Settings
| Field | Type | Description |
|-------|------|-------------|
| `track_quantity` | boolean | Enable inventory tracking |
| `continue_selling` | boolean | Allow overselling |
| `requires_shipping` | boolean | Physical product flag |
| `weight` | decimal | Shipping weight |
| `weight_unit` | enum | kg, lb, oz, g |

---

## Product Variants

Every product has at least one variant. Variants represent purchasable SKUs.

### Variant Fields
| Field | Type | Description |
|-------|------|-------------|
| `sku` | string | Stock keeping unit |
| `barcode` | string | UPC/EAN/ISBN |
| `price` | decimal | Selling price |
| `compare_at_price` | decimal | Original price (for sales) |
| `cost` | decimal | Cost of goods |
| `quantity` | integer | Available stock |

### Variant Options
Products can have up to 3 option types:

```php
// Example: T-Shirt with Size and Color
$variant = [
    'option1_name' => 'Size',
    'option1_value' => 'Medium',
    'option2_name' => 'Color',
    'option2_value' => 'Blue',
    'option3_name' => null,
    'option3_value' => null,
];
```

---

## Creating Products

### Via Web Interface

1. Navigate to **Products > Add Product**
2. Fill in product details
3. Add variants (or use default single variant)
4. Set pricing and inventory
5. Assign category and template
6. Save as draft or publish

### Via API

```bash
POST /api/v1/products
X-Store-Id: 123
Authorization: Bearer {token}

{
    "title": "Classic T-Shirt",
    "description": "Comfortable cotton t-shirt",
    "category_id": 1,
    "track_quantity": true,
    "variants": [
        {
            "sku": "TSHIRT-S-BLU",
            "price": 29.99,
            "option1_name": "Size",
            "option1_value": "Small",
            "option2_name": "Color",
            "option2_value": "Blue"
        },
        {
            "sku": "TSHIRT-M-BLU",
            "price": 29.99,
            "option1_name": "Size",
            "option1_value": "Medium",
            "option2_name": "Color",
            "option2_value": "Blue"
        }
    ]
}
```

---

## Product Status Lifecycle

```
draft ──────► active ──────► archived
  │              │               │
  │              ▼               │
  └──────► (deleted) ◄──────────┘
```

| Status | Description |
|--------|-------------|
| `draft` | Work in progress, not visible |
| `active` | Published and available for sale |
| `archived` | Hidden but preserved for records |

---

## Searching & Filtering

### Available Filters
- Status (draft, active, archived)
- Category
- Brand
- Vendor
- Product type
- Has variants
- Track quantity enabled
- Published state

### Search Fields
- Title
- SKU (across all variants)
- Barcode
- Vendor
- Description

---

## Bulk Operations

### Bulk Edit
Select multiple products to:
- Change status
- Assign category
- Update vendor
- Add/remove tags

### Bulk Delete
Archive or permanently delete selected products.

### Import/Export
- CSV import for bulk creation
- CSV/Excel export for reporting

---

## Product Templates

Products can use templates that define:
- Required custom attributes
- Default values
- Validation rules

See [Categories & Templates](./categories-templates.md) for details.

---

## Permissions

| Permission | Description |
|------------|-------------|
| `products.view` | View product list and details |
| `products.create` | Create new products |
| `products.update` | Edit existing products |
| `products.delete` | Delete/archive products |
| `products.export` | Export product data |
| `products.import` | Import products from file |
