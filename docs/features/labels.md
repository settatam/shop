# Label Printing System

The label printing system allows users to create custom label templates and print labels for products and transactions using Zebra printers or browser printing.

## Overview

- **Visual Designer**: Drag-and-drop label designer with real-time preview
- **Custom Sizes**: Support for any label size (203 DPI standard)
- **Store-wide Templates**: Templates are shared by all users in a store
- **Zebra Integration**: Direct printing to Zebra printers via Zebra Browser Print
- **CODE128 Barcodes**: Industry-standard barcode support

## Database Schema

### Label Templates

```
label_templates
├── id
├── store_id (foreign key)
├── name (unique per store)
├── type (product|transaction)
├── canvas_width (dots, default 406)
├── canvas_height (dots, default 203)
├── is_default (boolean)
├── timestamps
└── soft_deletes
```

### Label Template Elements

```
label_template_elements
├── id
├── label_template_id (foreign key)
├── element_type (text_field|barcode|static_text|line)
├── x, y (position in dots)
├── width, height (size in dots)
├── content (field key or static text)
├── styles (JSON: fontSize, alignment, etc.)
├── sort_order
└── timestamps
```

## Element Types

| Type | Description | Content Field |
|------|-------------|---------------|
| `text_field` | Dynamic text from product/transaction | Field key (e.g., `product.title`) |
| `barcode` | CODE128 barcode | Field key for barcode data |
| `static_text` | Fixed text | The text to display |
| `line` | Horizontal line | Not used |

## Available Fields

### Product Labels

| Field Key | Description |
|-----------|-------------|
| `product.title` | Product title |
| `variant.sku` | SKU |
| `variant.barcode` | Barcode value |
| `variant.price` | Formatted price |
| `variant.cost` | Formatted cost |
| `product.category` | Category name |
| `product.brand` | Brand name |
| `product.metal_type` | Metal type (jewelry) |
| `product.metal_purity` | Metal purity (jewelry) |

### Transaction Labels

| Field Key | Description |
|-----------|-------------|
| `transaction.transaction_number` | Transaction number |
| `transaction.type` | Transaction type |
| `transaction.status` | Current status |
| `transaction.bin_location` | Bin location |
| `customer.full_name` | Customer name |
| `transaction.final_offer` | Final offer amount |

## Routes

| Method | Route | Controller | Description |
|--------|-------|------------|-------------|
| GET | `/labels` | LabelTemplateController@index | List templates |
| GET | `/labels/create` | LabelTemplateController@create | Designer (new) |
| POST | `/labels` | LabelTemplateController@store | Create template |
| GET | `/labels/{label}/edit` | LabelTemplateController@edit | Designer (edit) |
| PUT | `/labels/{label}` | LabelTemplateController@update | Update template |
| DELETE | `/labels/{label}` | LabelTemplateController@destroy | Delete template |
| POST | `/labels/{label}/duplicate` | LabelTemplateController@duplicate | Duplicate |
| GET | `/print-labels/products` | LabelPrintController@products | Print products |
| POST | `/print-labels/products/zpl` | LabelPrintController@generateProductZpl | Generate ZPL |
| GET | `/print-labels/transactions` | LabelPrintController@transactions | Print transactions |
| POST | `/print-labels/transactions/zpl` | LabelPrintController@generateTransactionZpl | Generate ZPL |

## ZPL Generation

The `ZplGeneratorService` converts template elements to ZPL commands:

```php
// Text field
^FO{x},{y}^FB{width},1,0,{alignment},0^A0N,{fontSize},{fontSize}^FD{value}^FS

// Barcode (CODE128)
^FO{x},{y}^BY{moduleWidth},2,{height}^BCN,,{showText},N,N^FD{value}^FS

// Line
^FO{x},{y}^GB{width},{thickness},{thickness}^FS
```

## Canvas Dimensions

- **DPI**: 203 (standard Zebra)
- **Calculation**: 1 inch = 203 dots
- **Common sizes**:
  - 2" x 1" = 406 x 203 dots
  - 2.25" x 1.25" = 457 x 254 dots
  - 3" x 2" = 609 x 406 dots

## Frontend Components

| File | Description |
|------|-------------|
| `pages/labels/Index.vue` | Template list with CRUD |
| `pages/labels/Designer.vue` | Visual drag-and-drop designer |
| `pages/labels/PrintProducts.vue` | Product label printing workflow |
| `pages/labels/PrintTransactions.vue` | Transaction label printing workflow |
| `composables/useLabelDesigner.ts` | Designer state management |
| `composables/useZebraPrint.ts` | Zebra Browser Print integration |

## Designer Features

- Drag-and-drop element positioning
- Resize elements
- Undo/redo history (Ctrl+Z/Y)
- Duplicate elements (Ctrl+D)
- Delete with keyboard
- Arrow key nudging
- Live preview with sample data
- Preset label sizes
- Custom dimensions

## Printing

### Zebra Direct Print

1. Requires Zebra Browser Print app installed
2. Connects via localhost:9100
3. Sends ZPL directly to printer

### Browser Print

1. Generates ZPL preview
2. Opens in new window
3. Uses browser print dialog

## Usage

1. Go to **Labels** in the sidebar
2. Click **Create template**
3. Set name, type, and label size
4. Add elements from the left panel
5. Position and style elements
6. Save template
7. Go to Products/Transactions
8. Select items and click **Print Labels**
9. Choose template and print

## Testing

```bash
php artisan test tests/Feature/LabelTemplateTest.php
```
