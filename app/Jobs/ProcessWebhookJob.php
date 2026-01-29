<?php

namespace App\Jobs;

use App\Models\WebhookLog;
use App\Services\Webhooks\OrderImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessWebhookJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public WebhookLog $webhookLog
    ) {}

    public function handle(OrderImportService $orderImportService): void
    {
        $this->webhookLog->markAsProcessing();

        try {
            $eventType = strtolower($this->webhookLog->event_type);

            if ($this->isOrderEvent($eventType)) {
                $this->processOrderWebhook($orderImportService);
            } else {
                $this->webhookLog->markAsSkipped("Unhandled event type: {$eventType}");

                return;
            }

            $this->webhookLog->markAsCompleted([
                'processed_event' => $eventType,
                'order_created' => true,
            ]);
        } catch (Throwable $e) {
            $this->webhookLog->markAsFailed($e->getMessage());

            if ($this->webhookLog->canRetry()) {
                throw $e;
            }
        }
    }

    protected function isOrderEvent(string $eventType): bool
    {
        $orderEvents = [
            'orders/create',
            'orders/updated',
            'orders/paid',
            'orders/fulfilled',
            'orders/cancelled',
            'order.created',
            'order.updated',
            'order.paid',
            'order.completed',
            'order_change',
            'receipt.created',
            'woocommerce_order_created',
            'woocommerce_order_updated',
        ];

        foreach ($orderEvents as $event) {
            if (str_contains($eventType, $event) || str_contains($event, $eventType)) {
                return true;
            }
        }

        return str_contains($eventType, 'order');
    }

    protected function processOrderWebhook(OrderImportService $orderImportService): void
    {
        $marketplace = $this->webhookLog->marketplace;

        if (! $marketplace) {
            throw new \RuntimeException('No store marketplace found for webhook');
        }

        $payload = $this->webhookLog->payload;
        $platform = $this->webhookLog->platform;

        $order = $orderImportService->importFromWebhookPayload(
            $payload,
            $marketplace,
            $platform
        );

        $this->webhookLog->update([
            'external_id' => $order->external_marketplace_id,
            'response' => [
                'order_id' => $order->id,
                'status' => $order->status,
            ],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->webhookLog->update([
            'status' => WebhookLog::STATUS_FAILED,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
