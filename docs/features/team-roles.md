# Team & Roles

## Overview

Each store has its own team of users with role-based permissions. Users can belong to multiple stores with different roles in each.

```
Store
└── StoreUsers[] (team members)
    ├── User (account)
    └── Role (permissions)
```

---

## Team Members (StoreUser)

### StoreUser Fields

| Field | Type | Description |
|-------|------|-------------|
| `user_id` | foreign key | User account |
| `store_id` | foreign key | Store membership |
| `role_id` | foreign key | Assigned role |
| `is_owner` | boolean | Store owner flag |
| `status` | enum | active, pending, suspended |
| `first_name` | string | Display name |
| `last_name` | string | Display name |
| `email` | string | Contact email |

### Inviting Team Members

```php
// Create invitation
$storeUser = StoreUser::create([
    'store_id' => $store->id,
    'user_id' => $invitedUser->id,
    'role_id' => $staffRole->id,
    'status' => 'pending',
    'first_name' => $request->first_name,
    'last_name' => $request->last_name,
    'email' => $request->email,
]);

// Send invitation email
$storeUser->sendInvitation();
```

### Accepting Invitations

```php
// User accepts invitation
$storeUser->update(['status' => 'active']);
```

---

## Roles

### Role Fields

| Field | Type | Description |
|-------|------|-------------|
| `store_id` | foreign key | Owning store |
| `name` | string | Display name |
| `slug` | string | Identifier |
| `permissions` | JSON array | Permission slugs |
| `is_system` | boolean | Cannot be deleted |
| `is_default` | boolean | Assigned to new members |

### Default Roles

These roles are created automatically for each new store:

#### Owner
- **Permissions**: `*` (wildcard - all permissions)
- **Can**: Everything, including store deletion and ownership transfer

#### Admin
- **Permissions**: All except `store.*` and `team.manage*`
- **Can**: Full operational access without store-level settings

#### Manager
- **Permissions**: Products, orders, inventory, customers
- **Can**: Day-to-day operations

#### Staff
- **Permissions**: View/update products and orders, basic inventory
- **Can**: Basic tasks under supervision

#### Viewer
- **Permissions**: `*.view` only
- **Can**: Read-only access to all modules

---

## Permission System

### Permission Format

Permissions use dot notation: `{category}.{action}`

```
products.view
products.create
products.update
products.delete
orders.fulfill
inventory.transfer
```

### Permission Categories

| Category | Description |
|----------|-------------|
| `products` | Product catalog management |
| `orders` | Order processing |
| `inventory` | Stock management |
| `customers` | Customer data |
| `reports` | Analytics and reporting |
| `team` | Team member management |
| `store` | Store settings |
| `categories` | Category management |
| `templates` | Product templates |
| `warehouses` | Warehouse configuration |
| `integrations` | Third-party connections |

### All Permissions

Defined in `App\Models\Activity`:

```php
// Products
Activity::PRODUCTS_VIEW
Activity::PRODUCTS_CREATE
Activity::PRODUCTS_UPDATE
Activity::PRODUCTS_DELETE
Activity::PRODUCTS_EXPORT
Activity::PRODUCTS_IMPORT

// Orders
Activity::ORDERS_VIEW
Activity::ORDERS_CREATE
Activity::ORDERS_UPDATE
Activity::ORDERS_FULFILL
Activity::ORDERS_CANCEL
Activity::ORDERS_REFUND
Activity::ORDERS_EXPORT

// Inventory
Activity::INVENTORY_VIEW
Activity::INVENTORY_UPDATE
Activity::INVENTORY_TRANSFER

// Reports
Activity::REPORTS_VIEW_SALES
Activity::REPORTS_VIEW_INVENTORY
Activity::REPORTS_VIEW_CUSTOMERS
Activity::REPORTS_VIEW_ACTIVITY

// Team
Activity::TEAM_VIEW
Activity::TEAM_INVITE
Activity::TEAM_UPDATE
Activity::TEAM_REMOVE
Activity::TEAM_MANAGE_ROLES

// Store
Activity::STORE_VIEW_SETTINGS
Activity::STORE_UPDATE_SETTINGS
Activity::STORE_DELETE
```

---

## Checking Permissions

### In Controllers

```php
use App\Models\Activity;
use Illuminate\Support\Facades\Gate;

// Option 1: Gate::authorize (throws 403)
Gate::authorize(Activity::REPORTS_VIEW_SALES);

// Option 2: Gate check with custom handling
if (Gate::denies('reports.view_sales')) {
    return redirect()->back()->with('error', 'Access denied');
}

// Option 3: User model method
if (!auth()->user()->hasPermission(Activity::REPORTS_VIEW_SALES)) {
    abort(403);
}
```

### In Routes (Middleware)

```php
// Single permission
Route::get('reports/sales', [ReportController::class, 'sales'])
    ->middleware('permission:reports.view_sales');

// Multiple permissions (needs ANY)
Route::get('reports', [ReportController::class, 'index'])
    ->middleware('permission:reports.view_sales,reports.view_inventory');

// Using Laravel's can middleware
Route::get('reports/sales', [ReportController::class, 'sales'])
    ->middleware('can:reports.view_sales');
```

### In Blade Templates

```blade
@can('reports.view_sales')
    <a href="{{ route('reports.sales') }}">Sales Report</a>
@endcan

@canany(['reports.view_sales', 'reports.view_inventory'])
    <a href="{{ route('reports.index') }}">Reports</a>
@endcanany
```

### In Vue Templates

Permissions are shared via Inertia:

```php
// HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        'auth' => [
            'user' => $request->user(),
            'can' => [
                'viewReports' => $request->user()?->hasAnyPermission([
                    Activity::REPORTS_VIEW_SALES,
                    Activity::REPORTS_VIEW_INVENTORY,
                ]) ?? false,
                'manageTeam' => $request->user()?->hasPermission(
                    Activity::TEAM_MANAGE_ROLES
                ) ?? false,
            ],
        ],
    ];
}
```

```vue
<template>
    <Link v-if="$page.props.auth.can.viewReports" href="/reports">
        Reports
    </Link>
</template>
```

---

## Convenience Gates

In addition to individual permissions, these convenience gates are available:

```php
// Super admin (has wildcard *)
Gate::allows('super-admin')

// Can view any report
Gate::allows('view-reports')

// Can manage team members
Gate::allows('manage-team')

// Can manage products (create/update/delete)
Gate::allows('manage-products')

// Can manage orders
Gate::allows('manage-orders')
```

---

## Creating Custom Roles

```php
$role = Role::create([
    'store_id' => $store->id,
    'name' => 'Warehouse Staff',
    'slug' => 'warehouse-staff',
    'permissions' => [
        'inventory.view',
        'inventory.update',
        'inventory.transfer',
        'products.view',
        'warehouses.view',
    ],
    'is_system' => false,
    'is_default' => false,
]);
```

---

## Managing Team via API

### List Team Members
```bash
GET /api/v1/team
X-Store-Id: 123
```

### Invite Member
```bash
POST /api/v1/team/invite
X-Store-Id: 123
{
    "email": "new@example.com",
    "first_name": "John",
    "last_name": "Doe",
    "role_id": 5
}
```

### Update Member Role
```bash
PATCH /api/v1/team/{storeUserId}
X-Store-Id: 123
{
    "role_id": 3
}
```

### Remove Member
```bash
DELETE /api/v1/team/{storeUserId}
X-Store-Id: 123
```

---

## Permissions Required

| Permission | Description |
|------------|-------------|
| `team.view` | View team members |
| `team.invite` | Invite new members |
| `team.update` | Change member roles |
| `team.remove` | Remove members |
| `team.manage_roles` | Create/edit custom roles |
