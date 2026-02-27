<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SalesChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateSalesChannelListingsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600; // 10 minutes

    public function __construct(
        public SalesChannel $channel,
    ) {}

    public function handle(): void
    {
        $products = Product::where('store_id', $this->channel->store_id)->get();

        $total = $products->count();
        $created = 0;

        Log::info("CreateSalesChannelListingsJob: Creating listings for {$total} products on channel {$this->channel->id}");

        foreach ($products as $product) {
            $product->ensureListingExists($this->channel);
            $created++;
        }

        Log::info("CreateSalesChannelListingsJob: Completed — {$created}/{$total} listings created on channel {$this->channel->id}");
    }
}
