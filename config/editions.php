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

    'default' => 'standard',

    'editions' => [
        'standard' => [
            'name' => 'Standard',
            'description' => 'Default edition with all standard features',
            'features' => [
                // UI Features
                // 'prominent_store_switcher', // Not needed for single-store users
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
