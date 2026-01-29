# Multi-Tenancy

Shopmata uses a single-database multi-tenant architecture where each store's data is isolated by `store_id` foreign keys.

## Store Context

The `StoreContext` service is a singleton that holds the current store for each request.

```php
// app/Services/StoreContext.php
class StoreContext
{
    protected ?Store $currentStore = null;
    protected ?int $currentStoreId = null;

    public function setCurrentStore(Store $store): void;
    public function setCurrentStoreId(int $storeId): void;
    public function getCurrentStore(): ?Store;
    public function getCurrentStoreId(): ?int;
    public function hasStore(): bool;
    public function clear(): void;
}
```

### How Store Context is Set

The `SetCurrentStore` middleware determines the current store in this priority:

1. **X-Store-Id Header** (API requests)
   ```bash
   curl -H "X-Store-Id: 123" https://api.shopmata.com/v1/products
   ```

2. **Subdomain** (if configured)
   ```
   mystore.shopmata.com → resolves to store with slug "mystore"
   ```

3. **Session** (web requests)
   ```php
   $request->session()->get('current_store_id')
   ```

4. **User's Current Store**
   ```php
   $user->current_store_id
   ```

5. **User's First Store** (fallback)

### Using Store Context

```php
// In a controller
class ProductController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index()
    {
        $store = $this->storeContext->getCurrentStore();

        if (!$store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $products = Product::where('store_id', $store->id)->get();
        // ...
    }
}
```

### In Eloquent Queries

Always scope queries to the current store:

```php
// Good - explicit store scope
$products = Product::where('store_id', $store->id)
    ->where('is_published', true)
    ->get();

// Bad - no store scope (security risk!)
$products = Product::where('is_published', true)->get();
```

## Data Isolation

### Store-Scoped Tables

These tables have a `store_id` column:

| Table | Description |
|-------|-------------|
| `products` | Product catalog |
| `product_variants` | Product SKUs (via product) |
| `categories` | Product categories |
| `brands` | Product brands |
| `templates` | Product templates |
| `warehouses` | Inventory locations |
| `inventory` | Stock levels |
| `orders` | Customer orders |
| `customers` | Customer records |
| `roles` | Permission roles |
| `store_users` | Team members |

### Non-Scoped Tables

These tables are shared across stores:

| Table | Description |
|-------|-------------|
| `users` | User accounts (can access multiple stores) |
| `countries` | Reference data |
| `currencies` | Reference data |

## User and Store Relationship

```
User (1) ─────────── (N) Store     [owns stores via user_id]
  │
  └── (N) StoreUser (N) ─── (1) Store   [membership via pivot]
            │
            └── (1) Role              [permissions]
```

### User Model Relationships

```php
class User extends Model
{
    // Stores the user owns
    public function ownedStores(): HasMany
    {
        return $this->hasMany(Store::class, 'user_id');
    }

    // Stores the user is a member of
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_users')
            ->withPivot(['role_id', 'status', 'is_owner']);
    }

    // All store memberships
    public function storeUsers(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    // Current store (from context)
    public function currentStore(): ?Store
    {
        return app(StoreContext::class)->getCurrentStore();
    }
}
```

## Switching Stores

Users can switch between stores they have access to:

```php
// StoreController
public function switch(Request $request, Store $store): RedirectResponse
{
    $user = $request->user();

    // Verify access
    $hasAccess = StoreUser::where('user_id', $user->id)
        ->where('store_id', $store->id)
        ->exists();

    if (!$hasAccess && $store->user_id !== $user->id) {
        return back()->with('error', 'Access denied.');
    }

    // Update user's current store
    $user->update(['current_store_id' => $store->id]);

    // Update session
    $request->session()->put('current_store_id', $store->id);

    return redirect()->route('dashboard');
}
```

## Creating a New Store

When a store is created, these are auto-generated:

1. **Default Roles** - Owner, Admin, Manager, Staff, Viewer
2. **Owner StoreUser** - Links creator as owner with owner role
3. **Default Warehouse** - "Main Warehouse" with store address
4. **Categories** - Based on industry selection
5. **Sample Products** - Optional, based on industry

```php
// StoreController::store()
$store = Store::create([...]);

Role::createDefaultRoles($store->id);

$ownerRole = Role::where('store_id', $store->id)
    ->where('slug', 'owner')
    ->first();

StoreUser::create([
    'user_id' => $user->id,
    'store_id' => $store->id,
    'role_id' => $ownerRole->id,
    'is_owner' => true,
    'status' => 'active',
]);

Warehouse::create([
    'store_id' => $store->id,
    'name' => 'Main Warehouse',
    'is_default' => true,
]);
```

## Security Considerations

### Always Scope Queries

```php
// In controllers, always verify store ownership
$product = Product::where('store_id', $store->id)
    ->findOrFail($id);

// Never trust route model binding alone for store-scoped models
// Bad:
Route::get('products/{product}', ...); // Could access other store's products

// Good:
public function show(Product $product)
{
    if ($product->store_id !== $this->storeContext->getCurrentStoreId()) {
        abort(404);
    }
}
```

### Middleware Protection

Use the `store` middleware to ensure store context exists:

```php
Route::middleware(['auth', 'store'])->group(function () {
    Route::resource('products', ProductController::class);
});
```

### API Store Header

For API requests, require the `X-Store-Id` header:

```php
// In API controller
$storeId = $request->header('X-Store-Id');
if (!$storeId) {
    return response()->json(['error' => 'X-Store-Id header required'], 400);
}
```
