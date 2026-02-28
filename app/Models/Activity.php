<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Activity extends Model
{
    // Categories
    public const CATEGORY_PRODUCTS = 'products';

    public const CATEGORY_ORDERS = 'orders';

    public const CATEGORY_TRANSACTIONS = 'transactions';

    public const CATEGORY_INVENTORY = 'inventory';

    public const CATEGORY_CUSTOMERS = 'customers';

    public const CATEGORY_INTEGRATIONS = 'integrations';

    public const CATEGORY_STORE = 'store';

    public const CATEGORY_TEAM = 'team';

    public const CATEGORY_REPORTS = 'reports';

    public const CATEGORY_MEMOS = 'memos';

    public const CATEGORY_REPAIRS = 'repairs';

    public const CATEGORY_VENDORS = 'vendors';

    public const CATEGORY_BUCKETS = 'buckets';

    public const CATEGORY_LAYAWAYS = 'layaways';

    public const CATEGORY_APPRAISALS = 'appraisals';

    public const CATEGORY_LISTINGS = 'listings';

    // Product Activities
    public const PRODUCTS_VIEW = 'products.view';

    public const PRODUCTS_CREATE = 'products.create';

    public const PRODUCTS_UPDATE = 'products.update';

    public const PRODUCTS_DELETE = 'products.delete';

    public const PRODUCTS_EXPORT = 'products.export';

    public const PRODUCTS_IMPORT = 'products.import';

    public const PRODUCTS_MANAGE_VARIANTS = 'products.manage_variants';

    public const PRODUCTS_MANAGE_IMAGES = 'products.manage_images';

    public const PRODUCTS_MANAGE_PRICING = 'products.manage_pricing';

    public const PRODUCTS_PRICE_CHANGE = 'products.price_change';

    public const PRODUCTS_QUANTITY_CHANGE = 'products.quantity_change';

    // Category Activities
    public const CATEGORIES_VIEW = 'categories.view';

    public const CATEGORIES_CREATE = 'categories.create';

    public const CATEGORIES_UPDATE = 'categories.update';

    public const CATEGORIES_DELETE = 'categories.delete';

    // Template Activities
    public const TEMPLATES_VIEW = 'templates.view';

    public const TEMPLATES_CREATE = 'templates.create';

    public const TEMPLATES_UPDATE = 'templates.update';

    public const TEMPLATES_DELETE = 'templates.delete';

    // Order Activities
    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_CREATE = 'orders.create';

    public const ORDERS_UPDATE = 'orders.update';

    public const ORDERS_DELETE = 'orders.delete';

    public const ORDERS_CANCEL = 'orders.cancel';

    public const ORDERS_REFUND = 'orders.refund';

    public const ORDERS_FULFILL = 'orders.fulfill';

    public const ORDERS_COMPLETE = 'orders.complete';

    public const ORDERS_EXPORT = 'orders.export';

    public const ORDERS_MANAGE_SHIPPING = 'orders.manage_shipping';

    public const ORDERS_VIEW_FINANCIALS = 'orders.view_financials';

    public const ORDERS_DELETE_CLOSED = 'orders.delete_closed';

    // Transaction Activities
    public const TRANSACTIONS_VIEW = 'transactions.view';

    public const TRANSACTIONS_CREATE = 'transactions.create';

    public const TRANSACTIONS_UPDATE = 'transactions.update';

    public const TRANSACTIONS_DELETE = 'transactions.delete';

    public const TRANSACTIONS_SUBMIT_OFFER = 'transactions.submit_offer';

    public const TRANSACTIONS_ACCEPT_OFFER = 'transactions.accept_offer';

    public const TRANSACTIONS_DECLINE_OFFER = 'transactions.decline_offer';

    public const TRANSACTIONS_PROCESS_PAYMENT = 'transactions.process_payment';

    public const TRANSACTIONS_CANCEL = 'transactions.cancel';

    public const TRANSACTIONS_STATUS_CHANGE = 'transactions.status_change';

    public const TRANSACTIONS_UPDATE_PAYOUT_PREFERENCE = 'transactions.update_payout_preference';

    public const TRANSACTIONS_DELETE_CLOSED = 'transactions.delete_closed';

    // Inventory Activities
    public const INVENTORY_VIEW = 'inventory.view';

    public const INVENTORY_ADJUST = 'inventory.adjust';

    public const INVENTORY_TRANSFER = 'inventory.transfer';

    public const INVENTORY_VIEW_REPORTS = 'inventory.view_reports';

    public const INVENTORY_QUANTITY_MANUAL_ADJUST = 'inventory.quantity_manual_adjust';

    // Warehouse Activities
    public const WAREHOUSES_VIEW = 'warehouses.view';

    public const WAREHOUSES_CREATE = 'warehouses.create';

    public const WAREHOUSES_UPDATE = 'warehouses.update';

    public const WAREHOUSES_DELETE = 'warehouses.delete';

    // Customer Activities
    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_CREATE = 'customers.create';

    public const CUSTOMERS_UPDATE = 'customers.update';

    public const CUSTOMERS_DELETE = 'customers.delete';

    public const CUSTOMERS_EXPORT = 'customers.export';

    public const CUSTOMERS_VIEW_HISTORY = 'customers.view_history';

    public const CUSTOMERS_CHAT_LEAD = 'customers.chat_lead';

    // Integration Activities
    public const INTEGRATIONS_VIEW = 'integrations.view';

    public const INTEGRATIONS_CONNECT = 'integrations.connect';

    public const INTEGRATIONS_DISCONNECT = 'integrations.disconnect';

    public const INTEGRATIONS_SYNC = 'integrations.sync';

    public const INTEGRATIONS_MANAGE_SETTINGS = 'integrations.manage_settings';

    // Store Settings Activities
    public const STORE_VIEW_SETTINGS = 'store.view_settings';

    public const STORE_UPDATE_SETTINGS = 'store.update_settings';

    public const STORE_VIEW_BILLING = 'store.view_billing';

    public const STORE_MANAGE_BILLING = 'store.manage_billing';

    public const STORE_MANAGE_STATUSES = 'store.manage_statuses';

    // Team Activities
    public const TEAM_VIEW = 'team.view';

    public const TEAM_INVITE = 'team.invite';

    public const TEAM_UPDATE = 'team.update';

    public const TEAM_REMOVE = 'team.remove';

    public const TEAM_ACCEPT_INVITATION = 'team.accept_invitation';

    public const TEAM_TRANSFER_OWNERSHIP = 'team.transfer_ownership';

    public const TEAM_MANAGE_ROLES = 'team.manage_roles';

    // Role Activities
    public const ROLES_CREATE = 'roles.create';

    public const ROLES_UPDATE = 'roles.update';

    public const ROLES_DELETE = 'roles.delete';

    // Report Activities
    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_EXPORT = 'reports.export';

    // AI Activities
    public const AI_GENERATE_DESCRIPTIONS = 'ai.generate_descriptions';

    public const AI_CATEGORIZE = 'ai.categorize';

    public const AI_OPTIMIZE_PRICING = 'ai.optimize_pricing';

    // Memo Activities
    public const MEMOS_VIEW = 'memos.view';

    public const MEMOS_CREATE = 'memos.create';

    public const MEMOS_UPDATE = 'memos.update';

    public const MEMOS_DELETE = 'memos.delete';

    public const MEMOS_SEND_TO_VENDOR = 'memos.send_to_vendor';

    public const MEMOS_MARK_RECEIVED = 'memos.mark_received';

    public const MEMOS_PAYMENT_RECEIVED = 'memos.payment_received';

    public const MEMOS_CANCEL = 'memos.cancel';

    // Repair Activities
    public const REPAIRS_VIEW = 'repairs.view';

    public const REPAIRS_CREATE = 'repairs.create';

    public const REPAIRS_UPDATE = 'repairs.update';

    public const REPAIRS_DELETE = 'repairs.delete';

    public const REPAIRS_SEND_TO_VENDOR = 'repairs.send_to_vendor';

    public const REPAIRS_COMPLETE = 'repairs.complete';

    public const REPAIRS_PAYMENT_RECEIVED = 'repairs.payment_received';

    public const REPAIRS_CANCEL = 'repairs.cancel';

    // Appraisal Activities
    public const APPRAISALS_VIEW = 'appraisals.view';

    public const APPRAISALS_CREATE = 'appraisals.create';

    public const APPRAISALS_UPDATE = 'appraisals.update';

    public const APPRAISALS_DELETE = 'appraisals.delete';

    public const APPRAISALS_COMPLETE = 'appraisals.complete';

    public const APPRAISALS_PAYMENT_RECEIVED = 'appraisals.payment_received';

    public const APPRAISALS_CANCEL = 'appraisals.cancel';

    // Layaway Activities
    public const LAYAWAYS_VIEW = 'layaways.view';

    public const LAYAWAYS_CREATE = 'layaways.create';

    public const LAYAWAYS_UPDATE = 'layaways.update';

    public const LAYAWAYS_DELETE = 'layaways.delete';

    public const LAYAWAYS_PAYMENT_RECEIVED = 'layaways.payment_received';

    public const LAYAWAYS_PAYMENT_DUE_SOON = 'layaways.payment_due_soon';

    public const LAYAWAYS_PAYMENT_OVERDUE = 'layaways.payment_overdue';

    public const LAYAWAYS_COMPLETED = 'layaways.completed';

    public const LAYAWAYS_CANCELLED = 'layaways.cancelled';

    // Vendor Activities
    public const VENDORS_VIEW = 'vendors.view';

    public const VENDORS_CREATE = 'vendors.create';

    public const VENDORS_UPDATE = 'vendors.update';

    public const VENDORS_DELETE = 'vendors.delete';

    // Note Activities (polymorphic - can belong to any notable model)
    public const NOTES_CREATE = 'notes.create';

    public const NOTES_UPDATE = 'notes.update';

    public const NOTES_DELETE = 'notes.delete';

    // Bucket Activities
    public const BUCKETS_CREATE = 'buckets.create';

    public const BUCKETS_UPDATE = 'buckets.update';

    public const BUCKETS_DELETE = 'buckets.delete';

    public const BUCKETS_ITEM_ADDED = 'buckets.item_added';

    public const BUCKETS_ITEM_REMOVED = 'buckets.item_removed';

    public const BUCKETS_ITEM_SOLD = 'buckets.item_sold';

    // Listing Activities (Platform Listings)
    public const LISTINGS_PUBLISH = 'listings.publish';

    public const LISTINGS_UNLIST = 'listings.unlist';

    public const LISTINGS_RELIST = 'listings.relist';

    public const LISTINGS_DELETE = 'listings.delete';

    public const LISTINGS_SYNC = 'listings.sync';

    public const LISTINGS_STATUS_CHANGE = 'listings.status_change';

    public const LISTINGS_CREATED = 'listings.created';

    public const LISTINGS_UPDATE = 'listings.update';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'category',
        'group',
        'sort_order',
    ];

    /**
     * Get all defined activities with their metadata.
     */
    public static function getDefinitions(): array
    {
        return [
            // Products
            self::PRODUCTS_VIEW => ['name' => 'View Products', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'View product listings and details'],
            self::PRODUCTS_CREATE => ['name' => 'Create Products', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Add new products to the catalog'],
            self::PRODUCTS_UPDATE => ['name' => 'Update Products', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Edit existing product information'],
            self::PRODUCTS_DELETE => ['name' => 'Delete Products', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Remove products from the catalog'],
            self::PRODUCTS_EXPORT => ['name' => 'Export Products', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Export product data'],
            self::PRODUCTS_IMPORT => ['name' => 'Import Products', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Import products from file'],
            self::PRODUCTS_MANAGE_VARIANTS => ['name' => 'Manage Variants', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Create and edit product variants'],
            self::PRODUCTS_MANAGE_IMAGES => ['name' => 'Manage Images', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Upload and manage product images'],
            self::PRODUCTS_MANAGE_PRICING => ['name' => 'Manage Pricing', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Set and update product prices'],
            self::PRODUCTS_PRICE_CHANGE => ['name' => 'Price Changed', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Product price, wholesale price, or cost was changed'],
            self::PRODUCTS_QUANTITY_CHANGE => ['name' => 'Quantity Changed', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Product stock quantity was updated'],

            // Categories
            self::CATEGORIES_VIEW => ['name' => 'View Categories', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'View product categories'],
            self::CATEGORIES_CREATE => ['name' => 'Create Categories', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Add new categories'],
            self::CATEGORIES_UPDATE => ['name' => 'Update Categories', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Edit existing categories'],
            self::CATEGORIES_DELETE => ['name' => 'Delete Categories', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Remove categories'],

            // Templates
            self::TEMPLATES_VIEW => ['name' => 'View Templates', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'View product templates'],
            self::TEMPLATES_CREATE => ['name' => 'Create Templates', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Create new product templates'],
            self::TEMPLATES_UPDATE => ['name' => 'Update Templates', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Edit product templates'],
            self::TEMPLATES_DELETE => ['name' => 'Delete Templates', 'category' => self::CATEGORY_PRODUCTS, 'description' => 'Remove product templates'],

            // Orders
            self::ORDERS_VIEW => ['name' => 'View Orders', 'category' => self::CATEGORY_ORDERS, 'description' => 'View order listings and details'],
            self::ORDERS_CREATE => ['name' => 'Create Orders', 'category' => self::CATEGORY_ORDERS, 'description' => 'Create manual orders'],
            self::ORDERS_UPDATE => ['name' => 'Update Orders', 'category' => self::CATEGORY_ORDERS, 'description' => 'Edit order information'],
            self::ORDERS_DELETE => ['name' => 'Delete Orders', 'category' => self::CATEGORY_ORDERS, 'description' => 'Remove orders'],
            self::ORDERS_CANCEL => ['name' => 'Cancel Orders', 'category' => self::CATEGORY_ORDERS, 'description' => 'Cancel pending orders'],
            self::ORDERS_REFUND => ['name' => 'Process Refunds', 'category' => self::CATEGORY_ORDERS, 'description' => 'Issue order refunds'],
            self::ORDERS_FULFILL => ['name' => 'Fulfill Orders', 'category' => self::CATEGORY_ORDERS, 'description' => 'Mark orders as shipped/fulfilled'],
            self::ORDERS_COMPLETE => ['name' => 'Complete Orders', 'category' => self::CATEGORY_ORDERS, 'description' => 'Mark orders as completed'],
            self::ORDERS_EXPORT => ['name' => 'Export Orders', 'category' => self::CATEGORY_ORDERS, 'description' => 'Export order data'],
            self::ORDERS_MANAGE_SHIPPING => ['name' => 'Manage Shipping', 'category' => self::CATEGORY_ORDERS, 'description' => 'Create shipping labels and tracking'],
            self::ORDERS_VIEW_FINANCIALS => ['name' => 'View Order Financials', 'category' => self::CATEGORY_ORDERS, 'description' => 'View order costs and profits'],
            self::ORDERS_DELETE_CLOSED => ['name' => 'Closed Sale Deleted', 'category' => self::CATEGORY_ORDERS, 'description' => 'A completed/shipped sale was deleted'],

            // Transactions
            self::TRANSACTIONS_VIEW => ['name' => 'View Transactions', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'View buy transactions'],
            self::TRANSACTIONS_CREATE => ['name' => 'Create Transaction', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'Create a new buy transaction'],
            self::TRANSACTIONS_UPDATE => ['name' => 'Update Transaction', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'Update buy transaction details'],
            self::TRANSACTIONS_DELETE => ['name' => 'Delete Transaction', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'Delete a buy transaction'],
            self::TRANSACTIONS_SUBMIT_OFFER => ['name' => 'Submit Offer', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'Submit an offer on a transaction'],
            self::TRANSACTIONS_ACCEPT_OFFER => ['name' => 'Accept Offer', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'Accept a transaction offer'],
            self::TRANSACTIONS_DECLINE_OFFER => ['name' => 'Decline Offer', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'Decline a transaction offer'],
            self::TRANSACTIONS_PROCESS_PAYMENT => ['name' => 'Process Payment', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'Process payment for a transaction'],
            self::TRANSACTIONS_CANCEL => ['name' => 'Cancel Transaction', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'Cancel a buy transaction'],
            self::TRANSACTIONS_STATUS_CHANGE => ['name' => 'Change Status', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'Change transaction status'],
            self::TRANSACTIONS_UPDATE_PAYOUT_PREFERENCE => ['name' => 'Update Payout Preference', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'Customer updated payout preference'],
            self::TRANSACTIONS_DELETE_CLOSED => ['name' => 'Closed Transaction Deleted', 'category' => self::CATEGORY_TRANSACTIONS, 'description' => 'A completed/closed transaction was deleted'],

            // Inventory
            self::INVENTORY_VIEW => ['name' => 'View Inventory', 'category' => self::CATEGORY_INVENTORY, 'description' => 'View stock levels'],
            self::INVENTORY_ADJUST => ['name' => 'Adjust Inventory', 'category' => self::CATEGORY_INVENTORY, 'description' => 'Adjust stock quantities'],
            self::INVENTORY_TRANSFER => ['name' => 'Transfer Inventory', 'category' => self::CATEGORY_INVENTORY, 'description' => 'Transfer stock between locations'],
            self::INVENTORY_VIEW_REPORTS => ['name' => 'View Inventory Reports', 'category' => self::CATEGORY_INVENTORY, 'description' => 'View inventory analytics'],
            self::INVENTORY_QUANTITY_MANUAL_ADJUST => ['name' => 'Manual Quantity Adjustment', 'category' => self::CATEGORY_INVENTORY, 'description' => 'Inventory quantity was manually adjusted'],

            // Warehouses
            self::WAREHOUSES_VIEW => ['name' => 'View Warehouses', 'category' => self::CATEGORY_INVENTORY, 'description' => 'View warehouse locations'],
            self::WAREHOUSES_CREATE => ['name' => 'Create Warehouses', 'category' => self::CATEGORY_INVENTORY, 'description' => 'Add new warehouse locations'],
            self::WAREHOUSES_UPDATE => ['name' => 'Update Warehouses', 'category' => self::CATEGORY_INVENTORY, 'description' => 'Edit warehouse information'],
            self::WAREHOUSES_DELETE => ['name' => 'Delete Warehouses', 'category' => self::CATEGORY_INVENTORY, 'description' => 'Remove warehouse locations'],

            // Customers
            self::CUSTOMERS_VIEW => ['name' => 'View Customers', 'category' => self::CATEGORY_CUSTOMERS, 'description' => 'View customer listings'],
            self::CUSTOMERS_CREATE => ['name' => 'Create Customers', 'category' => self::CATEGORY_CUSTOMERS, 'description' => 'Add new customers'],
            self::CUSTOMERS_UPDATE => ['name' => 'Update Customers', 'category' => self::CATEGORY_CUSTOMERS, 'description' => 'Edit customer information'],
            self::CUSTOMERS_DELETE => ['name' => 'Delete Customers', 'category' => self::CATEGORY_CUSTOMERS, 'description' => 'Remove customers'],
            self::CUSTOMERS_EXPORT => ['name' => 'Export Customers', 'category' => self::CATEGORY_CUSTOMERS, 'description' => 'Export customer data'],
            self::CUSTOMERS_VIEW_HISTORY => ['name' => 'View Customer History', 'category' => self::CATEGORY_CUSTOMERS, 'description' => 'View customer order history'],
            self::CUSTOMERS_CHAT_LEAD => ['name' => 'Chat Lead Captured', 'category' => self::CATEGORY_CUSTOMERS, 'description' => 'A new lead was captured from the storefront chat'],

            // Integrations
            self::INTEGRATIONS_VIEW => ['name' => 'View Integrations', 'category' => self::CATEGORY_INTEGRATIONS, 'description' => 'View connected platforms'],
            self::INTEGRATIONS_CONNECT => ['name' => 'Connect Integrations', 'category' => self::CATEGORY_INTEGRATIONS, 'description' => 'Connect new platforms'],
            self::INTEGRATIONS_DISCONNECT => ['name' => 'Disconnect Integrations', 'category' => self::CATEGORY_INTEGRATIONS, 'description' => 'Remove platform connections'],
            self::INTEGRATIONS_SYNC => ['name' => 'Sync Integrations', 'category' => self::CATEGORY_INTEGRATIONS, 'description' => 'Sync data with platforms'],
            self::INTEGRATIONS_MANAGE_SETTINGS => ['name' => 'Manage Integration Settings', 'category' => self::CATEGORY_INTEGRATIONS, 'description' => 'Configure integration settings'],

            // Store
            self::STORE_VIEW_SETTINGS => ['name' => 'View Store Settings', 'category' => self::CATEGORY_STORE, 'description' => 'View store configuration'],
            self::STORE_UPDATE_SETTINGS => ['name' => 'Update Store Settings', 'category' => self::CATEGORY_STORE, 'description' => 'Modify store settings'],
            self::STORE_VIEW_BILLING => ['name' => 'View Billing', 'category' => self::CATEGORY_STORE, 'description' => 'View subscription and billing'],
            self::STORE_MANAGE_BILLING => ['name' => 'Manage Billing', 'category' => self::CATEGORY_STORE, 'description' => 'Manage subscription and payments'],
            self::STORE_MANAGE_STATUSES => ['name' => 'Manage Statuses', 'category' => self::CATEGORY_STORE, 'description' => 'Configure workflow statuses and automations'],

            // Team
            self::TEAM_VIEW => ['name' => 'View Team', 'category' => self::CATEGORY_TEAM, 'description' => 'View team members'],
            self::TEAM_INVITE => ['name' => 'Invite Team Members', 'category' => self::CATEGORY_TEAM, 'description' => 'Invite new team members'],
            self::TEAM_UPDATE => ['name' => 'Update Team Members', 'category' => self::CATEGORY_TEAM, 'description' => 'Edit team member roles'],
            self::TEAM_REMOVE => ['name' => 'Remove Team Members', 'category' => self::CATEGORY_TEAM, 'description' => 'Remove team members'],
            self::TEAM_ACCEPT_INVITATION => ['name' => 'Accept Invitation', 'category' => self::CATEGORY_TEAM, 'description' => 'Manually accept a team invitation'],
            self::TEAM_TRANSFER_OWNERSHIP => ['name' => 'Transfer Ownership', 'category' => self::CATEGORY_TEAM, 'description' => 'Transfer store ownership to another team member'],
            self::TEAM_MANAGE_ROLES => ['name' => 'Manage Roles', 'category' => self::CATEGORY_TEAM, 'description' => 'Create and edit roles'],

            // Roles
            self::ROLES_CREATE => ['name' => 'Create Role', 'category' => self::CATEGORY_TEAM, 'description' => 'A new role was created'],
            self::ROLES_UPDATE => ['name' => 'Update Role', 'category' => self::CATEGORY_TEAM, 'description' => 'A role was updated'],
            self::ROLES_DELETE => ['name' => 'Delete Role', 'category' => self::CATEGORY_TEAM, 'description' => 'A role was deleted'],

            // Reports
            self::REPORTS_VIEW => ['name' => 'View Reports', 'category' => self::CATEGORY_REPORTS, 'description' => 'View all reports and analytics'],
            self::REPORTS_EXPORT => ['name' => 'Export Reports', 'category' => self::CATEGORY_REPORTS, 'description' => 'Export report data'],

            // AI
            self::AI_GENERATE_DESCRIPTIONS => ['name' => 'Generate AI Descriptions', 'category' => self::CATEGORY_PRODUCTS, 'group' => 'ai', 'description' => 'Use AI to generate product descriptions'],
            self::AI_CATEGORIZE => ['name' => 'AI Categorization', 'category' => self::CATEGORY_PRODUCTS, 'group' => 'ai', 'description' => 'Use AI to categorize products'],
            self::AI_OPTIMIZE_PRICING => ['name' => 'AI Price Optimization', 'category' => self::CATEGORY_PRODUCTS, 'group' => 'ai', 'description' => 'Use AI for pricing suggestions'],

            // Memos
            self::MEMOS_VIEW => ['name' => 'View Memos', 'category' => self::CATEGORY_MEMOS, 'description' => 'View memo listings and details'],
            self::MEMOS_CREATE => ['name' => 'Create Memo', 'category' => self::CATEGORY_MEMOS, 'description' => 'Create a new consignment memo'],
            self::MEMOS_UPDATE => ['name' => 'Update Memo', 'category' => self::CATEGORY_MEMOS, 'description' => 'Update memo details'],
            self::MEMOS_DELETE => ['name' => 'Delete Memo', 'category' => self::CATEGORY_MEMOS, 'description' => 'Delete a memo'],
            self::MEMOS_SEND_TO_VENDOR => ['name' => 'Send to Vendor', 'category' => self::CATEGORY_MEMOS, 'description' => 'Send memo items to vendor'],
            self::MEMOS_MARK_RECEIVED => ['name' => 'Mark Received', 'category' => self::CATEGORY_MEMOS, 'description' => 'Mark memo as received by vendor'],
            self::MEMOS_PAYMENT_RECEIVED => ['name' => 'Payment Received', 'category' => self::CATEGORY_MEMOS, 'description' => 'Record payment received for memo'],
            self::MEMOS_CANCEL => ['name' => 'Cancel Memo', 'category' => self::CATEGORY_MEMOS, 'description' => 'Cancel a memo'],

            // Appraisals
            self::APPRAISALS_VIEW => ['name' => 'View Appraisals', 'category' => self::CATEGORY_APPRAISALS, 'description' => 'View appraisal listings and details'],
            self::APPRAISALS_CREATE => ['name' => 'Create Appraisal', 'category' => self::CATEGORY_APPRAISALS, 'description' => 'Create a new appraisal'],
            self::APPRAISALS_UPDATE => ['name' => 'Update Appraisal', 'category' => self::CATEGORY_APPRAISALS, 'description' => 'Update appraisal details'],
            self::APPRAISALS_DELETE => ['name' => 'Delete Appraisal', 'category' => self::CATEGORY_APPRAISALS, 'description' => 'Delete an appraisal'],
            self::APPRAISALS_COMPLETE => ['name' => 'Complete Appraisal', 'category' => self::CATEGORY_APPRAISALS, 'description' => 'Mark appraisal as completed'],
            self::APPRAISALS_PAYMENT_RECEIVED => ['name' => 'Payment Received', 'category' => self::CATEGORY_APPRAISALS, 'description' => 'Record payment received for appraisal'],
            self::APPRAISALS_CANCEL => ['name' => 'Cancel Appraisal', 'category' => self::CATEGORY_APPRAISALS, 'description' => 'Cancel an appraisal'],

            // Repairs
            self::REPAIRS_VIEW => ['name' => 'View Repairs', 'category' => self::CATEGORY_REPAIRS, 'description' => 'View repair listings and details'],
            self::REPAIRS_CREATE => ['name' => 'Create Repair', 'category' => self::CATEGORY_REPAIRS, 'description' => 'Create a new repair order'],
            self::REPAIRS_UPDATE => ['name' => 'Update Repair', 'category' => self::CATEGORY_REPAIRS, 'description' => 'Update repair details'],
            self::REPAIRS_DELETE => ['name' => 'Delete Repair', 'category' => self::CATEGORY_REPAIRS, 'description' => 'Delete a repair order'],
            self::REPAIRS_SEND_TO_VENDOR => ['name' => 'Send to Vendor', 'category' => self::CATEGORY_REPAIRS, 'description' => 'Send repair items to vendor'],
            self::REPAIRS_COMPLETE => ['name' => 'Complete Repair', 'category' => self::CATEGORY_REPAIRS, 'description' => 'Mark repair as completed'],
            self::REPAIRS_PAYMENT_RECEIVED => ['name' => 'Payment Received', 'category' => self::CATEGORY_REPAIRS, 'description' => 'Record payment received for repair'],
            self::REPAIRS_CANCEL => ['name' => 'Cancel Repair', 'category' => self::CATEGORY_REPAIRS, 'description' => 'Cancel a repair order'],

            // Layaways
            self::LAYAWAYS_VIEW => ['name' => 'View Layaways', 'category' => self::CATEGORY_LAYAWAYS, 'description' => 'View layaway listings and details'],
            self::LAYAWAYS_CREATE => ['name' => 'Create Layaway', 'category' => self::CATEGORY_LAYAWAYS, 'description' => 'Create a new layaway'],
            self::LAYAWAYS_UPDATE => ['name' => 'Update Layaway', 'category' => self::CATEGORY_LAYAWAYS, 'description' => 'Update layaway details'],
            self::LAYAWAYS_DELETE => ['name' => 'Delete Layaway', 'category' => self::CATEGORY_LAYAWAYS, 'description' => 'Delete a layaway'],
            self::LAYAWAYS_PAYMENT_RECEIVED => ['name' => 'Payment Received', 'category' => self::CATEGORY_LAYAWAYS, 'description' => 'Record payment received for layaway'],
            self::LAYAWAYS_PAYMENT_DUE_SOON => ['name' => 'Payment Due Soon', 'category' => self::CATEGORY_LAYAWAYS, 'description' => 'Layaway payment due reminder sent'],
            self::LAYAWAYS_PAYMENT_OVERDUE => ['name' => 'Payment Overdue', 'category' => self::CATEGORY_LAYAWAYS, 'description' => 'Layaway payment overdue notice sent'],
            self::LAYAWAYS_COMPLETED => ['name' => 'Layaway Completed', 'category' => self::CATEGORY_LAYAWAYS, 'description' => 'Layaway fully paid and completed'],
            self::LAYAWAYS_CANCELLED => ['name' => 'Layaway Cancelled', 'category' => self::CATEGORY_LAYAWAYS, 'description' => 'Layaway was cancelled'],

            // Vendors
            self::VENDORS_VIEW => ['name' => 'View Vendors', 'category' => self::CATEGORY_VENDORS, 'description' => 'View vendor listings and details'],
            self::VENDORS_CREATE => ['name' => 'Create Vendor', 'category' => self::CATEGORY_VENDORS, 'description' => 'Add a new vendor'],
            self::VENDORS_UPDATE => ['name' => 'Update Vendor', 'category' => self::CATEGORY_VENDORS, 'description' => 'Update vendor information'],
            self::VENDORS_DELETE => ['name' => 'Delete Vendor', 'category' => self::CATEGORY_VENDORS, 'description' => 'Remove a vendor'],

            // Notes (polymorphic - logged on the notable model)
            self::NOTES_CREATE => ['name' => 'Note Added', 'category' => self::CATEGORY_STORE, 'description' => 'A note was added'],
            self::NOTES_UPDATE => ['name' => 'Note Updated', 'category' => self::CATEGORY_STORE, 'description' => 'A note was updated'],
            self::NOTES_DELETE => ['name' => 'Note Deleted', 'category' => self::CATEGORY_STORE, 'description' => 'A note was deleted'],

            // Buckets
            self::BUCKETS_CREATE => ['name' => 'Bucket Created', 'category' => self::CATEGORY_BUCKETS, 'description' => 'A new bucket was created'],
            self::BUCKETS_UPDATE => ['name' => 'Bucket Updated', 'category' => self::CATEGORY_BUCKETS, 'description' => 'Bucket details were updated'],
            self::BUCKETS_DELETE => ['name' => 'Bucket Deleted', 'category' => self::CATEGORY_BUCKETS, 'description' => 'A bucket was deleted'],
            self::BUCKETS_ITEM_ADDED => ['name' => 'Item Added', 'category' => self::CATEGORY_BUCKETS, 'description' => 'An item was added to the bucket'],
            self::BUCKETS_ITEM_REMOVED => ['name' => 'Item Removed', 'category' => self::CATEGORY_BUCKETS, 'description' => 'An item was removed from the bucket'],
            self::BUCKETS_ITEM_SOLD => ['name' => 'Item Sold', 'category' => self::CATEGORY_BUCKETS, 'description' => 'An item was sold from the bucket'],

            // Listings
            self::LISTINGS_PUBLISH => ['name' => 'Listing Published', 'category' => self::CATEGORY_LISTINGS, 'description' => 'Product was published to a platform'],
            self::LISTINGS_UNLIST => ['name' => 'Listing Unlisted', 'category' => self::CATEGORY_LISTINGS, 'description' => 'Product was unlisted from a platform'],
            self::LISTINGS_RELIST => ['name' => 'Listing Relisted', 'category' => self::CATEGORY_LISTINGS, 'description' => 'Product was relisted on a platform'],
            self::LISTINGS_DELETE => ['name' => 'Listing Deleted', 'category' => self::CATEGORY_LISTINGS, 'description' => 'Listing was permanently deleted from a platform'],
            self::LISTINGS_SYNC => ['name' => 'Listing Synced', 'category' => self::CATEGORY_LISTINGS, 'description' => 'Listing was synced with platform'],
            self::LISTINGS_STATUS_CHANGE => ['name' => 'Status Changed', 'category' => self::CATEGORY_LISTINGS, 'description' => 'Listing status was changed'],
            self::LISTINGS_CREATED => ['name' => 'Listing Created', 'category' => self::CATEGORY_LISTINGS, 'description' => 'Listing was created for a product'],
            self::LISTINGS_UPDATE => ['name' => 'Listing Updated', 'category' => self::CATEGORY_LISTINGS, 'description' => 'Listing details were updated'],
        ];
    }

    /**
     * Get all activity slugs.
     */
    public static function getAllSlugs(): array
    {
        return array_keys(self::getDefinitions());
    }

    /**
     * Get activities grouped by category.
     */
    public static function getGroupedByCategory(): Collection
    {
        return collect(self::getDefinitions())->groupBy('category');
    }

    /**
     * Get activities for a specific category.
     */
    public static function getByCategory(string $category): array
    {
        return collect(self::getDefinitions())
            ->filter(fn ($def) => $def['category'] === $category)
            ->keys()
            ->toArray();
    }

    /**
     * Get permission groups for quick-select functionality.
     * Groups bundle related categories together for common access patterns.
     */
    public static function getPermissionGroups(): array
    {
        return [
            'sales' => [
                'name' => 'Sales',
                'description' => 'Orders, layaways, repairs, appraisals, and customer management',
                'categories' => [
                    self::CATEGORY_ORDERS,
                    self::CATEGORY_LAYAWAYS,
                    self::CATEGORY_REPAIRS,
                    self::CATEGORY_APPRAISALS,
                    self::CATEGORY_CUSTOMERS,
                ],
            ],
            'inventory' => [
                'name' => 'Inventory',
                'description' => 'Products and stock management',
                'categories' => [
                    self::CATEGORY_PRODUCTS,
                    self::CATEGORY_INVENTORY,
                ],
            ],
            'purchasing' => [
                'name' => 'Purchasing',
                'description' => 'Buy transactions, vendors, and consignment memos',
                'categories' => [
                    self::CATEGORY_TRANSACTIONS,
                    self::CATEGORY_VENDORS,
                    self::CATEGORY_MEMOS,
                ],
            ],
            'reports' => [
                'name' => 'Reports',
                'description' => 'Sales and inventory analytics',
                'categories' => [
                    self::CATEGORY_REPORTS,
                ],
            ],
            'administration' => [
                'name' => 'Administration',
                'description' => 'Team, store settings, and integrations',
                'categories' => [
                    self::CATEGORY_TEAM,
                    self::CATEGORY_STORE,
                    self::CATEGORY_INTEGRATIONS,
                ],
            ],
        ];
    }

    /**
     * Get display names for all permission categories.
     */
    public static function getCategoryDisplayNames(): array
    {
        return [
            self::CATEGORY_PRODUCTS => 'Products',
            self::CATEGORY_ORDERS => 'Orders',
            self::CATEGORY_TRANSACTIONS => 'Buy Transactions',
            self::CATEGORY_INVENTORY => 'Inventory',
            self::CATEGORY_CUSTOMERS => 'Customers',
            self::CATEGORY_INTEGRATIONS => 'Integrations',
            self::CATEGORY_STORE => 'Store Settings',
            self::CATEGORY_TEAM => 'Team',
            self::CATEGORY_REPORTS => 'Reports',
            self::CATEGORY_MEMOS => 'Consignment Memos',
            self::CATEGORY_REPAIRS => 'Repairs',
            self::CATEGORY_APPRAISALS => 'Appraisals',
            self::CATEGORY_VENDORS => 'Vendors',
            self::CATEGORY_BUCKETS => 'Buckets',
            self::CATEGORY_LAYAWAYS => 'Layaways',
            self::CATEGORY_LISTINGS => 'Platform Listings',
        ];
    }

    /**
     * Get role presets for common roles.
     */
    public static function getRolePresets(): array
    {
        return [
            'owner' => [
                'name' => 'Owner',
                'slug' => 'owner',
                'description' => 'Full access to all features',
                'permissions' => ['*'], // All permissions
            ],
            'admin' => [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Full access except billing and team management',
                'permissions' => array_values(array_filter(self::getAllSlugs(), fn ($slug) => ! str_starts_with($slug, 'store.') && ! str_starts_with($slug, 'team.manage'))),
            ],
            'manager' => [
                'name' => 'Manager',
                'slug' => 'manager',
                'description' => 'Manage products, orders, and inventory',
                'permissions' => array_merge(
                    self::getByCategory(self::CATEGORY_PRODUCTS),
                    self::getByCategory(self::CATEGORY_ORDERS),
                    self::getByCategory(self::CATEGORY_INVENTORY),
                    self::getByCategory(self::CATEGORY_CUSTOMERS),
                    [self::TEAM_VIEW, self::INTEGRATIONS_VIEW, self::INTEGRATIONS_SYNC],
                ),
            ],
            'staff' => [
                'name' => 'Staff',
                'slug' => 'staff',
                'description' => 'Basic product and order management',
                'permissions' => [
                    self::PRODUCTS_VIEW, self::PRODUCTS_UPDATE, self::PRODUCTS_MANAGE_IMAGES,
                    self::CATEGORIES_VIEW,
                    self::ORDERS_VIEW, self::ORDERS_UPDATE, self::ORDERS_FULFILL,
                    self::INVENTORY_VIEW, self::INVENTORY_ADJUST,
                    self::CUSTOMERS_VIEW, self::CUSTOMERS_VIEW_HISTORY,
                ],
            ],
            'viewer' => [
                'name' => 'Viewer',
                'slug' => 'viewer',
                'description' => 'Read-only access',
                'permissions' => [
                    self::PRODUCTS_VIEW, self::CATEGORIES_VIEW, self::TEMPLATES_VIEW,
                    self::ORDERS_VIEW,
                    self::INVENTORY_VIEW, self::WAREHOUSES_VIEW,
                    self::CUSTOMERS_VIEW,
                    self::INTEGRATIONS_VIEW,
                ],
            ],
        ];
    }
}
