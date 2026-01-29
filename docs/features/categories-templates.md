# Categories & Templates

## Categories

Categories organize products into a hierarchical structure. Each store has its own category tree.

### Category Structure

```
Electronics (parent)
├── Phones
│   ├── Smartphones
│   └── Feature Phones
├── Computers
│   ├── Laptops
│   └── Desktops
└── Accessories
```

### Category Fields

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Category name |
| `slug` | string | URL-friendly identifier |
| `parent_id` | foreign key | Parent category (null for root) |
| `position` | integer | Sort order within parent |
| `template_id` | foreign key | Default template for products |
| `is_active` | boolean | Visibility flag |
| `description` | text | Category description |
| `image_url` | string | Category image |

### Creating Categories

```php
// Via controller
$category = Category::create([
    'store_id' => $store->id,
    'name' => 'Smartphones',
    'slug' => 'smartphones',
    'parent_id' => $electronicsCategory->id,
    'position' => 1,
    'is_active' => true,
]);
```

### Category Hierarchy

```php
// Get root categories
$roots = Category::where('store_id', $storeId)
    ->whereNull('parent_id')
    ->orderBy('position')
    ->get();

// Get children
$children = $category->children()->orderBy('position')->get();

// Get ancestors (breadcrumb)
$ancestors = $category->ancestors();

// Get all descendants
$descendants = $category->descendants();
```

---

## Templates

Templates define custom attributes for products in a category. When a product is assigned to a category with a template, it inherits those attribute definitions.

### Template Structure

```
Jewelry Template
├── Attributes
│   ├── Metal Type (select: Gold, Silver, Platinum)
│   ├── Gemstone (select: Diamond, Ruby, Sapphire, None)
│   ├── Carat Weight (number)
│   └── Certification (text)
└── Options
    ├── Require all fields: false
    └── Show on product page: true
```

### Template Fields

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Template name |
| `slug` | string | Identifier |
| `description` | text | Template purpose |
| `attributes` | JSON | Attribute definitions |
| `is_active` | boolean | Availability flag |

### Attribute Definition Schema

```json
{
    "attributes": [
        {
            "name": "Metal Type",
            "slug": "metal_type",
            "type": "select",
            "options": ["Gold", "Silver", "Platinum", "Rose Gold"],
            "required": true,
            "default": null,
            "help_text": "Primary metal used"
        },
        {
            "name": "Carat Weight",
            "slug": "carat_weight",
            "type": "number",
            "min": 0,
            "max": 100,
            "step": 0.01,
            "required": false,
            "unit": "ct"
        },
        {
            "name": "Certificate Number",
            "slug": "certificate_number",
            "type": "text",
            "max_length": 50,
            "required": false
        }
    ]
}
```

### Attribute Types

| Type | Description | Options |
|------|-------------|---------|
| `text` | Single-line text | max_length |
| `textarea` | Multi-line text | max_length, rows |
| `number` | Numeric value | min, max, step, unit |
| `select` | Dropdown selection | options[] |
| `multiselect` | Multiple selection | options[] |
| `checkbox` | Boolean toggle | - |
| `date` | Date picker | min_date, max_date |
| `color` | Color picker | - |

---

## Using Templates

### Assigning to Category

```php
$category->update(['template_id' => $template->id]);
```

### Product Attributes

When a product uses a template, its custom attributes are stored in the `attributes` JSON column:

```php
$product = Product::create([
    'title' => 'Diamond Engagement Ring',
    'category_id' => $jewelryCategory->id,
    'attributes' => [
        'metal_type' => 'Platinum',
        'gemstone' => 'Diamond',
        'carat_weight' => 1.5,
        'certification' => 'GIA-12345678',
    ],
]);
```

### Validation

Template attributes are validated when saving products:

```php
// In ProductController
$validated = $request->validate([
    'title' => 'required|string|max:255',
    'attributes' => 'array',
    'attributes.metal_type' => 'required|in:Gold,Silver,Platinum,Rose Gold',
    'attributes.carat_weight' => 'nullable|numeric|min:0|max:100',
]);
```

---

## Industry Presets

When creating a store, selecting an industry auto-generates relevant categories:

| Industry | Categories |
|----------|------------|
| Jewelry | Rings, Necklaces, Bracelets, Earrings, Watches |
| Electronics | Phones, Computers, Tablets, Accessories, Audio |
| Clothing | Men's, Women's, Kids, Shoes, Accessories |
| Home & Garden | Furniture, Decor, Kitchen, Outdoor, Bedding |
| Sports | Equipment, Apparel, Footwear, Accessories, Outdoor |
| Beauty | Skincare, Makeup, Hair Care, Fragrance, Tools |
| Toys & Games | Action Figures, Board Games, Educational, Outdoor, Puzzles |

---

## Permissions

| Permission | Description |
|------------|-------------|
| `categories.view` | View categories |
| `categories.create` | Create categories |
| `categories.update` | Edit categories |
| `categories.delete` | Delete categories |
| `templates.view` | View templates |
| `templates.create` | Create templates |
| `templates.update` | Edit templates |
| `templates.delete` | Delete templates |
