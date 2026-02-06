<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'textract' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_TEXTRACT_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
    ],

    'fedex' => [
        'client_id' => env('FEDEX_CLIENT_ID'),
        'client_secret' => env('FEDEX_CLIENT_SECRET'),
        'account_number' => env('FEDEX_ACCOUNT_NUMBER'),
        'mode' => env('FEDEX_MODE', 'sandbox'),

        // Available service types
        'service_types' => [
            'FEDEX_GROUND' => 'FedEx Ground',
            'FEDEX_HOME_DELIVERY' => 'FedEx Home Delivery',
            'FEDEX_EXPRESS_SAVER' => 'FedEx Express Saver',
            'FEDEX_2_DAY' => 'FedEx 2Day',
            'FEDEX_2_DAY_AM' => 'FedEx 2Day AM',
            'STANDARD_OVERNIGHT' => 'Standard Overnight',
            'PRIORITY_OVERNIGHT' => 'Priority Overnight',
            'FIRST_OVERNIGHT' => 'First Overnight',
        ],

        // Available packaging types
        'packaging_types' => [
            'YOUR_PACKAGING' => 'Your Packaging',
            'FEDEX_ENVELOPE' => 'FedEx Envelope',
            'FEDEX_PAK' => 'FedEx Pak',
            'FEDEX_BOX' => 'FedEx Box',
            'FEDEX_SMALL_BOX' => 'FedEx Small Box',
            'FEDEX_MEDIUM_BOX' => 'FedEx Medium Box',
            'FEDEX_LARGE_BOX' => 'FedEx Large Box',
            'FEDEX_EXTRA_LARGE_BOX' => 'FedEx Extra Large Box',
            'FEDEX_TUBE' => 'FedEx Tube',
        ],

        // Default package dimensions
        'default_package' => [
            'weight' => 1,
            'length' => 12,
            'width' => 12,
            'height' => 6,
        ],

        // Default service type
        'default_service_type' => env('FEDEX_DEFAULT_SERVICE_TYPE', 'FEDEX_GROUND'),

        // Default packaging type
        'default_packaging_type' => env('FEDEX_DEFAULT_PACKAGING_TYPE', 'YOUR_PACKAGING'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_PHONE_NUMBER'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'whisper_model' => env('OPENAI_WHISPER_MODEL', 'whisper-1'),
        'tts_model' => env('OPENAI_TTS_MODEL', 'tts-1'),
        'tts_voice' => env('OPENAI_TTS_VOICE', 'alloy'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
    ],

];
