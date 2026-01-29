# Shopmata Documentation

Shopmata is a multi-tenant e-commerce platform for managing products, inventory, orders, and integrations across multiple sales channels.

## Quick Links

### Architecture
- [Overview](./architecture/overview.md) - System architecture and design decisions
- [Authentication & Authorization](./architecture/auth.md) - How auth and permissions work
- [Multi-Tenancy](./architecture/multi-tenancy.md) - Store context and data isolation

### Features
- [Store Setup](./features/store-setup.md) - Creating and configuring stores
- [Products & Variants](./features/products.md) - Product management system
- [Categories & Templates](./features/categories-templates.md) - Organizing products with templates
- [Inventory & Warehouses](./features/inventory.md) - Stock management across locations
- [Team & Roles](./features/team-roles.md) - User management and permissions

### API Reference
- [Authentication](./api/authentication.md) - API authentication methods
- [Products API](./api/products.md) - Product endpoints
- [Categories API](./api/categories.md) - Category endpoints
- [Inventory API](./api/inventory.md) - Inventory endpoints
- [Webhooks](./api/webhooks.md) - Webhook integration

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 12, PHP 8.4 |
| Frontend | Vue 3, TypeScript, Inertia.js v2 |
| Styling | Tailwind CSS v4 |
| Database | MySQL |
| Auth | Laravel Fortify, Passport |
| Testing | PHPUnit |

## Getting Started

### Prerequisites
- PHP 8.4+
- Node.js 18+
- MySQL 8.0+
- Composer

### Installation

```bash
# Clone the repository
git clone <repo-url>
cd shopmata

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Copy environment file
cp .env.example .env

# Generate app key
php artisan key:generate

# Run migrations
php artisan migrate

# Build frontend assets
npm run build

# Start development server
composer run dev
```

### Running Tests

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/ProductTest.php

# Run with filter
php artisan test --filter=ProductTest
```

## Project Structure

```
shopmata/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/V1/        # API controllers
│   │   │   ├── Web/           # Web controllers (Inertia)
│   │   │   └── Settings/      # Settings controllers
│   │   └── Middleware/
│   ├── Models/                 # Eloquent models
│   ├── Services/               # Business logic services
│   └── Providers/
├── resources/
│   └── js/
│       ├── components/         # Vue components
│       ├── layouts/            # Layout components
│       ├── pages/              # Inertia pages
│       └── types/              # TypeScript types
├── routes/
│   ├── web.php                 # Web routes
│   ├── api.php                 # API routes
│   └── settings.php            # Settings routes
├── tests/
│   ├── Feature/                # Feature tests
│   └── Unit/                   # Unit tests
└── docs/                       # Documentation (you are here)
```

## Key Concepts

### Multi-Tenancy
Each store is isolated with its own products, inventory, categories, and team members. Users can belong to multiple stores with different roles.

### Store Context
The current store is determined by:
1. `X-Store-Id` header (API)
2. Session (Web)
3. User's `current_store_id`

### Permissions
Permissions are defined in `App\Models\Activity` and assigned to roles. The owner role has wildcard (`*`) permission for full access.

### Product Variants
Products have variants (SKUs) with their own pricing, inventory, and attributes. Each variant can have stock in multiple warehouses.
