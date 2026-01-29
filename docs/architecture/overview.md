# Architecture Overview

## System Design

Shopmata follows a modular monolith architecture using Laravel with Inertia.js for the frontend.

```
┌─────────────────────────────────────────────────────────────────┐
│                         Client Layer                             │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐  │
│  │   Vue 3 SPA     │  │   Mobile App    │  │  External APIs  │  │
│  │   (Inertia)     │  │   (Future)      │  │   (Webhooks)    │  │
│  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘  │
└───────────┼─────────────────────┼─────────────────────┼──────────┘
            │                     │                     │
┌───────────┼─────────────────────┼─────────────────────┼──────────┐
│           ▼                     ▼                     ▼          │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │                      Laravel Application                     │ │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │ │
│  │  │ Web Routes   │  │ API Routes   │  │ Webhooks     │       │ │
│  │  │ (Inertia)    │  │ (REST/JSON)  │  │ (Incoming)   │       │ │
│  │  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘       │ │
│  │         │                 │                 │                │ │
│  │         ▼                 ▼                 ▼                │ │
│  │  ┌─────────────────────────────────────────────────────┐    │ │
│  │  │              Middleware Layer                        │    │ │
│  │  │  • Authentication (Fortify/Passport)                 │    │ │
│  │  │  • Store Context (SetCurrentStore)                   │    │ │
│  │  │  • Permission Check (CheckPermission)                │    │ │
│  │  └─────────────────────────────────────────────────────┘    │ │
│  │                          │                                   │ │
│  │                          ▼                                   │ │
│  │  ┌─────────────────────────────────────────────────────┐    │ │
│  │  │              Controller Layer                        │    │ │
│  │  │  • Web Controllers (return Inertia responses)        │    │ │
│  │  │  • API Controllers (return JSON responses)           │    │ │
│  │  └─────────────────────────────────────────────────────┘    │ │
│  │                          │                                   │ │
│  │                          ▼                                   │ │
│  │  ┌─────────────────────────────────────────────────────┐    │ │
│  │  │              Service Layer                           │    │ │
│  │  │  • StoreContext (multi-tenancy)                      │    │ │
│  │  │  • Business Logic Services                           │    │ │
│  │  └─────────────────────────────────────────────────────┘    │ │
│  │                          │                                   │ │
│  │                          ▼                                   │ │
│  │  ┌─────────────────────────────────────────────────────┐    │ │
│  │  │              Model Layer (Eloquent)                  │    │ │
│  │  │  • Store-scoped models                               │    │ │
│  │  │  • Relationships & Accessors                         │    │ │
│  │  └─────────────────────────────────────────────────────┘    │ │
│  └─────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│                        Data Layer                                 │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐   │
│  │     MySQL       │  │     Redis       │  │   File Storage  │   │
│  │   (Primary)     │  │   (Cache/Queue) │  │   (S3/Local)    │   │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘   │
└──────────────────────────────────────────────────────────────────┘
```

## Core Components

### 1. Store Context Service
The `StoreContext` service manages multi-tenancy by tracking the current store for each request.

```php
// app/Services/StoreContext.php
class StoreContext
{
    public function setCurrentStore(Store $store): void;
    public function getCurrentStore(): ?Store;
    public function getCurrentStoreId(): ?int;
}
```

### 2. Permission System
Permissions are defined as constants in `Activity` model and checked via middleware or gates.

```php
// Check in controller
Gate::authorize(Activity::REPORTS_VIEW_SALES);

// Check in route
Route::get('reports')->middleware('permission:reports.view_sales');

// Check in Blade
@can('reports.view_sales')
```

### 3. Inertia.js Integration
Pages are Vue components that receive props from Laravel controllers.

```php
// Controller
return Inertia::render('products/Index', [
    'products' => $products,
    'categories' => $categories,
]);
```

```vue
<!-- Vue Page -->
<script setup lang="ts">
interface Props {
    products: Product[];
    categories: Category[];
}
const props = defineProps<Props>();
</script>
```

## Data Flow

### Web Request Flow
```
Browser → Web Route → Middleware → Controller → Service → Model → DB
                                       ↓
                                 Inertia::render()
                                       ↓
                               Vue Component
```

### API Request Flow
```
Client → API Route → Middleware → Controller → Service → Model → DB
                                       ↓
                               JSON Response
```

## Database Schema (Key Tables)

```
stores
├── id, name, slug, user_id (owner)
├── address, city, state, zip
└── is_active, created_at

store_users (pivot)
├── user_id, store_id, role_id
├── is_owner, status
└── first_name, last_name, email

roles
├── store_id, name, slug
├── permissions (JSON array)
└── is_system, is_default

products
├── store_id, category_id
├── title, description, handle
├── status, is_published
└── track_quantity

product_variants
├── product_id, sku, barcode
├── price, cost, quantity
└── option1_name, option1_value, etc.

inventory
├── store_id, product_variant_id, warehouse_id
├── quantity, reserved_quantity
├── reorder_point, unit_cost
└── bin_location

warehouses
├── store_id, name, code
├── address fields
├── is_default, is_active
└── fulfills_orders, accepts_transfers

categories
├── store_id, parent_id
├── name, slug, position
├── template_id
└── is_active
```

## Design Decisions

### Why Inertia.js?
- Server-side routing with SPA-like experience
- No need for separate API for frontend
- Full Laravel integration (sessions, validation, etc.)
- TypeScript support with type generation

### Why Multi-Tenant with Store Context?
- Simple implementation without database-per-tenant complexity
- Easy to add cross-store features (user can manage multiple stores)
- Shared codebase and infrastructure

### Why Permission Middleware vs Policies?
- Permissions are store-scoped, not model-scoped
- Simple slug-based checks work well for feature access
- Gates added for flexibility in controllers and views
