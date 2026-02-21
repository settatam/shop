<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Store Editions
    |--------------------------------------------------------------------------
    |
    | Define available editions and their features. Each store has an edition
    | that determines which features and navigation items are available.
    |
    */

    'default' => 'shopmata-public',

    'editions' => [
        // Shopmata Public - Standard inventory management system for new stores
        'shopmata-public' => [
            'name' => 'Shopmata',
            'description' => 'Standard inventory management system for e-commerce and retail',
            'logo' => 'https://fashionerize.nyc3.cdn.digitaloceanspaces.com/shopmata/logo.svg',
            'features' => [
                // Core features
                'dashboard',
                'customers',
                'products',
                'categories',
                'product_types',
                'templates',
                'orders',
                'shipments',
                'returns',
                'vendors',
                'invoices',
                'payments',
                'labels',
                'reports',
                'tags',
                'integrations',
                'settings',
                'marketplaces',

                // Standard inventory features
                'multi_warehouse',
                'inventory_tracking',
            ],
        ],

        'standard' => [
            'name' => 'Standard',
            'description' => 'Standard retail edition with all features enabled',
            'features' => [
                // UI Features
                'dashboard',
                'customers',
                'leads',
                'products',
                'gia', // GIA diamond lookup
                'categories',
                'product_types',
                'templates',
                'orders',
                'layaways',
                'shipments',
                'returns',
                'transactions',
                'buys',
                'vendors',
                'repairs', // Item repairs
                'memos', // Memo tracking
                'invoices',
                'payments',
                'labels',
                'buckets', // Bucket grouping
                'reports',
                'tags',
                'integrations',
                'settings',
                'quickbooks',
                'xoom',
                'agents',
                'marketplaces',

                // Additional features
                'product_status_in_memo',
                'product_status_in_repair',
                'product_status_in_bucket',
                'product_status_awaiting_confirmation',
                'single_item_inventory',
            ],
        ],

        'pawn_shop' => [
            'name' => 'Pawn Shop / Estate Buyers',
            'description' => 'For pawn shops, estate buyers, and consignment stores dealing with unique items',
            'features' => [
                // UI Features
                'dashboard',
                'customers',
                'leads',
                'products',
                'gia', // GIA diamond lookup
                'categories',
                'product_types',
                'templates',
                'orders',
                'layaways',
                'shipments',
                'returns',
                'transactions',
                'all_transactions', // All Transactions view
                'buys',
                'vendors',
                'repairs', // Item repairs
                'memos', // Memo tracking
                'invoices',
                'payments',
                'labels',
                'buckets', // Bucket grouping
                'reports',
                'tags',
                'integrations',
                'settings',
                'quickbooks',
                'xoom',
                'agents',
                'marketplaces',

                // Pawn shop specific features
                'product_status_in_memo', // Track items out on memo
                'product_status_in_repair', // Track items in repair
                'product_status_in_bucket', // Track items in buckets
                'product_status_awaiting_confirmation', // Pending buys
                'single_item_inventory', // One-of-a-kind items
            ],
        ],

        // Legacy edition for stores migrated from Evotally (stores 43, 44, etc.)
        // Includes transaction reports and trade-in buys reports
        'legacy' => [
            'name' => 'Legacy (Evotally)',
            'description' => 'Edition for stores migrated from Evotally with transaction reports',
            'features' => [
                // UI Features
                'dashboard',
                'customers',
                'leads',
                'products',
                'gia',
                'categories',
                'product_types',
                'templates',
                'orders',
                'layaways',
                'shipments',
                'returns',
                'transactions',
                'all_transactions', // All Transactions view
                'buys',
                'vendors',
                'repairs',
                'memos',
                'invoices',
                'payments',
                'labels',
                'buckets',
                'reports',
                'tags',
                'integrations',
                'settings',
                'quickbooks',
                'xoom',
                'agents',
                'marketplaces',

                // Legacy-specific features
                'transactions_reports', // Transaction daily/weekly/monthly/yearly reports
                'buys_trade_in', // Trade-in buys reports
                'product_status_in_memo',
                'product_status_in_repair',
                'product_status_in_bucket',
                'product_status_awaiting_confirmation',
                'single_item_inventory',
            ],
        ],

        // Example client-specific edition
        // Customize this for your client's needs
        'client_x' => [
            'name' => 'Client X Edition',
            'description' => 'Custom edition for Client X',
            'features' => [
                // UI Features
                'prominent_store_switcher', // Multi-store client needs prominent switcher

                // Navigation features
                'dashboard',
                'customers',
                // 'leads', // Not needed for this client
                'products',
                // 'gia', // Not needed
                'categories',
                'product_types',
                'templates',
                'orders',
                // 'layaways', // Not needed
                'shipments',
                'returns',
                'transactions',
                'buys',
                'vendors',
                'repairs',
                'memos',
                'invoices',
                'payments',
                'labels',
                // 'buckets', // Not needed
                'reports',
                'tags',
                'integrations',
                'settings',
                'quickbooks', // Shared feature
                'xoom', // Shared feature
                // 'agents', // Not needed
                // 'marketplaces', // Not needed

                // Client-specific features
                'custom_workflow',
                'bulk_operations',
            ],

            // Field requirements for this edition
            // These override the default field rules
            'field_requirements' => [
                'products' => [
                    'vendor_id' => [
                        'required' => true,
                        'label' => 'Vendor',
                        'message' => 'Please select a vendor before saving.',
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Field Requirements
    |--------------------------------------------------------------------------
    |
    | Default field requirements that apply to all editions unless overridden.
    |
    */

    'default_field_requirements' => [
        'products' => [
            'title' => [
                'required' => true,
                'label' => 'Title',
            ],
            'category_id' => [
                'required' => false,
                'label' => 'Category',
            ],
            'vendor_id' => [
                'required' => false,
                'label' => 'Vendor',
            ],
            'brand_id' => [
                'required' => false,
                'label' => 'Brand',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation Feature Mapping
    |--------------------------------------------------------------------------
    |
    | Map navigation items to their required features. If a nav item requires
    | a feature the store doesn't have, it won't be shown.
    |
    */

    'navigation' => [
        'dashboard' => 'dashboard',
        'customers' => 'customers',
        'leads' => 'leads',
        'products' => 'products',
        'gia' => 'gia',
        'categories' => 'categories',
        'product_types' => 'product_types',
        'templates' => 'templates',
        'orders' => 'orders',
        'layaways' => 'layaways',
        'shipments' => 'shipments',
        'returns' => 'returns',
        'transactions' => 'transactions',
        'buys' => 'buys',
        'vendors' => 'vendors',
        'repairs' => 'repairs',
        'memos' => 'memos',
        'invoices' => 'invoices',
        'payments' => 'payments',
        'labels' => 'labels',
        'buckets' => 'buckets',
        'reports' => 'reports',
        'tags' => 'tags',
        'integrations' => 'integrations',
        'settings' => 'settings',
    ],
];
