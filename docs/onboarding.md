# Onboarding Process Documentation

This document describes what happens when a user registers and completes the onboarding process. It serves as a reference for understanding the store initialization flow and ensuring all necessary defaults are created.

---

## Overview

The store setup process happens in two phases:

1. **Registration** - User account and store creation
2. **Onboarding Wizard** - Store configuration and default data creation

A store is marked as needing onboarding when `store.step < 2`. After completing onboarding, `store.step` is set to `2`.

---

## Phase 1: Registration

**File:** `app/Actions/Fortify/CreateNewUser.php`

When a user registers, the following records are created in a single transaction:

### Created Records

| Model | Description |
|-------|-------------|
| `User` | The user account with name, email, and password |
| `Store` | The user's store with name, slug, and account email |
| `Role` (5 records) | Default roles: Owner, Admin, Manager, Staff, Viewer |
| `StoreUser` | Links user to store as owner with the Owner role |

### Default Roles Created

| Role | Slug | System? | Default? | Description |
|------|------|---------|----------|-------------|
| Owner | `owner` | Yes | No | Full access to all features |
| Admin | `admin` | No | No | Administrative access |
| Manager | `manager` | No | No | Management access |
| Staff | `staff` | No | Yes | Standard staff access (default for new team members) |
| Viewer | `viewer` | No | No | Read-only access |

### Post-Registration State

- User's `current_store_id` is set to the new store
- Store's `step` is `null` or `0` (needs onboarding)
- Store has NO warehouse yet
- Store has NO categories yet
- Store has NO products yet

---

## Phase 2: Onboarding Wizard

**File:** `app/Http/Controllers/Web/OnboardingController.php`

The onboarding wizard collects:
- Product category selections (from eBay category tree)
- Business address information
- Option to skip category setup

### Created Records

| Model | Condition | Description |
|-------|-----------|-------------|
| `Warehouse` | Always (if none exist) | "Main Warehouse" with code "MAIN" |
| `LeadSource` | Always | 7 default lead sources for customer tracking |
| `NotificationTemplate` | Always | Default email/SMS templates |
| `Category` | If categories selected | Parent categories from eBay Level-1 |
| `Category` | If categories selected | Child categories from eBay Level-2 |
| `ProductTemplate` | If categories selected | One template per parent category |
| Store eBay Categories | If categories selected | Pivot records linking store to eBay categories |

### Default Warehouse Details

```php
Warehouse::create([
    'store_id' => $store->id,
    'name' => 'Main Warehouse',
    'code' => 'MAIN',
    'is_default' => true,
    'is_active' => true,        // implied default
    'fulfills_orders' => true,  // implied default
    'accepts_transfers' => true, // implied default
    'address_line1' => $validated['address_line1'] ?? null,
    'city' => $validated['city'] ?? null,
    'state' => $validated['state'] ?? null,
    'postal_code' => $validated['postal_code'] ?? null,
    'country' => $validated['country'] ?? 'US',
]);
```

### Default Lead Sources Created

| Name | Slug |
|------|------|
| Walk-in | `walk-in` |
| Online Ad | `online-ad` |
| Social Media | `social-media` |
| Referral | `referral` |
| Google Search | `google-search` |
| Email Campaign | `email-campaign` |
| Other | `other` |

### Post-Onboarding State

- Store's `step` is set to `2` (onboarding complete)
- Store's address fields are updated (if provided)
- Store has at least one warehouse (Main Warehouse)
- Store has 7 default lead sources
- Store has default notification templates
- Store may have categories and templates (if not skipped)

---

## Defaults NOT Currently Created (Candidates for Addition)

The following models have `createDefault` methods but are NOT yet called during onboarding:

### 1. Return Policy

**Model:** `App\Models\ReturnPolicy`

A default return policy for products.

**Status:** NOT created during onboarding
**Recommendation:** Consider creating a default "30-day return" policy

### 4. Notification Channels

**Model:** `App\Models\NotificationChannel`

Email/SMS channel configurations.

**Status:** NOT created during onboarding
**Recommendation:** Review if defaults are needed

---

## Recommended Onboarding Improvements

### Implemented (2026-01-25)

The following defaults are now created during onboarding:

```php
// Create default lead sources
LeadSource::createDefaultsForStore($store->id);

// Create default notification templates
NotificationTemplate::createDefaultTemplates($store->id);
```

### Priority 1: Nice-to-Have Defaults

Consider adding:

```php
// Create default return policy
ReturnPolicy::create([
    'store_id' => $store->id,
    'name' => 'Standard Return Policy',
    'description' => '30-day return policy for unused items',
    'days_to_return' => 30,
    'is_default' => true,
]);

// Create default bin location (for inventory organization)
BinLocation::create([
    'store_id' => $store->id,
    'warehouse_id' => $warehouse->id,
    'name' => 'Default',
    'code' => 'DEFAULT',
    'is_default' => true,
]);
```

---

## Testing Onboarding

**Test File:** `tests/Feature/OnboardingTest.php`

Key test scenarios:
- User can complete onboarding with category selections
- User can skip category selection
- Default warehouse is created
- Store step is updated to 2
- User is redirected to dashboard

### Running Tests

```bash
php artisan test --filter=OnboardingTest
```

---

## Related Files

| File | Purpose |
|------|---------|
| `app/Actions/Fortify/CreateNewUser.php` | User registration logic |
| `app/Http/Controllers/Web/OnboardingController.php` | Onboarding wizard |
| `app/Models/Role.php` | Role::createDefaultRoles() |
| `app/Models/LeadSource.php` | LeadSource::createDefaultsForStore() |
| `app/Models/NotificationTemplate.php` | NotificationTemplate::createDefaultTemplates() |
| `app/Http/Middleware/EnsureOnboardingComplete.php` | Redirects to onboarding if incomplete |
| `resources/js/pages/onboarding/Index.vue` | Onboarding UI |
| `tests/Feature/OnboardingTest.php` | Onboarding tests |

---

## Changelog

| Date | Change |
|------|--------|
| 2026-01-25 | Initial documentation created |
| 2026-01-25 | Added LeadSource and NotificationTemplate defaults to onboarding |

---

## TODO

- [x] Add LeadSource defaults to onboarding
- [x] Add NotificationTemplate defaults to onboarding
- [ ] Consider adding default ReturnPolicy
- [ ] Consider adding default BinLocation
- [ ] Add printer settings defaults (if applicable)
- [ ] Review if label template defaults are needed
