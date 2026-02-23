<?php

namespace App\Notifications;

use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InventoryOversoldNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Store $store,
        public ProductVariant $variant,
        public int $requested,
        public int $unfulfilled,
        public string $platform,
        public ?string $orderNumber = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $productName = $this->variant->product?->title ?? 'Unknown Product';

        return (new MailMessage)
            ->subject("Inventory Alert: Oversold on {$this->platform}")
            ->greeting("Inventory Alert for {$this->store->name}")
            ->line("An order from **{$this->platform}** has caused an oversell situation.")
            ->line("**Product:** {$productName}")
            ->line("**SKU:** {$this->variant->sku}")
            ->line("**Ordered:** {$this->requested} units")
            ->line("**Short by:** {$this->unfulfilled} units")
            ->when($this->orderNumber, fn ($mail) => $mail->line("**Order #:** {$this->orderNumber}"))
            ->line('Please review your inventory and restock if necessary.')
            ->action('View Product', url("/products/{$this->variant->product_id}"))
            ->salutation('Shopmata Inventory System');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inventory_oversold',
            'store_id' => $this->store->id,
            'variant_id' => $this->variant->id,
            'sku' => $this->variant->sku,
            'product_id' => $this->variant->product_id,
            'product_name' => $this->variant->product?->title,
            'platform' => $this->platform,
            'requested' => $this->requested,
            'unfulfilled' => $this->unfulfilled,
            'order_number' => $this->orderNumber,
            'message' => "Oversold {$this->variant->sku} by {$this->unfulfilled} units on {$this->platform}",
        ];
    }
}
