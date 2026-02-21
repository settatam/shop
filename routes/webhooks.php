<?php

use App\Http\Controllers\Webhooks\AmazonWebhookController;
use App\Http\Controllers\Webhooks\BigCommerceWebhookController;
use App\Http\Controllers\Webhooks\EbayWebhookController;
use App\Http\Controllers\Webhooks\EtsyWebhookController;
use App\Http\Controllers\Webhooks\PaperformWebhookController;
use App\Http\Controllers\Webhooks\ShopifyWebhookController;
use App\Http\Controllers\Webhooks\TwilioWebhookController;
use App\Http\Controllers\Webhooks\WalmartWebhookController;
use App\Http\Controllers\Webhooks\WooCommerceWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhooks from various e-commerce platforms.
| Webhooks are used to receive real-time notifications about orders,
| inventory changes, and other events from connected sales channels.
|
*/

Route::prefix('webhooks')->withoutMiddleware(['web', 'csrf'])->group(function () {
    // Shopify webhooks
    Route::post('shopify/{connectionId}', [ShopifyWebhookController::class, 'handle'])
        ->name('webhooks.shopify');

    // eBay webhooks
    Route::post('ebay/{connectionId}', [EbayWebhookController::class, 'handle'])
        ->name('webhooks.ebay');

    // Amazon webhooks
    Route::post('amazon/{connectionId}', [AmazonWebhookController::class, 'handle'])
        ->name('webhooks.amazon');

    // Etsy webhooks
    Route::post('etsy/{connectionId}', [EtsyWebhookController::class, 'handle'])
        ->name('webhooks.etsy');

    // Walmart webhooks
    Route::post('walmart/{connectionId}', [WalmartWebhookController::class, 'handle'])
        ->name('webhooks.walmart');

    // WooCommerce webhooks
    Route::post('woocommerce/{connectionId}', [WooCommerceWebhookController::class, 'handle'])
        ->name('webhooks.woocommerce');

    // BigCommerce webhooks
    Route::post('bigcommerce/{connectionId}', [BigCommerceWebhookController::class, 'handle'])
        ->name('webhooks.bigcommerce');

    // Paperform webhooks
    Route::post('paperform', [PaperformWebhookController::class, 'handle'])
        ->name('webhooks.paperform');

    // Twilio webhooks
    Route::post('twilio/sms', [TwilioWebhookController::class, 'handleIncomingSms'])
        ->name('webhooks.twilio.sms');
    Route::post('twilio/status', [TwilioWebhookController::class, 'handleStatusCallback'])
        ->name('webhooks.twilio.status');
});
