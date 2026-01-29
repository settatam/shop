# Inventory & Warehouses

## Overview

Shopmata supports multi-warehouse inventory management. Each product variant can have stock in multiple locations.

```
Store
└── Warehouses[]
    └── Inventory[]
        ├── Product Variant
        ├── Quantity
        ├── Reserved
        └── Bin Location
```

---

## Warehouses

### Warehouse Fields

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Warehouse name |
| `code` | string | Short identifier |
| `address` | string | Street address |
| `city` | string | City |
| `state` | string | State/Province |
| `zip` | string | Postal code |
| `country_code` | string | ISO country code |
| `is_default` | boolean | Default for new inventory |
| `is_active` | boolean | Operational status |
| `fulfills_orders` | boolean | Ships customer orders |
| `accepts_transfers` | boolean | Receives stock transfers |

### Default Warehouse

When a store is created, a "Main Warehouse" is automatically created with:
- Store's address
- `is_default = true`
- `fulfills_orders = true`
- `accepts_transfers = true`

### Creating Warehouses

```php
$warehouse = Warehouse::create([
    'store_id' => $store->id,
    'name' => 'East Coast Fulfillment',
    'code' => 'EAST-01',
    'address' => '123 Warehouse St',
    'city' => 'New York',
    'state' => 'NY',
    'zip' => '10001',
    'country_code' => 'US',
    'is_active' => true,
    'fulfills_orders' => true,
]);
```

---

## Inventory Records

### Inventory Fields

| Field | Type | Description |
|-------|------|-------------|
| `product_variant_id` | foreign key | The SKU |
| `warehouse_id` | foreign key | Storage location |
| `quantity` | integer | Available stock |
| `reserved_quantity` | integer | Allocated to orders |
| `reorder_point` | integer | Low stock threshold |
| `reorder_quantity` | integer | Suggested reorder amount |
| `unit_cost` | decimal | Cost per unit |
| `bin_location` | string | Physical location (e.g., A-12-3) |

### Computed Values

```php
// Available for sale
$available = $inventory->quantity - $inventory->reserved_quantity;

// Needs reorder
$needsReorder = $inventory->quantity <= $inventory->reorder_point;
```

---

## Inventory Operations

### Adding Stock

```php
// Increase inventory
$inventory->increment('quantity', 50);

// Or with tracking
InventoryMovement::create([
    'inventory_id' => $inventory->id,
    'type' => 'receive',
    'quantity' => 50,
    'reference' => 'PO-12345',
    'notes' => 'Received from supplier',
]);
$inventory->increment('quantity', 50);
```

### Reserving Stock (Orders)

```php
// When order is placed
$inventory->increment('reserved_quantity', $orderQuantity);

// When order is fulfilled
$inventory->decrement('quantity', $orderQuantity);
$inventory->decrement('reserved_quantity', $orderQuantity);

// When order is cancelled
$inventory->decrement('reserved_quantity', $orderQuantity);
```

### Stock Transfers

Transfer inventory between warehouses:

```php
// Create transfer
$transfer = StockTransfer::create([
    'store_id' => $store->id,
    'from_warehouse_id' => $warehouseA->id,
    'to_warehouse_id' => $warehouseB->id,
    'status' => 'pending',
]);

// Add items
$transfer->items()->create([
    'product_variant_id' => $variant->id,
    'quantity' => 20,
]);

// Complete transfer
$transfer->complete(); // Moves stock between warehouses
```

---

## Inventory Levels by Warehouse

### View All Locations for a Variant

```php
$variant->inventory()
    ->with('warehouse')
    ->get()
    ->map(fn ($inv) => [
        'warehouse' => $inv->warehouse->name,
        'available' => $inv->quantity - $inv->reserved_quantity,
        'reserved' => $inv->reserved_quantity,
        'total' => $inv->quantity,
    ]);
```

### Total Stock Across Warehouses

```php
$totalStock = $variant->inventory()->sum('quantity');
$totalAvailable = $variant->inventory()
    ->selectRaw('SUM(quantity - reserved_quantity) as available')
    ->value('available');
```

---

## Low Stock Alerts

### Setting Reorder Points

```php
$inventory->update([
    'reorder_point' => 10,
    'reorder_quantity' => 50,
]);
```

### Querying Low Stock

```php
$lowStock = Inventory::where('store_id', $storeId)
    ->whereColumn('quantity', '<=', 'reorder_point')
    ->with(['productVariant.product', 'warehouse'])
    ->get();
```

---

## Inventory in Product Forms

When creating/editing products, inventory can be set per warehouse:

```vue
<template>
    <div v-for="warehouse in warehouses" :key="warehouse.id">
        <label>{{ warehouse.name }}</label>
        <input
            type="number"
            v-model="form.inventory[warehouse.id]"
            min="0"
        />
    </div>
</template>
```

The controller handles creating inventory records:

```php
foreach ($request->inventory as $warehouseId => $quantity) {
    if ($quantity > 0) {
        Inventory::create([
            'store_id' => $store->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouseId,
            'quantity' => $quantity,
        ]);
    }
}
```

---

## Inventory History

Track all inventory changes:

```php
InventoryMovement::create([
    'inventory_id' => $inventory->id,
    'type' => 'adjustment', // receive, ship, transfer, adjustment, return
    'quantity' => -5, // negative for decreases
    'reason' => 'damaged',
    'reference' => 'ADJ-001',
    'user_id' => auth()->id(),
]);
```

### Movement Types

| Type | Description |
|------|-------------|
| `receive` | Stock received from supplier |
| `ship` | Stock shipped for order |
| `transfer_out` | Sent to another warehouse |
| `transfer_in` | Received from another warehouse |
| `adjustment` | Manual correction |
| `return` | Customer return |
| `damage` | Damaged/lost stock |

---

## Permissions

| Permission | Description |
|------------|-------------|
| `inventory.view` | View inventory levels |
| `inventory.update` | Adjust stock quantities |
| `inventory.transfer` | Create stock transfers |
| `warehouses.view` | View warehouse list |
| `warehouses.create` | Create warehouses |
| `warehouses.update` | Edit warehouses |
| `warehouses.delete` | Delete warehouses |
