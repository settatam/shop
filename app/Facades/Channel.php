<?php

namespace App\Facades;

use App\Services\Platforms\ChannelService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \App\Services\Platforms\ListingManager listing(int|\App\Models\PlatformListing $listing)
 * @method static \App\Services\Platforms\Adapters\BaseAdapter platform(int|\App\Models\SalesChannel $channel)
 * @method static \Illuminate\Database\Eloquent\Collection listingsFor(int|\App\Models\Product $product)
 * @method static array publishToAll(int|\App\Models\Product $product)
 * @method static array syncInventoryForAll(int|\App\Models\Product $product)
 * @method static \Illuminate\Database\Eloquent\Collection ensureListings(int|\App\Models\Product $product)
 *
 * @see \App\Services\Platforms\ChannelService
 */
class Channel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ChannelService::class;
    }
}
