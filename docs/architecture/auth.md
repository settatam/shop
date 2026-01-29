# Authentication & Authorization

## Authentication

### Web Authentication (Laravel Fortify)
Web users authenticate via Laravel Fortify with support for:
- Email/password login
- Two-factor authentication (TOTP)
- Password reset
- Email verification

### API Authentication (Laravel Passport)
API clients authenticate using OAuth2 tokens via Laravel Passport.

```bash
# Request token
POST /oauth/token
{
    "grant_type": "password",
    "client_id": "...",
    "client_secret": "...",
    "username": "user@example.com",
    "password": "password"
}
```

For first-party apps, use the `CreateFreshApiToken` middleware which creates encrypted cookies.

---

## Authorization

### Permission System Overview

```
User → StoreUser → Role → Permissions
         ↓
   Current Store Context
```

1. **User** - The authenticated user
2. **StoreUser** - Links user to a store with a role
3. **Role** - Defines permissions for a position (Owner, Admin, Staff, etc.)
4. **Permissions** - Granular activity slugs

### Permission Definitions

Permissions are defined in `App\Models\Activity`:

```php
// Categories
public const CATEGORY_PRODUCTS = 'products';
public const CATEGORY_ORDERS = 'orders';
public const CATEGORY_INVENTORY = 'inventory';
public const CATEGORY_TEAM = 'team';
public const CATEGORY_REPORTS = 'reports';

// Individual permissions
public const PRODUCTS_VIEW = 'products.view';
public const PRODUCTS_CREATE = 'products.create';
public const PRODUCTS_UPDATE = 'products.update';
public const PRODUCTS_DELETE = 'products.delete';
// ... many more
```

### Default Roles

When a store is created, these roles are auto-generated:

| Role | Description | Key Permissions |
|------|-------------|-----------------|
| **Owner** | Store owner | `*` (all permissions) |
| **Admin** | Full access except billing | All except `store.*`, `team.manage*` |
| **Manager** | Operations manager | Products, orders, inventory, customers |
| **Staff** | Basic employee | View/update products & orders, basic inventory |
| **Viewer** | Read-only access | View-only for all modules |

### Checking Permissions

#### 1. In Controllers

```php
use App\Models\Activity;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function salesReport()
    {
        // Option 1: Gate (throws 403 if denied)
        Gate::authorize(Activity::REPORTS_VIEW_SALES);

        // Option 2: Check and handle manually
        if (Gate::denies('reports.view_sales')) {
            return redirect()->route('dashboard')
                ->with('error', 'Access denied');
        }

        // Option 3: Use the User model method
        if (!auth()->user()->hasPermission(Activity::REPORTS_VIEW_SALES)) {
            abort(403);
        }

        return Inertia::render('reports/Sales', [...]);
    }
}
```

#### 2. In Routes (Middleware)

```php
// Using custom permission middleware
Route::get('reports/sales', [ReportController::class, 'sales'])
    ->middleware('permission:reports.view_sales');

// Multiple permissions (user needs ANY of these)
Route::get('reports', [ReportController::class, 'index'])
    ->middleware('permission:reports.view_sales,reports.view_inventory');

// Using Laravel's can middleware
Route::get('reports/sales', [ReportController::class, 'sales'])
    ->middleware('can:reports.view_sales');
```

#### 3. In Blade/Vue Templates

**Blade:**
```blade
@can('reports.view_sales')
    <a href="{{ route('reports.sales') }}">Sales Report</a>
@endcan

@canany(['reports.view_sales', 'reports.view_inventory'])
    <a href="{{ route('reports.index') }}">Reports</a>
@endcanany
```

**Vue (via Inertia shared data):**

First, share permissions in `HandleInertiaRequests.php`:
```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
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

Then use in Vue:
```vue
<template>
    <Link v-if="$page.props.auth.can.viewReports" href="/reports">
        Reports
    </Link>
</template>
```

### Convenience Gates

In addition to individual permission gates, these convenience gates are defined:

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

### How Permission Checking Works

```php
// User model
public function hasPermission(string $permission): bool
{
    $storeUser = $this->currentStoreUser();
    return $storeUser?->hasPermission($permission) ?? false;
}

// StoreUser model
public function hasPermission(string $permission): bool
{
    return $this->role?->hasPermission($permission) ?? false;
}

// Role model
public function hasPermission(string $permission): bool
{
    // Check for wildcard (owner has all permissions)
    if (in_array('*', $this->permissions)) {
        return true;
    }

    // Check for exact match
    if (in_array($permission, $this->permissions)) {
        return true;
    }

    // Check for category wildcard (e.g., 'products.*')
    $category = explode('.', $permission)[0];
    if (in_array($category . '.*', $this->permissions)) {
        return true;
    }

    return false;
}
```

### Adding New Permissions

1. Add the constant to `Activity` model:
```php
public const REPORTS_VIEW_DASHBOARD = 'reports.view_dashboard';
```

2. Add the definition:
```php
self::REPORTS_VIEW_DASHBOARD => [
    'name' => 'View Dashboard Reports',
    'category' => self::CATEGORY_REPORTS,
    'description' => 'View dashboard analytics widgets',
],
```

3. The gate is automatically registered on boot.

4. Add to role presets if needed:
```php
'admin' => [
    'permissions' => [
        // ...existing permissions
        self::REPORTS_VIEW_DASHBOARD,
    ],
],
```

### Store Ownership

Store owners are determined by:
1. `Store.user_id` - The user who created the store
2. `StoreUser.is_owner` - Flag on the store user record
3. `Role.slug === 'owner'` - The owner role

```php
// Check if user is store owner
$user->isStoreOwner(); // Uses current store context

// In StoreUser
$storeUser->isOwner();
```
