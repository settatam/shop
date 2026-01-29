# Store Setup

## Creating a Store

### Store Creation Wizard

When creating a new store, users go through a multi-step wizard:

#### Step 1: Store Information
- **Store Name** (required) - The display name for the store
- **Store Slug** - URL-friendly identifier (auto-generated from name)

#### Step 2: Address
- Address Line 1
- Address Line 2
- City
- State/Province
- ZIP/Postal Code
- Country

#### Step 3: Industry Selection
Choose from predefined industries to auto-generate relevant categories:

| Industry | Auto-Generated Categories |
|----------|---------------------------|
| Jewelry | Rings, Necklaces, Bracelets, Earrings, Watches |
| Electronics | Phones, Computers, Tablets, Accessories, Audio |
| Clothing | Men's, Women's, Kids, Shoes, Accessories |
| Home & Garden | Furniture, Decor, Kitchen, Outdoor, Bedding |
| Sports | Equipment, Apparel, Footwear, Accessories, Outdoor |
| Beauty | Skincare, Makeup, Hair Care, Fragrance, Tools |
| Toys & Games | Action Figures, Board Games, Educational, Outdoor, Puzzles |
| Other | Products, Services |

**Sample Data Option**: Check "Create sample products" to generate demo products.

#### Step 4: Review
Confirm all details before creating the store.

### Skip Option
Users can skip the wizard and create a basic store with just a name.

---

## What Gets Created

When a store is created, these are automatically generated:

### 1. Default Roles
| Role | Permissions |
|------|-------------|
| Owner | `*` (all permissions) |
| Admin | Everything except store settings and team management |
| Manager | Products, orders, inventory, customers |
| Staff | View/update products and orders, basic inventory |
| Viewer | Read-only access |

### 2. Owner Membership
The creating user is added as owner with full permissions.

### 3. Default Warehouse
A "Main Warehouse" is created with the store's address.

### 4. Industry Categories
Categories based on the selected industry (see table above).

### 5. Sample Products (Optional)
Two sample products with:
- Product details (title, description, handle)
- Default variant with SKU and pricing
- Initial inventory in the main warehouse

---

## Store Settings

### General Settings
Access via **Settings > Store**:

- Store name
- Store slug
- Contact email
- Phone number
- Timezone
- Currency

### Address Settings
- Business address
- Billing address (if different)

### Branding
- Logo upload
- Favicon
- Brand colors

---

## Switching Stores

Users with access to multiple stores can switch between them:

1. Click the store name in the sidebar
2. Select from the dropdown list
3. Or click "Create new store" to add another

The current store is tracked via:
- Session storage (web)
- `X-Store-Id` header (API)
- User's `current_store_id` field

---

## Store Access Control

### Owner
- Full access to all features
- Can delete the store
- Can transfer ownership

### Team Members
Access determined by role assignment. See [Team & Roles](./team-roles.md).

### API Clients
Must include `X-Store-Id` header in all requests.
