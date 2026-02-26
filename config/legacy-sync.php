<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection name to use for legacy data access.
    |
    */

    'connection' => env('LEGACY_SYNC_CONNECTION', 'legacy'),

    /*
    |--------------------------------------------------------------------------
    | Sync Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable legacy sync operations.
    |
    */

    'enabled' => env('LEGACY_SYNC_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Schedule Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the schedule for legacy data sync and reports.
    |
    */

    'schedule' => [
        'timezone' => env('LEGACY_SYNC_TIMEZONE', 'America/New_York'),
        'clear_and_reload_at' => env('LEGACY_SYNC_RELOAD_TIME', '20:00'),
        'send_reports_at' => env('LEGACY_SYNC_REPORTS_TIME', '00:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for legacy daily reports.
    | Report recipients are loaded from the legacy store_notifications table.
    |
    */

    'reports' => [
        'enabled' => env('LEGACY_REPORTS_ENABLED', false),
        'types' => [
            'daily_sales' => 'Daily Sales Report',
            'daily_buy' => 'Daily Buy Report',
            'daily_memos' => 'Daily Memos Report',
            'daily_repairs' => 'Daily Repairs Report',
        ],
    ],

];
