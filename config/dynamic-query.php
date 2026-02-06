<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed Tables
    |--------------------------------------------------------------------------
    |
    | Tables that can be queried through the dynamic query system. Only these
    | tables will be included in the schema provided to the LLM and queries
    | attempting to access other tables will be rejected.
    |
    */

    'allowed_tables' => [
        'orders',
        'order_items',
        'products',
        'product_variants',
        'customers',
        'payments',
        'transactions',
        'transaction_items',
        'categories',
        'brands',
        'invoices',
        'layaways',
        'layaway_schedules',
        'repairs',
        'memos',
        'vendors',
        'purchase_orders',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked Columns
    |--------------------------------------------------------------------------
    |
    | Columns that should never be included in schema or query results,
    | typically for security reasons.
    |
    */

    'blocked_columns' => [
        'password',
        'remember_token',
        'api_token',
        'secret',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution Limits
    |--------------------------------------------------------------------------
    |
    | Safety limits to prevent runaway queries from consuming too many
    | resources or returning too much data.
    |
    */

    'limits' => [
        'max_rows' => 1000,
        'query_timeout' => 10,  // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Caching configuration for schema data to avoid rebuilding on every
    | request.
    |
    */

    'cache' => [
        'schema_ttl' => 3600,  // 1 hour in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection to use for query execution. Using a read-only
    | connection is recommended for production environments.
    |
    */

    'connection' => env('DYNAMIC_QUERY_CONNECTION', null),  // null = default connection

];
